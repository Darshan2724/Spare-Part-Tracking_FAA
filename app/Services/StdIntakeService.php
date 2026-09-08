<?php

namespace App\Services;

use App\Models\BomItem;
use App\Models\BomRequirement;
use App\Models\Project;
use App\Models\Receipt;
use App\Models\ReceiptItem;
use App\Models\QcInspection;
use App\Models\ReworkRecord;
use App\Models\PaintRecord;
use App\Models\AssemblyRecord;
use App\Models\WorkflowEvent;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class StdIntakeService
{
    /**
     * Allowed STD transitions.
     * STD strictly follows: Pending -> Store -> QC -> Rework/Paint/Assembly -> Completed
     */
    public const ALLOWED_TRANSITIONS = [
        'pending' => ['store'],
        'store' => ['qc'],
        'qc' => ['rework', 'paint', 'assembly'],
        'rework' => ['qc', 'paint', 'assembly'],
        'paint' => ['assembly'],
        'assembly' => ['completed'],
    ];

    /**
     * Get aggregated STD parts across all projects (or filtered project).
     *
     * @param array $filters ['project_id', 'search', 'status']
     * @param int $page
     * @param int $perPage
     * @return array
     */
    public function getAggregatedStdParts(array $filters = [], int $page = 1, int $perPage = 50): array
    {
        $projectId = !empty($filters['project_id']) ? (int)$filters['project_id'] : null;
        $search = !empty($filters['search']) ? trim($filters['search']) : null;
        $statusFilter = !empty($filters['status']) ? strtolower(trim($filters['status'])) : 'all';

        // Query all STD items with requirements, project, and supplier
        $query = BomItem::query()
            ->with(['requirements', 'project', 'supplier'])
            ->where('part_type', 'STD');

        if ($projectId) {
            $query->where('project_id', $projectId);
        }

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('standard_part_no', 'ILIKE', "%{$search}%")
                  ->orWhere('item_no', 'ILIKE', "%{$search}%")
                  ->orWhere('jig_no', 'ILIKE', "%{$search}%")
                  ->orWhere('unit_no', 'ILIKE', "%{$search}%")
                  ->orWhere('remarks', 'ILIKE', "%{$search}%")
                  ->orWhere('supplier_name_raw', 'ILIKE', "%{$search}%")
                  ->orWhereHas('project', fn($pq) => $pq->where('name', 'ILIKE', "%{$search}%")->orWhere('project_code', 'ILIKE', "%{$search}%"))
                  ->orWhereHas('supplier', fn($sq) => $sq->where('name', 'ILIKE', "%{$search}%"));
            });
        }

        $allStdItems = $query->orderBy('standard_part_no')->get();
        $bomItemIds = $allStdItems->pluck('id')->toArray();

        // Bulk load all operational records
        $receipts = ReceiptItem::query()
            ->whereIn('bom_item_id', $bomItemIds)
            ->whereIn('status', QuantityCalculationService::VALID_RECEIPT_STATUSES)
            ->get()
            ->groupBy('bom_item_id');

        $qcInspections = QcInspection::query()
            ->whereIn('bom_item_id', $bomItemIds)
            ->get()
            ->groupBy('bom_item_id');

        $reworkRecords = ReworkRecord::query()
            ->whereIn('bom_item_id', $bomItemIds)
            ->get()
            ->groupBy('bom_item_id');

        $paintRecords = PaintRecord::query()
            ->whereIn('bom_item_id', $bomItemIds)
            ->get()
            ->groupBy('bom_item_id');

        $assemblyRecords = AssemblyRecord::query()
            ->whereIn('bom_item_id', $bomItemIds)
            ->where('status', 'completed')
            ->get()
            ->groupBy('bom_item_id');

        // Aggregate by standard_part_no
        $grouped = $allStdItems->groupBy('standard_part_no');
        $aggregatedRows = collect();

        $summaryTotals = [
            'total_parts' => 0,
            'total_received' => 0,
            'parts_pending' => 0,
            'parts_in_store' => 0,
            'parts_in_qc' => 0,
            'parts_in_rework' => 0,
            'parts_in_paint' => 0,
            'parts_in_assembly' => 0,
            'assembly_completed' => 0,
        ];

        foreach ($grouped as $partNo => $items) {
            $totalRequired = 0;
            $totalReceived = 0;
            $partsInStore = 0;
            $partsInQc = 0;
            $partsInRework = 0;
            $partsInPaint = 0;
            $partsInAssembly = 0;
            $assemblyCompleted = 0;

            $unitSet = [];
            $projectSet = [];
            $supplierNames = [];

            foreach ($items as $item) {
                $itemRecs = $receipts->get($item->id, collect());
                $itemQc = $qcInspections->get($item->id, collect());
                $itemRew = $reworkRecords->get($item->id, collect());
                $itemPnt = $paintRecords->get($item->id, collect());
                $itemAsm = $assemblyRecords->get($item->id, collect());

                if ($item->unit_no) {
                    $unitSet[] = $item->unit_no;
                }
                if ($item->project) {
                    $projectSet[$item->project->id] = $item->project->project_code ?: $item->project->name;
                }
                if ($item->supplier) {
                    $supplierNames[] = $item->supplier->name;
                } elseif ($item->supplier_name_raw) {
                    $supplierNames[] = $item->supplier_name_raw;
                }

                foreach ($item->requirements as $req) {
                    $side = $req->side;
                    $reqQty = (int)$req->required_quantity;
                    $recForSide = $itemRecs->where('side', $side);
                    $qcForSide = $itemQc->where('side', $side);
                    $rewForSide = $itemRew->where('side', $side);
                    $pntForSide = $itemPnt->where('side', $side);
                    $asmForSide = $itemAsm->where('side', $side);

                    $rawRecQty = (int)$recForSide->sum('received_quantity');
                    $effectiveRec = min($rawRecQty, $reqQty);

                    // QC Stats
                    $qcAppPaint = (int)$qcForSide->filter(fn($q) => $q->approved_quantity > 0 && ($q->destination === 'PAINT' || empty($q->destination)))->sum('approved_quantity');
                    $qcAppDirect = (int)$qcForSide->filter(fn($q) => $q->approved_quantity > 0 && $q->destination === 'ASSEMBLY')->sum('approved_quantity');
                    $qcApp = $qcAppPaint + $qcAppDirect;
                    $qcRej = (int)$qcForSide->sum('rejected_quantity');
                    $qcRew = (int)$qcForSide->sum('rework_quantity');

                    // Rework
                    $rewComp = (int)$rewForSide->whereIn('status', ['completed', 'returned_to_qc'])->sum('quantity');
                    $rewActive = max(0, $qcRew - $rewComp);

                    // Paint
                    $paintComp = (int)$pntForSide->whereIn('status', ['completed', 'assembled'])->sum('quantity');
                    $paintActive = max(0, $qcAppPaint - $paintComp);

                    // Assembly
                    $asmComp = (int)$asmForSide->sum('quantity');
                    $asmReached = $paintComp + $qcAppDirect;
                    $directAsmReady = (int)$recForSide->where('status', 'in_assembly')->sum('received_quantity');
                    $asmReady = max(0, $asmReached - $asmComp) + $directAsmReady;

                    // QC Dispatched & Residencies
                    $qcDispatched = (int)$recForSide->whereNotIn('status', ['received', 'returned_to_store', 'in_assembly', 'assembly_completed'])->sum('received_quantity');
                    $qcTotalAccounted = $qcApp + $qcRej + $qcRew;
                    $sentToQc = min($effectiveRec, max($qcDispatched, $qcTotalAccounted));

                    $qcResident = max(0, $sentToQc + $rewComp - ($qcApp + $qcRej + $qcRew));
                    $storeResident = max(0, $effectiveRec - ($qcResident + $qcRej + $rewActive + $paintActive + $asmReady + $asmComp));

                    $totalRequired += $reqQty;
                    $totalReceived += $effectiveRec;
                    $partsInStore += $storeResident;
                    $partsInQc += $qcResident;
                    $partsInRework += $rewActive;
                    $partsInPaint += $paintActive;
                    $partsInAssembly += $asmReady;
                    $assemblyCompleted += $asmComp;
                }
            }

            $pending = max(0, $totalRequired - $totalReceived);

            // Determine primary status badge
            if ($assemblyCompleted >= $totalRequired && $totalRequired > 0) {
                $status = 'Completed';
                $statusColor = 'success';
            } elseif ($partsInAssembly > 0) {
                $status = 'Assembly';
                $statusColor = 'pink';
            } elseif ($partsInPaint > 0) {
                $status = 'Paint';
                $statusColor = 'purple';
            } elseif ($partsInRework > 0) {
                $status = 'Rework';
                $statusColor = 'warning';
            } elseif ($partsInQc > 0) {
                $status = 'QC';
                $statusColor = 'info';
            } elseif ($partsInStore > 0) {
                $status = 'Store';
                $statusColor = 'warning';
            } else {
                $status = 'Pending';
                $statusColor = 'secondary';
            }

            // Apply status filter if set
            if ($statusFilter !== 'all') {
                if ($statusFilter === 'completed' && $status !== 'Completed') continue;
                if ($statusFilter === 'assembly' && $partsInAssembly <= 0) continue;
                if ($statusFilter === 'paint' && $partsInPaint <= 0) continue;
                if ($statusFilter === 'rework' && $partsInRework <= 0) continue;
                if ($statusFilter === 'qc' && $partsInQc <= 0) continue;
                if ($statusFilter === 'store' && $partsInStore <= 0) continue;
                if ($statusFilter === 'pending' && $pending <= 0) continue;
            }

            // Accumulate summary totals
            $summaryTotals['total_parts'] += $totalRequired;
            $summaryTotals['total_received'] += $totalReceived;
            $summaryTotals['parts_pending'] += $pending;
            $summaryTotals['parts_in_store'] += $partsInStore;
            $summaryTotals['parts_in_qc'] += $partsInQc;
            $summaryTotals['parts_in_rework'] += $partsInRework;
            $summaryTotals['parts_in_paint'] += $partsInPaint;
            $summaryTotals['parts_in_assembly'] += $partsInAssembly;
            $summaryTotals['assembly_completed'] += $assemblyCompleted;

            $aggregatedRows->push([
                'standard_part_no' => $partNo,
                'part_name' => $items->first()->part_name ?? $partNo,
                'part_type' => 'STD',
                'total_required' => $totalRequired,
                'total_received' => $totalReceived,
                'pending' => $pending,
                'store' => $partsInStore,
                'qc' => $partsInQc,
                'rework' => $partsInRework,
                'paint' => $partsInPaint,
                'assembly' => $partsInAssembly,
                'completed' => $assemblyCompleted,
                'total_pending' => $pending,
                'parts_in_store' => $partsInStore,
                'parts_in_qc' => $partsInQc,
                'parts_in_rework' => $partsInRework,
                'parts_in_paint' => $partsInPaint,
                'parts_in_assembly' => $partsInAssembly,
                'assembly_completed' => $assemblyCompleted,
                'completion_pct' => $totalRequired > 0 ? (int) round(($totalReceived / $totalRequired) * 100) : 0,
                'status' => $status,
                'status_color' => $statusColor,
                'items_count' => $items->count(),
                'units_count' => count(array_unique($unitSet)),
                'projects_count' => count($projectSet),
                'distinct_projects' => count($projectSet),
                'distinct_units' => count(array_unique($unitSet)),
                'distinct_jigs' => count(array_unique(array_filter($items->pluck('jig_no')->toArray()))),
                'project_codes' => array_values(array_unique($projectSet)),
                'suppliers' => array_values(array_unique($supplierNames)),
                'remarks' => $items->pluck('remarks')->filter()->unique()->implode(', '),
                'size' => $items->first()->size,
            ]);
        }

        // Deterministic stable canonical sort: natural case-insensitive order by standard_part_no ASC
        // Guarantees rows never move or shuffle when quantities change across departments
        $sortedRows = $aggregatedRows->sort(fn($a, $b) => strnatcasecmp($a['standard_part_no'], $b['standard_part_no']))->values();

        $totalRecords = $sortedRows->count();
        $paginated = $sortedRows->slice(($page - 1) * $perPage, $perPage)->values();

        return [
            'summary' => $summaryTotals,
            'total' => $totalRecords,
            'page' => $page,
            'per_page' => $perPage,
            'total_pages' => (int) ceil($totalRecords / max(1, $perPage)),
            'data' => $paginated,
        ];
    }

    /**
     * Get underlying breakdown for a specific STD standard_part_no.
     *
     * @param string $partNo
     * @param int|null $projectId
     * @return array
     */
    public function getPartBreakdown(string $partNo, ?int $projectId = null): array
    {
        $query = BomItem::query()
            ->with(['requirements', 'project', 'supplier'])
            ->where('part_type', 'STD')
            ->where('standard_part_no', $partNo);

        if ($projectId) {
            $query->where('project_id', $projectId);
        }

        $items = $query->orderBy('project_id')->orderBy('jig_no')->orderBy('unit_no')->get();
        $bomItemIds = $items->pluck('id')->toArray();

        $receipts = ReceiptItem::query()
            ->whereIn('bom_item_id', $bomItemIds)
            ->whereIn('status', QuantityCalculationService::VALID_RECEIPT_STATUSES)
            ->get()
            ->groupBy('bom_item_id');

        $qcInspections = QcInspection::query()
            ->whereIn('bom_item_id', $bomItemIds)
            ->get()
            ->groupBy('bom_item_id');

        $reworkRecords = ReworkRecord::query()
            ->whereIn('bom_item_id', $bomItemIds)
            ->get()
            ->groupBy('bom_item_id');

        $paintRecords = PaintRecord::query()
            ->whereIn('bom_item_id', $bomItemIds)
            ->get()
            ->groupBy('bom_item_id');

        $assemblyRecords = AssemblyRecord::query()
            ->whereIn('bom_item_id', $bomItemIds)
            ->where('status', 'completed')
            ->get()
            ->groupBy('bom_item_id');

        $breakdown = [];

        foreach ($items as $item) {
            $itemRecs = $receipts->get($item->id, collect());
            $itemQc = $qcInspections->get($item->id, collect());
            $itemRew = $reworkRecords->get($item->id, collect());
            $itemPnt = $paintRecords->get($item->id, collect());
            $itemAsm = $assemblyRecords->get($item->id, collect());

            foreach ($item->requirements as $req) {
                $side = $req->side;
                $reqQty = (int)$req->required_quantity;
                $recForSide = $itemRecs->where('side', $side);
                $qcForSide = $itemQc->where('side', $side);
                $rewForSide = $itemRew->where('side', $side);
                $pntForSide = $itemPnt->where('side', $side);
                $asmForSide = $itemAsm->where('side', $side);

                $rawRecQty = (int)$recForSide->sum('received_quantity');
                $effectiveRec = min($rawRecQty, $reqQty);

                $qcAppPaint = (int)$qcForSide->filter(fn($q) => $q->approved_quantity > 0 && ($q->destination === 'PAINT' || empty($q->destination)))->sum('approved_quantity');
                $qcAppDirect = (int)$qcForSide->filter(fn($q) => $q->approved_quantity > 0 && $q->destination === 'ASSEMBLY')->sum('approved_quantity');
                $qcApp = $qcAppPaint + $qcAppDirect;
                $qcRej = (int)$qcForSide->sum('rejected_quantity');
                $qcRew = (int)$qcForSide->sum('rework_quantity');

                $rewComp = (int)$rewForSide->whereIn('status', ['completed', 'returned_to_qc'])->sum('quantity');
                $rewActive = max(0, $qcRew - $rewComp);

                $paintComp = (int)$pntForSide->whereIn('status', ['completed', 'assembled'])->sum('quantity');
                $paintActive = max(0, $qcAppPaint - $paintComp);

                $asmComp = (int)$asmForSide->sum('quantity');
                $asmReached = $paintComp + $qcAppDirect;
                $directAsmReady = (int)$recForSide->where('status', 'in_assembly')->sum('received_quantity');
                $asmReady = max(0, $asmReached - $asmComp) + $directAsmReady;

                $qcDispatched = (int)$recForSide->whereNotIn('status', ['received', 'returned_to_store', 'in_assembly', 'assembly_completed'])->sum('received_quantity');
                $qcTotalAccounted = $qcApp + $qcRej + $qcRew;
                $sentToQc = min($effectiveRec, max($qcDispatched, $qcTotalAccounted));

                $qcResident = max(0, $sentToQc + $rewComp - ($qcApp + $qcRej + $qcRew));
                $storeResident = max(0, $effectiveRec - ($qcResident + $qcRej + $rewActive + $paintActive + $asmReady + $asmComp));
                $pending = max(0, $reqQty - $effectiveRec);

                if ($asmComp >= $reqQty && $reqQty > 0) {
                    $status = 'Completed';
                } elseif ($asmReady > 0) {
                    $status = 'Assembly';
                } elseif ($paintActive > 0) {
                    $status = 'Paint';
                } elseif ($rewActive > 0) {
                    $status = 'Rework';
                } elseif ($qcResident > 0) {
                    $status = 'QC';
                } elseif ($storeResident > 0) {
                    $status = 'Store';
                } else {
                    $status = 'Pending';
                }

                $breakdown[] = [
                    'bom_item_id' => $item->id,
                    'project_id' => $item->project_id,
                    'project_code' => $item->project?->project_code ?: $item->project?->name,
                    'project_name' => $item->project?->name,
                    'jig_no' => $item->jig_no ?: 'GENERAL',
                    'unit_no' => $item->unit_no ?: 'Unit 00',
                    'unit_display' => $item->unit_no ? (is_numeric($item->unit_no) ? 'Unit ' . sprintf('%02d', (int)$item->unit_no) : $item->unit_no) : 'Unit 00',
                    'side' => $side,
                    'required' => $reqQty,
                    'received' => $effectiveRec,
                    'pending' => $pending,
                    'store' => $storeResident,
                    'qc' => $qcResident,
                    'rework' => $rewActive,
                    'paint' => $paintActive,
                    'assembly' => $asmReady,
                    'completed' => $asmComp,
                    'required_quantity' => $reqQty,
                    'received_quantity' => $effectiveRec,
                    'pending_quantity' => $pending,
                    'store_quantity' => $storeResident,
                    'qc_quantity' => $qcResident,
                    'rework_quantity' => $rewActive,
                    'paint_quantity' => $paintActive,
                    'assembly_quantity' => $asmReady,
                    'completed_quantity' => $asmComp,
                    'unit_key' => $item->id . '_' . $side,
                    'status' => $status,
                    'supplier_name' => $item->supplier?->name ?? ($item->supplier_name_raw ?? 'Standard'),
                ];
            }
        }

        return $breakdown;
    }

    /**
     * Transactional quantity department transfer for STD part.
     * Enforces deterministic FIFO allocation across contributing units.
     *
     * @param string $partNo
     * @param string $fromDept
     * @param string $toDept
     * @param int $quantity
     * @param array $options ['project_id', 'destination', 'remarks', 'user_id']
     * @return array
     */
    public function transitionQuantity(
        string $partNo,
        string $fromDept,
        string $toDept,
        int $quantity,
        array $options = []
    ): array {
        $fromDept = strtolower(trim($fromDept));
        $toDept = strtolower(trim($toDept));
        $projectId = $options['project_id'] ?? null;
        $destination = strtoupper($options['destination'] ?? 'ASSEMBLY');
        $remarks = $options['remarks'] ?? null;
        $userId = $options['user_id'] ?? null;

        // 1. Validate allowed transition path
        if (!isset(self::ALLOWED_TRANSITIONS[$fromDept]) || !in_array($toDept, self::ALLOWED_TRANSITIONS[$fromDept], true)) {
            throw ValidationException::withMessages([
                'transition' => ["Invalid STD transition from '{$fromDept}' to '{$toDept}'."],
            ]);
        }

        if ($quantity <= 0) {
            throw ValidationException::withMessages([
                'quantity' => ['Transition quantity must be a positive integer greater than 0.'],
            ]);
        }

        return DB::transaction(function () use ($partNo, $fromDept, $toDept, $quantity, $projectId, $destination, $remarks, $userId) {
            // Find all matching STD items ordered deterministically
            $query = BomItem::query()
                ->where('part_type', 'STD')
                ->where('standard_part_no', $partNo)
                ->orderBy('project_id', 'asc')
                ->orderBy('jig_no', 'asc')
                ->orderBy('unit_no', 'asc')
                ->orderBy('id', 'asc');

            if ($projectId) {
                $query->where('project_id', $projectId);
            }

            $items = $query->lockForUpdate()->get();

            if ($items->isEmpty()) {
                throw ValidationException::withMessages([
                    'standard_part_no' => ["STD part '{$partNo}' was not found."],
                ]);
            }

            $bomItemIds = $items->pluck('id')->toArray();
            $remainingToAllocate = $quantity;
            $allocatedRecords = [];

            if ($fromDept === 'pending' && $toDept === 'store') {
                // Pending -> Store Intake
                $requirements = BomRequirement::whereIn('bom_item_id', $bomItemIds)
                    ->orderBy('bom_item_id', 'asc')
                    ->orderBy('side', 'asc')
                    ->lockForUpdate()
                    ->get();

                foreach ($requirements as $req) {
                    $item = $items->firstWhere('id', $req->bom_item_id);
                    $existingRec = (int) ReceiptItem::where('bom_item_id', $req->bom_item_id)
                        ->where('side', $req->side)
                        ->whereIn('status', QuantityCalculationService::VALID_RECEIPT_STATUSES)
                        ->lockForUpdate()
                        ->get()
                        ->sum('received_quantity');

                    $reqQty = (int)$req->required_quantity;
                    $available = max(0, $reqQty - $existingRec);

                    if ($available <= 0) continue;

                    $take = min($remainingToAllocate, $available);

                    $receipt = Receipt::create([
                        'project_id' => $item->project_id,
                        'supplier_id' => $item->supplier_id,
                        'delivery_note_number' => 'STD-INTAKE-' . date('YmdHis'),
                        'received_by' => $userId,
                        'remarks' => $remarks ?: 'Website STD Store Intake',
                    ]);

                    $receiptItem = ReceiptItem::create([
                        'receipt_id' => $receipt->id,
                        'bom_item_id' => $item->id,
                        'side' => $req->side,
                        'received_quantity' => $take,
                        'status' => 'received',
                        'remarks' => $remarks ?: 'Website STD Store Intake',
                    ]);

                    WorkflowEvent::create([
                        'bom_item_id' => $item->id,
                        'project_id' => $item->project_id,
                        'user_id' => $userId,
                        'event_type' => 'store_received',
                        'side' => $req->side,
                        'quantity' => $take,
                        'previous_state' => 'pending',
                        'new_state' => 'received',
                        'remarks' => "STD Store intake: {$take} pcs for {$partNo} ({$item->jig_no}/Unit {$item->unit_no}/{$req->side}).",
                    ]);

                    $allocatedRecords[] = [
                        'bom_item_id' => $item->id,
                        'unit_no' => $item->unit_no,
                        'side' => $req->side,
                        'quantity' => $take,
                    ];

                    $remainingToAllocate -= $take;
                    if ($remainingToAllocate <= 0) break;
                }
            } elseif ($fromDept === 'store' && $toDept === 'qc') {
                // Store -> QC Arrival
                $receiptItems = ReceiptItem::whereIn('bom_item_id', $bomItemIds)
                    ->whereIn('status', ['received', 'sent_to_qc', 'returned_to_store'])
                    ->orderBy('bom_item_id', 'asc')
                    ->orderBy('id', 'asc')
                    ->lockForUpdate()
                    ->get();

                foreach ($receiptItems as $rec) {
                    $available = (int)$rec->received_quantity;
                    if ($available <= 0) continue;

                    $take = min($remainingToAllocate, $available);
                    $item = $items->firstWhere('id', $rec->bom_item_id);

                    if ($take < $available) {
                        $rec->update(['received_quantity' => $available - $take]);

                        $qcItem = $rec->replicate();
                        $qcItem->received_quantity = $take;
                        $qcItem->status = 'qc_received';
                        $qcItem->qc_received_at = now();
                        $qcItem->save();
                    } else {
                        $rec->update([
                            'status' => 'qc_received',
                            'qc_received_at' => now(),
                        ]);
                    }

                    WorkflowEvent::create([
                        'bom_item_id' => $rec->bom_item_id,
                        'project_id' => $item->project_id,
                        'user_id' => $userId,
                        'event_type' => 'qc_received',
                        'side' => $rec->side,
                        'quantity' => $take,
                        'previous_state' => 'store',
                        'new_state' => 'qc_received',
                        'remarks' => "STD Store -> QC: {$take} pcs for {$partNo} ({$item->jig_no}/Unit {$item->unit_no}/{$rec->side}).",
                    ]);

                    $allocatedRecords[] = [
                        'bom_item_id' => $rec->bom_item_id,
                        'unit_no' => $item->unit_no,
                        'side' => $rec->side,
                        'quantity' => $take,
                    ];

                    $remainingToAllocate -= $take;
                    if ($remainingToAllocate <= 0) break;
                }
            } elseif ($fromDept === 'qc' && in_array($toDept, ['rework', 'paint', 'assembly'], true)) {
                // QC Inspection Routing
                $receiptItems = ReceiptItem::whereIn('bom_item_id', $bomItemIds)
                    ->where('status', 'qc_received')
                    ->orderBy('bom_item_id', 'asc')
                    ->orderBy('id', 'asc')
                    ->lockForUpdate()
                    ->get();

                foreach ($receiptItems as $rec) {
                    $available = (int)$rec->received_quantity;
                    if ($available <= 0) continue;

                    $take = min($remainingToAllocate, $available);
                    $item = $items->firstWhere('id', $rec->bom_item_id);

                    if ($toDept === 'rework') {
                        $insp = QcInspection::create([
                            'receipt_item_id' => $rec->id,
                            'bom_item_id' => $rec->bom_item_id,
                            'side' => $rec->side,
                            'inspected_quantity' => $take,
                            'inspector_id' => $userId,
                            'inspected_by' => $userId,
                            'inspection_date' => now(),
                            'approved_quantity' => 0,
                            'rejected_quantity' => 0,
                            'rework_quantity' => $take,
                            'result' => 'rework',
                            'notes' => $remarks ?: 'STD QC Defect -> Rework',
                            'remarks' => $remarks ?: 'STD QC Defect -> Rework',
                            'destination' => null,
                        ]);

                        ReworkRecord::create([
                            'qc_inspection_id' => $insp->id,
                            'bom_item_id' => $rec->bom_item_id,
                            'side' => $rec->side,
                            'quantity' => $take,
                            'status' => 'pending',
                            'defect_description' => $remarks ?: 'STD Quality Defect',
                        ]);

                        if ($take < $available) {
                            $remQty = $rec->received_quantity - $take;
                            $remItem = $rec->replicate();
                            $remItem->received_quantity = $remQty;
                            $remItem->status = 'qc_received';
                            $remItem->save();

                            $rec->received_quantity = $take;
                            $rec->status = 'qc_rework';
                            $rec->save();
                        } else {
                            $rec->update(['status' => 'qc_rework']);
                        }
                    } elseif ($toDept === 'paint') {
                        $insp = QcInspection::create([
                            'receipt_item_id' => $rec->id,
                            'bom_item_id' => $rec->bom_item_id,
                            'side' => $rec->side,
                            'inspected_quantity' => $take,
                            'inspector_id' => $userId,
                            'inspected_by' => $userId,
                            'inspection_date' => now(),
                            'approved_quantity' => $take,
                            'rejected_quantity' => 0,
                            'rework_quantity' => 0,
                            'result' => 'approved',
                            'notes' => $remarks ?: 'STD QC Approved for Paint',
                            'remarks' => $remarks ?: 'STD QC Approved for Paint',
                            'destination' => 'PAINT',
                        ]);

                        PaintRecord::create([
                            'qc_inspection_id' => $insp->id,
                            'bom_item_id' => $rec->bom_item_id,
                            'side' => $rec->side,
                            'quantity' => $take,
                            'status' => 'pending',
                        ]);

                        if ($take < $available) {
                            $remQty = $rec->received_quantity - $take;
                            $remItem = $rec->replicate();
                            $remItem->received_quantity = $remQty;
                            $remItem->status = 'qc_received';
                            $remItem->save();

                            $rec->received_quantity = $take;
                            $rec->status = 'qc_approved';
                            $rec->save();
                        } else {
                            $rec->update(['status' => 'qc_approved']);
                        }
                    } elseif ($toDept === 'assembly') {
                        QcInspection::create([
                            'receipt_item_id' => $rec->id,
                            'bom_item_id' => $rec->bom_item_id,
                            'side' => $rec->side,
                            'inspected_quantity' => $take,
                            'inspector_id' => $userId,
                            'inspected_by' => $userId,
                            'inspection_date' => now(),
                            'approved_quantity' => $take,
                            'rejected_quantity' => 0,
                            'rework_quantity' => 0,
                            'result' => 'approved',
                            'notes' => $remarks ?: 'STD QC Approved for Direct Assembly',
                            'remarks' => $remarks ?: 'STD QC Approved for Direct Assembly',
                            'destination' => 'ASSEMBLY',
                        ]);

                        if ($take < $available) {
                            $remQty = $rec->received_quantity - $take;
                            $remItem = $rec->replicate();
                            $remItem->received_quantity = $remQty;
                            $remItem->status = 'qc_received';
                            $remItem->save();

                            $rec->received_quantity = $take;
                            $rec->status = 'qc_approved';
                            $rec->save();
                        } else {
                            $rec->update(['status' => 'qc_approved']);
                        }
                    }

                    WorkflowEvent::create([
                        'bom_item_id' => $rec->bom_item_id,
                        'project_id' => $item->project_id,
                        'user_id' => $userId,
                        'event_type' => "qc_routed_{$toDept}",
                        'side' => $rec->side,
                        'quantity' => $take,
                        'previous_state' => 'qc_received',
                        'new_state' => $toDept,
                        'remarks' => "STD QC -> {$toDept}: {$take} pcs for {$partNo} ({$item->jig_no}/Unit {$item->unit_no}/{$rec->side}).",
                    ]);

                    $allocatedRecords[] = [
                        'bom_item_id' => $rec->bom_item_id,
                        'unit_no' => $item->unit_no,
                        'side' => $rec->side,
                        'quantity' => $take,
                    ];

                    $remainingToAllocate -= $take;
                    if ($remainingToAllocate <= 0) break;
                }
            } elseif ($fromDept === 'rework' && in_array($toDept, ['qc', 'paint', 'assembly'], true)) {
                // Rework Routing (Rework -> QC re-inspection, Paint, or Direct Assembly)
                $reworks = ReworkRecord::whereIn('bom_item_id', $bomItemIds)
                    ->where('status', 'pending')
                    ->orderBy('bom_item_id', 'asc')
                    ->orderBy('id', 'asc')
                    ->lockForUpdate()
                    ->get();

                foreach ($reworks as $rew) {
                    $available = (int)$rew->quantity;
                    if ($available <= 0) continue;

                    $take = min($remainingToAllocate, $available);
                    $item = $items->firstWhere('id', $rew->bom_item_id);

                    if ($take < $available) {
                        $rew->update(['quantity' => $available - $take]);

                        $compRew = $rew->replicate();
                        $compRew->quantity = $take;
                        $compRew->status = 'completed';
                        $compRew->completed_at = now();
                        $compRew->save();
                    } else {
                        $rew->update([
                            'status' => 'completed',
                            'completed_at' => now(),
                        ]);
                    }

                    if ($toDept === 'qc') {
                        // Re-queue receipt item to qc_received for re-inspection
                        if ($rew->qcInspection?->receiptItem) {
                            $rew->qcInspection->receiptItem->update(['status' => 'qc_received']);
                        }

                        WorkflowEvent::create([
                            'bom_item_id' => $rew->bom_item_id,
                            'project_id' => $item->project_id,
                            'user_id' => $userId,
                            'event_type' => 'rework_completed',
                            'side' => $rew->side,
                            'quantity' => $take,
                            'previous_state' => 'rework',
                            'new_state' => 'qc_received',
                            'remarks' => "STD Rework -> QC: {$take} pcs for {$partNo} ({$item->jig_no}/Unit {$item->unit_no}/{$rew->side}).",
                        ]);
                    } elseif ($toDept === 'paint') {
                        // Route reworked part directly to Paint
                        PaintRecord::create([
                            'qc_inspection_id' => $rew->qc_inspection_id,
                            'bom_item_id' => $rew->bom_item_id,
                            'side' => $rew->side,
                            'quantity' => $take,
                            'status' => 'pending',
                        ]);

                        if ($rew->qcInspection?->receiptItem) {
                            $rew->qcInspection->receiptItem->update(['status' => 'qc_approved']);
                        }

                        WorkflowEvent::create([
                            'bom_item_id' => $rew->bom_item_id,
                            'project_id' => $item->project_id,
                            'user_id' => $userId,
                            'event_type' => 'rework_to_paint',
                            'side' => $rew->side,
                            'quantity' => $take,
                            'previous_state' => 'rework',
                            'new_state' => 'paint',
                            'remarks' => "STD Rework -> Paint: {$take} pcs for {$partNo} ({$item->jig_no}/Unit {$item->unit_no}/{$rew->side}).",
                        ]);
                    } elseif ($toDept === 'assembly') {
                        // Route reworked part directly to Assembly
                        QcInspection::create([
                            'receipt_item_id' => $rew->qcInspection?->receipt_item_id,
                            'bom_item_id' => $rew->bom_item_id,
                            'rework_record_id' => $rew->id,
                            'side' => $rew->side,
                            'inspected_quantity' => $take,
                            'inspector_id' => $userId,
                            'inspected_by' => $userId,
                            'inspection_date' => now(),
                            'approved_quantity' => $take,
                            'rejected_quantity' => 0,
                            'rework_quantity' => 0,
                            'result' => 'approved',
                            'notes' => $remarks ?: 'STD Rework Approved for Direct Assembly',
                            'remarks' => $remarks ?: 'STD Rework Approved for Direct Assembly',
                            'destination' => 'ASSEMBLY',
                            'is_reinspection' => true,
                        ]);

                        if ($rew->qcInspection?->receiptItem) {
                            $rew->qcInspection->receiptItem->update(['status' => 'qc_approved']);
                        }

                        WorkflowEvent::create([
                            'bom_item_id' => $rew->bom_item_id,
                            'project_id' => $item->project_id,
                            'user_id' => $userId,
                            'event_type' => 'rework_to_assembly',
                            'side' => $rew->side,
                            'quantity' => $take,
                            'previous_state' => 'rework',
                            'new_state' => 'assembly',
                            'remarks' => "STD Rework -> Assembly: {$take} pcs for {$partNo} ({$item->jig_no}/Unit {$item->unit_no}/{$rew->side}).",
                        ]);
                    }

                    $allocatedRecords[] = [
                        'bom_item_id' => $rew->bom_item_id,
                        'unit_no' => $item->unit_no,
                        'side' => $rew->side,
                        'quantity' => $take,
                    ];

                    $remainingToAllocate -= $take;
                    if ($remainingToAllocate <= 0) break;
                }
            } elseif ($fromDept === 'paint' && $toDept === 'assembly') {
                // Paint Completion -> Assembly Bay Queue
                $paints = PaintRecord::whereIn('bom_item_id', $bomItemIds)
                    ->where('status', 'pending')
                    ->orderBy('bom_item_id', 'asc')
                    ->orderBy('id', 'asc')
                    ->lockForUpdate()
                    ->get();

                foreach ($paints as $pnt) {
                    $available = (int)$pnt->quantity;
                    if ($available <= 0) continue;

                    $take = min($remainingToAllocate, $available);
                    $item = $items->firstWhere('id', $pnt->bom_item_id);

                    if ($take < $available) {
                        $pnt->update(['quantity' => $available - $take]);

                        $compPnt = $pnt->replicate();
                        $compPnt->quantity = $take;
                        $compPnt->status = 'completed';
                        $compPnt->completed_at = now();
                        $compPnt->save();
                    } else {
                        $pnt->update([
                            'status' => 'completed',
                            'completed_at' => now(),
                        ]);
                    }

                    WorkflowEvent::create([
                        'bom_item_id' => $pnt->bom_item_id,
                        'project_id' => $item->project_id,
                        'user_id' => $userId,
                        'event_type' => 'paint_completed',
                        'side' => $pnt->side,
                        'quantity' => $take,
                        'previous_state' => 'paint',
                        'new_state' => 'assembly',
                        'remarks' => "STD Paint -> Assembly: {$take} pcs for {$partNo} ({$item->jig_no}/Unit {$item->unit_no}/{$pnt->side}).",
                    ]);

                    $allocatedRecords[] = [
                        'bom_item_id' => $pnt->bom_item_id,
                        'unit_no' => $item->unit_no,
                        'side' => $pnt->side,
                        'quantity' => $take,
                    ];

                    $remainingToAllocate -= $take;
                    if ($remainingToAllocate <= 0) break;
                }
            } elseif ($fromDept === 'store' && $toDept === 'assembly') {
                // Store -> Direct Assembly Bay
                $receiptItems = ReceiptItem::whereIn('bom_item_id', $bomItemIds)
                    ->whereIn('status', ['received', 'returned_to_store'])
                    ->orderBy('bom_item_id', 'asc')
                    ->orderBy('id', 'asc')
                    ->lockForUpdate()
                    ->get();

                foreach ($receiptItems as $rec) {
                    $available = (int)$rec->received_quantity;
                    if ($available <= 0) continue;

                    $take = min($remainingToAllocate, $available);
                    $item = $items->firstWhere('id', $rec->bom_item_id);

                    if ($take < $available) {
                        $rec->update(['received_quantity' => $available - $take]);

                        $asmItem = $rec->replicate();
                        $asmItem->received_quantity = $take;
                        $asmItem->status = 'in_assembly';
                        $asmItem->save();
                    } else {
                        $rec->update([
                            'status' => 'in_assembly',
                        ]);
                    }

                    WorkflowEvent::create([
                        'bom_item_id' => $rec->bom_item_id,
                        'project_id' => $item->project_id,
                        'user_id' => $userId,
                        'event_type' => 'store_to_assembly',
                        'side' => $rec->side,
                        'quantity' => $take,
                        'previous_state' => 'store',
                        'new_state' => 'in_assembly',
                        'remarks' => "STD Store -> Direct Assembly: {$take} pcs for {$partNo} ({$item->jig_no}/Unit {$item->unit_no}/{$rec->side}).",
                    ]);

                    $allocatedRecords[] = [
                        'bom_item_id' => $rec->bom_item_id,
                        'unit_no' => $item->unit_no,
                        'side' => $rec->side,
                        'quantity' => $take,
                    ];

                    $remainingToAllocate -= $take;
                    if ($remainingToAllocate <= 0) break;
                }
            } elseif ($fromDept === 'assembly' && $toDept === 'completed') {
                // Assembly Completion -> 100% Assembled
                // Can be fulfilled from Paint Completed, Direct QC, or Direct in_assembly receipts
                $paints = PaintRecord::whereIn('bom_item_id', $bomItemIds)
                    ->whereIn('status', ['completed', 'assembled'])
                    ->orderBy('bom_item_id', 'asc')
                    ->orderBy('id', 'asc')
                    ->lockForUpdate()
                    ->get();

                $directQcs = QcInspection::whereIn('bom_item_id', $bomItemIds)
                    ->where('destination', 'ASSEMBLY')
                    ->where('approved_quantity', '>', 0)
                    ->orderBy('bom_item_id', 'asc')
                    ->orderBy('id', 'asc')
                    ->lockForUpdate()
                    ->get();

                $inAssemblyRecs = ReceiptItem::whereIn('bom_item_id', $bomItemIds)
                    ->where('status', 'in_assembly')
                    ->orderBy('bom_item_id', 'asc')
                    ->orderBy('id', 'asc')
                    ->lockForUpdate()
                    ->get();

                // 1. Fulfill from Direct in_assembly receipts first (direct store->assembly)
                foreach ($inAssemblyRecs as $asmRec) {
                    $available = (int)$asmRec->received_quantity;
                    if ($available <= 0) continue;

                    $take = min($remainingToAllocate, $available);
                    $item = $items->firstWhere('id', $asmRec->bom_item_id);

                    if ($take < $available) {
                        $asmRec->update(['received_quantity' => $available - $take]);

                        $compRec = $asmRec->replicate();
                        $compRec->received_quantity = $take;
                        $compRec->status = 'assembly_completed';
                        $compRec->save();
                    } else {
                        $asmRec->update([
                            'status' => 'assembly_completed',
                        ]);
                    }

                    AssemblyRecord::create([
                        'bom_item_id' => $asmRec->bom_item_id,
                        'receipt_item_id' => $asmRec->id,
                        'side' => $asmRec->side,
                        'quantity' => $take,
                        'status' => 'completed',
                        'assembled_by' => $userId,
                        'started_at' => now(),
                        'completed_at' => now(),
                        'remarks' => $remarks ?: "Website STD Assembly Completed ({$take} pcs)",
                    ]);

                    WorkflowEvent::create([
                        'bom_item_id' => $asmRec->bom_item_id,
                        'project_id' => $item->project_id,
                        'user_id' => $userId,
                        'event_type' => 'assembly_completed',
                        'side' => $asmRec->side,
                        'quantity' => $take,
                        'previous_state' => 'in_assembly',
                        'new_state' => 'assembly_completed',
                        'remarks' => "STD Assembly Completed: {$take} pcs for {$partNo} ({$item->jig_no}/Unit {$item->unit_no}/{$asmRec->side}).",
                    ]);

                    $allocatedRecords[] = [
                        'bom_item_id' => $asmRec->bom_item_id,
                        'unit_no' => $item->unit_no,
                        'side' => $asmRec->side,
                        'quantity' => $take,
                    ];

                    $remainingToAllocate -= $take;
                    if ($remainingToAllocate <= 0) break;
                }

                // 2. Fulfill from Paint completed
                if ($remainingToAllocate > 0) {
                    foreach ($paints as $pnt) {
                        $assembled = (int) AssemblyRecord::where('paint_record_id', $pnt->id)->sum('quantity');
                        $available = max(0, $pnt->quantity - $assembled);
                        if ($available <= 0) continue;

                        $take = min($remainingToAllocate, $available);
                        $item = $items->firstWhere('id', $pnt->bom_item_id);

                        AssemblyRecord::create([
                            'bom_item_id' => $pnt->bom_item_id,
                            'paint_record_id' => $pnt->id,
                            'side' => $pnt->side,
                            'quantity' => $take,
                            'status' => 'completed',
                            'assembled_by' => $userId,
                            'started_at' => now(),
                            'completed_at' => now(),
                            'remarks' => $remarks ?: "Website STD Assembly Completed ({$take} pcs)",
                        ]);

                        WorkflowEvent::create([
                            'bom_item_id' => $pnt->bom_item_id,
                            'project_id' => $item->project_id,
                            'user_id' => $userId,
                            'event_type' => 'assembly_completed',
                            'side' => $pnt->side,
                            'quantity' => $take,
                            'previous_state' => 'assembly',
                            'new_state' => 'completed',
                            'remarks' => "STD Assembly Completed: {$take} pcs for {$partNo} ({$item->jig_no}/Unit {$item->unit_no}/{$pnt->side}).",
                        ]);

                        $allocatedRecords[] = [
                            'bom_item_id' => $pnt->bom_item_id,
                            'unit_no' => $item->unit_no,
                            'side' => $pnt->side,
                            'quantity' => $take,
                        ];

                        $remainingToAllocate -= $take;
                        if ($remainingToAllocate <= 0) break;
                    }
                }

                // 3. Fulfill from Direct QC inspections
                if ($remainingToAllocate > 0) {
                    foreach ($directQcs as $dqc) {
                        $assembled = (int) AssemblyRecord::where('qc_inspection_id', $dqc->id)->sum('quantity');
                        $available = max(0, $dqc->approved_quantity - $assembled);
                        if ($available <= 0) continue;

                        $take = min($remainingToAllocate, $available);
                        $item = $items->firstWhere('id', $dqc->bom_item_id);

                        AssemblyRecord::create([
                            'bom_item_id' => $dqc->bom_item_id,
                            'qc_inspection_id' => $dqc->id,
                            'side' => $dqc->side,
                            'quantity' => $take,
                            'status' => 'completed',
                            'assembled_by' => $userId,
                            'started_at' => now(),
                            'completed_at' => now(),
                            'remarks' => $remarks ?: "Website STD Direct Assembly Completed ({$take} pcs)",
                        ]);

                        WorkflowEvent::create([
                            'bom_item_id' => $dqc->bom_item_id,
                            'project_id' => $item->project_id,
                            'user_id' => $userId,
                            'event_type' => 'assembly_completed',
                            'side' => $dqc->side,
                            'quantity' => $take,
                            'previous_state' => 'assembly',
                            'new_state' => 'completed',
                            'remarks' => "STD Direct Assembly Completed: {$take} pcs for {$partNo} ({$item->jig_no}/Unit {$item->unit_no}/{$dqc->side}).",
                        ]);

                        $allocatedRecords[] = [
                            'bom_item_id' => $dqc->bom_item_id,
                            'unit_no' => $item->unit_no,
                            'side' => $dqc->side,
                            'quantity' => $take,
                        ];

                        $remainingToAllocate -= $take;
                        if ($remainingToAllocate <= 0) break;
                    }
                }
            }

            if ($remainingToAllocate > 0) {
                $processed = $quantity - $remainingToAllocate;
                throw ValidationException::withMessages([
                    'quantity' => ["Cannot transition {$quantity} pcs. Only {$processed} pcs were available in {$fromDept}."],
                ]);
            }

            return [
                'success' => true,
                'standard_part_no' => $partNo,
                'from_department' => $fromDept,
                'to_department' => $toDept,
                'quantity_transitioned' => $quantity,
                'allocations' => $allocatedRecords,
                'message' => "Successfully moved {$quantity} pcs of {$partNo} from {$fromDept} to {$toDept}.",
            ];
        });
    }
}
