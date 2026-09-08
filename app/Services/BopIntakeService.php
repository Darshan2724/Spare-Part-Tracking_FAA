<?php

namespace App\Services;

use App\Models\BomItem;
use App\Models\BomRequirement;
use App\Models\Project;
use App\Models\Receipt;
use App\Models\ReceiptItem;
use App\Models\AssemblyRecord;
use App\Models\WorkflowEvent;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class BopIntakeService
{
    /**
     * Allowed BOP transitions.
     * BOP strictly follows: Pending -> Store -> Assembly -> Completed
     */
    public const ALLOWED_TRANSITIONS = [
        'pending' => ['store'],
        'store' => ['assembly'],
        'assembly' => ['completed'],
    ];

    /**
     * Get aggregated BOP parts across all projects (or filtered project).
     *
     * @param array $filters ['project_id', 'search', 'status']
     * @param int $page
     * @param int $perPage
     * @return array
     */
    public function getAggregatedBopParts(array $filters = [], int $page = 1, int $perPage = 50): array
    {
        $projectId = !empty($filters['project_id']) ? (int)$filters['project_id'] : null;
        $search = !empty($filters['search']) ? trim($filters['search']) : null;
        $statusFilter = !empty($filters['status']) ? strtolower(trim($filters['status'])) : 'all';

        // Query all BOP items with requirements, project, and supplier
        $query = BomItem::query()
            ->with(['requirements', 'project', 'supplier'])
            ->where('part_type', 'BOP');

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

        $allBopItems = $query->orderBy('standard_part_no')->get();
        $bomItemIds = $allBopItems->pluck('id')->toArray();

        // Load receipts and assembly records in bulk
        $receipts = ReceiptItem::query()
            ->whereIn('bom_item_id', $bomItemIds)
            ->whereIn('status', QuantityCalculationService::VALID_RECEIPT_STATUSES)
            ->get()
            ->groupBy('bom_item_id');

        $assemblyRecords = AssemblyRecord::query()
            ->whereIn('bom_item_id', $bomItemIds)
            ->where('status', 'completed')
            ->get()
            ->groupBy('bom_item_id');

        // Aggregate by standard_part_no
        $grouped = $allBopItems->groupBy('standard_part_no');
        $aggregatedRows = collect();

        $summaryTotals = [
            'total_parts' => 0,
            'total_received' => 0,
            'parts_pending' => 0,
            'parts_in_store' => 0,
            'parts_in_assembly' => 0,
            'assembly_completed' => 0,
        ];

        foreach ($grouped as $partNo => $items) {
            $totalRequired = 0;
            $totalReceived = 0;
            $partsInStore = 0;
            $partsInAssembly = 0;
            $assemblyCompleted = 0;

            $unitSet = [];
            $projectSet = [];
            $supplierNames = [];

            foreach ($items as $item) {
                $itemRecs = $receipts->get($item->id, collect());
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
                    $asmForSide = $itemAsm->where('side', $side);

                    $rawRecQty = (int)$recForSide->sum('received_quantity');
                    $effectiveRec = min($rawRecQty, $reqQty);

                    $asmComp = (int)$asmForSide->sum('quantity');
                    $asmReady = (int)$recForSide->where('status', 'in_assembly')->sum('received_quantity');
                    $storeResident = (int)$recForSide->whereIn('status', ['received', 'returned_to_store'])->sum('received_quantity');

                    $totalRequired += $reqQty;
                    $totalReceived += $effectiveRec;
                    $partsInStore += $storeResident;
                    $partsInAssembly += $asmReady;
                    $assemblyCompleted += $asmComp;
                }
            }

            $pending = max(0, $totalRequired - $totalReceived);

            // Determine status badge
            if ($assemblyCompleted >= $totalRequired && $totalRequired > 0) {
                $status = 'Completed';
                $statusColor = 'success';
            } elseif ($partsInAssembly > 0) {
                $status = 'Assembly';
                $statusColor = 'pink';
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
                if ($statusFilter === 'store' && $partsInStore <= 0) continue;
                if ($statusFilter === 'pending' && $pending <= 0) continue;
            }

            // Accumulate summary totals
            $summaryTotals['total_parts'] += $totalRequired;
            $summaryTotals['total_received'] += $totalReceived;
            $summaryTotals['parts_pending'] += $pending;
            $summaryTotals['parts_in_store'] += $partsInStore;
            $summaryTotals['parts_in_assembly'] += $partsInAssembly;
            $summaryTotals['assembly_completed'] += $assemblyCompleted;

            $aggregatedRows->push([
                'standard_part_no' => $partNo,
                'part_name' => $items->first()->part_name ?? $partNo,
                'part_type' => 'BOP',
                'total_required' => $totalRequired,
                'total_received' => $totalReceived,
                'pending' => $pending,
                'store' => $partsInStore,
                'assembly' => $partsInAssembly,
                'completed' => $assemblyCompleted,
                'total_pending' => $pending,
                'parts_in_store' => $partsInStore,
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
     * Get underlying breakdown for a specific BOP standard_part_no.
     *
     * @param string $partNo
     * @param int|null $projectId
     * @return array
     */
    public function getPartBreakdown(string $partNo, ?int $projectId = null): array
    {
        $query = BomItem::query()
            ->with(['requirements', 'project', 'supplier'])
            ->where('part_type', 'BOP')
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

        $assemblyRecords = AssemblyRecord::query()
            ->whereIn('bom_item_id', $bomItemIds)
            ->where('status', 'completed')
            ->get()
            ->groupBy('bom_item_id');

        $breakdown = [];

        foreach ($items as $item) {
            $itemRecs = $receipts->get($item->id, collect());
            $itemAsm = $assemblyRecords->get($item->id, collect());

            foreach ($item->requirements as $req) {
                $side = $req->side;
                $reqQty = (int)$req->required_quantity;
                $recForSide = $itemRecs->where('side', $side);
                $asmForSide = $itemAsm->where('side', $side);

                $rawRecQty = (int)$recForSide->sum('received_quantity');
                $effectiveRec = min($rawRecQty, $reqQty);

                $asmComp = (int)$asmForSide->sum('quantity');
                $asmReady = (int)$recForSide->where('status', 'in_assembly')->sum('received_quantity');
                $storeResident = (int)$recForSide->whereIn('status', ['received', 'returned_to_store'])->sum('received_quantity');
                $pending = max(0, $reqQty - $effectiveRec);

                if ($asmComp >= $reqQty && $reqQty > 0) {
                    $status = 'Completed';
                } elseif ($asmReady > 0) {
                    $status = 'Assembly';
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
                    'assembly' => $asmReady,
                    'completed' => $asmComp,
                    'required_quantity' => $reqQty,
                    'received_quantity' => $effectiveRec,
                    'pending_quantity' => $pending,
                    'store_quantity' => $storeResident,
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
     * Transactional quantity department transfer for BOP part.
     * Enforces deterministic FIFO allocation across contributing units.
     *
     * @param string $partNo
     * @param string $fromDept
     * @param string $toDept
     * @param int $quantity
     * @param int|null $projectId
     * @param string|null $remarks
     * @param int|null $userId
     * @return array
     */
    public function transitionQuantity(
        string $partNo,
        string $fromDept,
        string $toDept,
        int $quantity,
        ?int $projectId = null,
        ?string $remarks = null,
        ?int $userId = null
    ): array {
        $fromDept = strtolower(trim($fromDept));
        $toDept = strtolower(trim($toDept));

        // 1. Validate allowed transition path
        if (!isset(self::ALLOWED_TRANSITIONS[$fromDept]) || !in_array($toDept, self::ALLOWED_TRANSITIONS[$fromDept], true)) {
            throw ValidationException::withMessages([
                'transition' => ["Invalid BOP transition from '{$fromDept}' to '{$toDept}'. BOP only allows: Pending -> Store -> Assembly -> Completed."],
            ]);
        }

        if ($quantity <= 0) {
            throw ValidationException::withMessages([
                'quantity' => ['Transition quantity must be a positive integer greater than 0.'],
            ]);
        }

        return DB::transaction(function () use ($partNo, $fromDept, $toDept, $quantity, $projectId, $remarks, $userId) {
            // Find all matching BOP items ordered deterministically
            $query = BomItem::query()
                ->where('part_type', 'BOP')
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
                    'standard_part_no' => ["BOP part '{$partNo}' was not found."],
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

                    if ($available <= 0) {
                        continue;
                    }

                    $take = min($remainingToAllocate, $available);

                    // Create receipt header if needed
                    $receipt = Receipt::create([
                        'project_id' => $item->project_id,
                        'supplier_id' => $item->supplier_id,
                        'delivery_note_number' => 'BOP-INTAKE-' . date('YmdHis'),
                        'received_by' => $userId,
                        'remarks' => $remarks ?: 'Website BOP Store Intake',
                    ]);

                    $receiptItem = ReceiptItem::create([
                        'receipt_id' => $receipt->id,
                        'bom_item_id' => $item->id,
                        'side' => $req->side,
                        'received_quantity' => $take,
                        'status' => 'received',
                        'remarks' => $remarks ?: 'Website BOP Store Intake',
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
                        'remarks' => "BOP Store intake: {$take} pcs for {$partNo} ({$item->jig_no}/Unit {$item->unit_no}/{$req->side}).",
                    ]);

                    $allocatedRecords[] = [
                        'bom_item_id' => $item->id,
                        'unit_no' => $item->unit_no,
                        'side' => $req->side,
                        'quantity' => $take,
                    ];

                    $remainingToAllocate -= $take;
                    if ($remainingToAllocate <= 0) {
                        break;
                    }
                }
            } elseif ($fromDept === 'store' && $toDept === 'assembly') {
                // Store -> Assembly Transition
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
                        // Atomic split: retain remaining in store, move portion to in_assembly
                        $rec->update(['received_quantity' => $available - $take]);

                        $asmItem = $rec->replicate();
                        $asmItem->received_quantity = $take;
                        $asmItem->status = 'in_assembly';
                        $asmItem->save();
                    } else {
                        $rec->update(['status' => 'in_assembly']);
                    }

                    WorkflowEvent::create([
                        'bom_item_id' => $rec->bom_item_id,
                        'project_id' => $item->project_id,
                        'user_id' => $userId,
                        'event_type' => 'sent_to_assembly',
                        'side' => $rec->side,
                        'quantity' => $take,
                        'previous_state' => 'store',
                        'new_state' => 'assembly',
                        'remarks' => "BOP Store -> Assembly: {$take} pcs for {$partNo} ({$item->jig_no}/Unit {$item->unit_no}/{$rec->side}).",
                    ]);

                    $allocatedRecords[] = [
                        'bom_item_id' => $rec->bom_item_id,
                        'unit_no' => $item->unit_no,
                        'side' => $rec->side,
                        'quantity' => $take,
                    ];

                    $remainingToAllocate -= $take;
                    if ($remainingToAllocate <= 0) {
                        break;
                    }
                }
            } elseif ($fromDept === 'assembly' && $toDept === 'completed') {
                // Assembly -> Completed Transition
                $receiptItems = ReceiptItem::whereIn('bom_item_id', $bomItemIds)
                    ->where('status', 'in_assembly')
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
                        // Atomic split: retain remaining in assembly, complete portion
                        $rec->update(['received_quantity' => $available - $take]);

                        $compItem = $rec->replicate();
                        $compItem->received_quantity = $take;
                        $compItem->status = 'assembly_completed';
                        $compItem->save();
                    } else {
                        $rec->update(['status' => 'assembly_completed']);
                    }

                    AssemblyRecord::create([
                        'bom_item_id' => $rec->bom_item_id,
                        'side' => $rec->side,
                        'quantity' => $take,
                        'status' => 'completed',
                        'assembled_by' => $userId,
                        'started_at' => now(),
                        'completed_at' => now(),
                        'remarks' => $remarks ?: "Website BOP Assembly Completed ({$take} pcs)",
                    ]);

                    WorkflowEvent::create([
                        'bom_item_id' => $rec->bom_item_id,
                        'project_id' => $item->project_id,
                        'user_id' => $userId,
                        'event_type' => 'assembly_completed',
                        'side' => $rec->side,
                        'quantity' => $take,
                        'previous_state' => 'assembly',
                        'new_state' => 'completed',
                        'remarks' => "BOP Assembly Completed: {$take} pcs for {$partNo} ({$item->jig_no}/Unit {$item->unit_no}/{$rec->side}).",
                    ]);

                    $allocatedRecords[] = [
                        'bom_item_id' => $rec->bom_item_id,
                        'unit_no' => $item->unit_no,
                        'side' => $rec->side,
                        'quantity' => $take,
                    ];

                    $remainingToAllocate -= $take;
                    if ($remainingToAllocate <= 0) {
                        break;
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
