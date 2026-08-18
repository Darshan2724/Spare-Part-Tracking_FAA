<?php

namespace App\Services;

use App\Models\BomItem;
use App\Models\BomRequirement;
use App\Models\Project;
use App\Models\ReceiptItem;
use App\Models\QcInspection;
use App\Models\ReworkRecord;
use App\Models\PaintRecord;
use App\Models\AssemblyRecord;
use Illuminate\Support\Facades\DB;

class HierarchyService
{
    /**
     * Build unified hierarchy tree for any operational department.
     *
     * @param string $department 'store' | 'qc' | 'rework' | 'paint' | 'assembly' | 'manager'
     * @param int|null $projectId
     * @param array $filters ['side' => 'RH'|'LH', 'search' => '...']
     * @return array
     */
    public function getDepartmentHierarchy(string $department, ?int $projectId = null, array $filters = []): array
    {
        $projects = Project::orderBy('name')->get();

        $projectsList = $projects->map(function ($proj) use ($department) {
            return $this->getProjectOverviewStats($proj, $department);
        });

        if (!$projectId) {
            return [
                'is_hierarchical' => false,
                'projects' => $projectsList,
                'department' => $department,
                'message' => 'Select a project to view hierarchical breakdown.',
            ];
        }

        $project = Project::find($projectId);
        if (!$project) {
            return [
                'is_hierarchical' => false,
                'projects' => $projectsList,
                'department' => $department,
                'message' => 'Project not found.',
            ];
        }

        // Query BOM Items with necessary relations
        $query = BomItem::query()
            ->with(['requirements', 'supplier', 'project'])
            ->where('project_id', $project->id);

        if (!empty($filters['search'])) {
            $search = trim($filters['search']);
            $query->where(function ($q) use ($search) {
                $q->where('standard_part_no', 'LIKE', "%{$search}%")
                  ->orWhere('item_no', 'LIKE', "%{$search}%");
            });
        }

        $bomItems = $query->orderBy('standard_part_no')->get();

        if ($bomItems->isEmpty()) {
            return [
                'is_hierarchical' => false,
                'project' => $project,
                'projects' => $projectsList,
                'department' => $department,
                'message' => 'No BOM items found for this project.',
            ];
        }

        $bomItemIds = $bomItems->pluck('id')->toArray();

        // Pre-fetch related operational records in bulk
        $receiptItemsGrouped = ReceiptItem::query()
            ->whereIn('bom_item_id', $bomItemIds)
            ->get()
            ->groupBy('bom_item_id');

        $qcInspectionsGrouped = QcInspection::query()
            ->whereIn('bom_item_id', $bomItemIds)
            ->get()
            ->groupBy('bom_item_id');

        $reworkRecordsGrouped = ReworkRecord::query()
            ->whereIn('bom_item_id', $bomItemIds)
            ->get()
            ->groupBy('bom_item_id');

        $paintRecordsGrouped = PaintRecord::query()
            ->whereIn('bom_item_id', $bomItemIds)
            ->get()
            ->groupBy('bom_item_id');

        $assemblyRecordsGrouped = AssemblyRecord::query()
            ->whereIn('bom_item_id', $bomItemIds)
            ->get()
            ->groupBy('bom_item_id');

        $jigsTree = [];

        foreach ($bomItems as $item) {
            $partNo = trim($item->standard_part_no);

            // Read JIG and Unit directly from the authoritative FA-279 BOM fields
            $jigName = !empty($item->jig_no) ? strtoupper(trim($item->jig_no)) : 'GENERAL';
            $unitNo = !empty($item->unit_no) ? 'Unit ' . trim($item->unit_no) : 'Unit 00';

            $itemReceipts = $receiptItemsGrouped->get($item->id, collect());
            $itemQcInspections = $qcInspectionsGrouped->get($item->id, collect());
            $itemReworks = $reworkRecordsGrouped->get($item->id, collect());
            $itemPaints = $paintRecordsGrouped->get($item->id, collect());
            $itemAssemblies = $assemblyRecordsGrouped->get($item->id, collect());

            // Compute side-specific breakdown
            $sideStats = [];
            $itemMetrics = [
                'total_required' => 0,
                'total_received' => 0,
                'total_pending' => 0,
                'qc_pending_arrival' => 0,
                'qc_pending_inspection' => 0,
                'qc_approved' => 0,
                'qc_rejected' => 0,
                'qc_rework' => 0,
                'rework_pending' => 0,
                'rework_in_progress' => 0,
                'rework_completed' => 0,
                'paint_ready' => 0,
                'paint_completed' => 0,
                'assembly_ready' => 0,
                'assembly_completed' => 0,
            ];

            foreach ($item->requirements as $req) {
                $side = $req->side;

                $recForSide = $itemReceipts->where('side', $side);
                $qcForSide = $itemQcInspections->where('side', $side);
                $reworkForSide = $itemReworks->where('side', $side);
                $paintForSide = $itemPaints->where('side', $side);
                $assemblyForSide = $itemAssemblies->where('side', $side);

                $reqQty = (int) $req->required_quantity;
                $recQty = (int) $recForSide->sum('received_quantity');
                $pendingQty = max(0, $reqQty - $recQty);

                // QC Stats
                $qcPendingArrival = (int) $recForSide->whereIn('status', ['received', 'sent_to_qc'])->sum('received_quantity');
                $qcPendingInsp = (int) $recForSide->where('status', 'qc_received')->sum('received_quantity');
                $qcApp = (int) $qcForSide->sum('approved_quantity');
                $qcRej = (int) $qcForSide->sum('rejected_quantity');
                $qcRew = (int) $qcForSide->sum('rework_quantity');

                // Rework Stats
                $rewPending = (int) $reworkForSide->where('status', 'pending')->sum('quantity');
                $rewProg = (int) $reworkForSide->where('status', 'in_progress')->sum('quantity');
                $rewComp = (int) $reworkForSide->where('status', 'completed')->sum('quantity');

                // Paint Stats (Only QC approvals explicitly routed to PAINT)
                $qcAppPaint = (int) $qcForSide->filter(fn($q) => $q->approved_quantity > 0 && ($q->destination === 'PAINT' || empty($q->destination)))->sum('approved_quantity');
                $qcAppDirectAssembly = (int) $qcForSide->filter(fn($q) => $q->approved_quantity > 0 && $q->destination === 'ASSEMBLY')->sum('approved_quantity');

                $paintComp = (int) $paintForSide->where('status', 'completed')->sum('quantity');
                $paintReady = max(0, $qcAppPaint - $paintComp);

                // Assembly Stats (Completed paint parts + Direct QC approved parts routed to ASSEMBLY)
                $asmComp = (int) $assemblyForSide->where('status', 'completed')->sum('quantity');
                $asmReady = max(0, ($paintComp + $qcAppDirectAssembly) - $asmComp);

                $sideStats[$side] = [
                    'required' => $reqQty,
                    'received' => $recQty,
                    'pending' => $pendingQty,
                    'qc_pending_arrival' => $qcPendingArrival,
                    'qc_pending_inspection' => $qcPendingInsp,
                    'qc_approved' => $qcApp,
                    'qc_rejected' => $qcRej,
                    'qc_rework' => $qcRew,
                    'rework_pending' => $rewPending,
                    'rework_in_progress' => $rewProg,
                    'rework_completed' => $rewComp,
                    'paint_ready' => $paintReady,
                    'paint_completed' => $paintComp,
                    'assembly_ready' => $asmReady,
                    'assembly_completed' => $asmComp,
                    'receipt_items' => $recForSide->values(),
                    'qc_inspections' => $qcForSide->values(),
                    'rework_records' => $reworkForSide->values(),
                    'paint_records' => $paintForSide->values(),
                    'assembly_records' => $assemblyForSide->values(),
                ];

                // Accumulate into item metrics (only include in top-level metric if matches filter when filter is active)
                if (empty($filters['side']) || $filters['side'] === $side || $side === 'COMMON') {
                    $itemMetrics['total_required'] += $reqQty;
                    $itemMetrics['total_received'] += min($recQty, $reqQty);
                    $itemMetrics['total_pending'] += $pendingQty;
                    $itemMetrics['qc_pending_arrival'] += $qcPendingArrival;
                    $itemMetrics['qc_pending_inspection'] += $qcPendingInsp;
                    $itemMetrics['qc_approved'] += $qcApp;
                    $itemMetrics['qc_rejected'] += $qcRej;
                    $itemMetrics['qc_rework'] += $qcRew;
                    $itemMetrics['rework_pending'] += $rewPending;
                    $itemMetrics['rework_in_progress'] += $rewProg;
                    $itemMetrics['rework_completed'] += $rewComp;
                    $itemMetrics['paint_ready'] += $paintReady;
                    $itemMetrics['paint_completed'] += $paintComp;
                    $itemMetrics['assembly_ready'] += $asmReady;
                    $itemMetrics['assembly_completed'] += $asmComp;
                }
            }

            // If side filter is active and this item has no requirements for that side, skip
            if (!empty($filters['side']) && !isset($sideStats[$filters['side']]) && !isset($sideStats['COMMON'])) {
                continue;
            }

            // Check department eligibility for downstream workstations
            $isEligible = match ($department) {
                'store' => true,
                'qc' => (($itemMetrics['qc_pending_arrival'] + $itemMetrics['qc_pending_inspection']) > 0),
                'rework' => (($itemMetrics['rework_pending'] + $itemMetrics['rework_in_progress']) > 0),
                'paint' => ($itemMetrics['paint_ready'] > 0),
                'assembly' => ($itemMetrics['assembly_ready'] > 0),
                default => true,
            };

            if (!$isEligible) {
                continue;
            }

            $item->side_stats = $sideStats;
            $item->metrics = $itemMetrics;
            
            // Attach raw operational item IDs for direct action buttons
            $item->receipt_items = $itemReceipts->values();
            $item->qc_inspections = $itemQcInspections->values();
            $item->rework_records = $itemReworks->values();
            $latestRework = $itemReworks->sortByDesc('created_at')->first();
            $item->rework_remark = $latestRework?->completion_notes ?: ($latestRework?->rework_description ?: null);
            $item->paint_records = $itemPaints->values();
            $item->assembly_records = $itemAssemblies->values();

            // Department-specific completion flag
            $item->is_complete = match ($department) {
                'store' => ($itemMetrics['total_required'] > 0 && $itemMetrics['total_pending'] === 0),
                'qc' => ($itemMetrics['total_received'] > 0 && ($itemMetrics['qc_pending_arrival'] + $itemMetrics['qc_pending_inspection']) === 0),
                'rework' => ($itemMetrics['qc_rework'] > 0 && $itemMetrics['rework_pending'] === 0 && $itemMetrics['rework_in_progress'] === 0),
                'paint' => ($itemMetrics['qc_approved'] > 0 && $itemMetrics['paint_ready'] === 0),
                'assembly' => ($itemMetrics['total_required'] > 0 && $itemMetrics['assembly_completed'] >= $itemMetrics['total_required']),
                default => ($itemMetrics['total_required'] > 0 && $itemMetrics['assembly_completed'] >= $itemMetrics['total_required']),
            };

            // Group into JIG and Unit structure
            if (!isset($jigsTree[$jigName])) {
                $jigsTree[$jigName] = [
                    'jig_name' => $jigName,
                    'total_required' => 0,
                    'total_received' => 0,
                    'total_parts' => 0,
                    'complete_units' => 0,
                    'total_units' => 0,
                    'is_complete' => false,
                    'completion_pct' => 0,
                    'metrics' => $this->initZeroMetrics(),
                    'units' => [],
                ];
            }

            if (!isset($jigsTree[$jigName]['units'][$unitNo])) {
                $jigsTree[$jigName]['units'][$unitNo] = [
                    'unit_no' => $unitNo,
                    'jig_name' => $jigName,
                    'total_required' => 0,
                    'total_received' => 0,
                    'total_parts' => 0,
                    'is_complete' => false,
                    'completion_pct' => 0,
                    'metrics' => $this->initZeroMetrics(),
                    'parts' => [],
                ];
            }

            $jigsTree[$jigName]['units'][$unitNo]['parts'][] = $item;
            $jigsTree[$jigName]['units'][$unitNo]['total_parts']++;
            $jigsTree[$jigName]['units'][$unitNo]['total_required'] += $itemMetrics['total_required'];
            $jigsTree[$jigName]['units'][$unitNo]['total_received'] += $itemMetrics['total_received'];
            $this->accumulateMetrics($jigsTree[$jigName]['units'][$unitNo]['metrics'], $itemMetrics);

            $jigsTree[$jigName]['total_parts']++;
            $jigsTree[$jigName]['total_required'] += $itemMetrics['total_required'];
            $jigsTree[$jigName]['total_received'] += $itemMetrics['total_received'];
            $this->accumulateMetrics($jigsTree[$jigName]['metrics'], $itemMetrics);
        }

        // Format and compute percentage completions per Unit and JIG
        $formattedJigs = [];

        foreach ($jigsTree as $jigName => $jigData) {
            $formattedUnits = [];
            $completeUnitsCount = 0;

            foreach ($jigData['units'] as $unitNo => $unitData) {
                // Skip units that have no eligible parts for downstream departments
                if (empty($unitData['parts'])) {
                    continue;
                }

                $hasEligibleUnitParts = match ($department) {
                    'qc' => (($unitData['metrics']['qc_pending_arrival'] + $unitData['metrics']['qc_pending_inspection']) > 0),
                    'rework' => (($unitData['metrics']['rework_pending'] + $unitData['metrics']['rework_in_progress']) > 0),
                    'paint' => ($unitData['metrics']['paint_ready'] > 0),
                    'assembly' => ($unitData['metrics']['assembly_ready'] > 0),
                    default => true,
                };

                if (!$hasEligibleUnitParts) {
                    continue;
                }

                $req = $unitData['total_required'];
                $rec = $unitData['total_received'];
                $unitData['pending_quantity'] = max(0, $req - $rec);

                // Compute dedicated LH and RH side breakdowns (COMMON parts included in both)
                $lhParts = [];
                $rhParts = [];
                $lhMetrics = $this->initZeroMetrics();
                $rhMetrics = $this->initZeroMetrics();
                $lhRequired = 0; $lhReceived = 0; $lhPending = 0;
                $rhRequired = 0; $rhReceived = 0; $rhPending = 0;

                foreach ($unitData['parts'] as $part) {
                    $hasLh = isset($part->side_stats['LH']) || isset($part->side_stats['COMMON']);
                    $hasRh = isset($part->side_stats['RH']) || isset($part->side_stats['COMMON']);

                    if ($hasLh) {
                        $lhParts[] = $part;
                        $st = $part->side_stats['LH'] ?? $part->side_stats['COMMON'];
                        $lhRequired += $st['required'] ?? 0;
                        $lhReceived += $st['received'] ?? 0;
                        $lhPending += $st['pending'] ?? 0;
                        $this->accumulateMetrics($lhMetrics, $st);
                    }
                    if ($hasRh) {
                        $rhParts[] = $part;
                        $st = $part->side_stats['RH'] ?? $part->side_stats['COMMON'];
                        $rhRequired += $st['required'] ?? 0;
                        $rhReceived += $st['received'] ?? 0;
                        $rhPending += $st['pending'] ?? 0;
                        $this->accumulateMetrics($rhMetrics, $st);
                    }
                }

                $lhCompletionPct = match ($department) {
                    'store' => ($lhRequired > 0 ? min(100, round(($lhReceived / $lhRequired) * 100, 1)) : 100),
                    'qc' => ($lhRequired > 0 ? min(100, round(($lhMetrics['qc_approved'] / $lhRequired) * 100, 1)) : 100),
                    'rework' => ($lhMetrics['qc_rework'] > 0 ? min(100, round(($lhMetrics['rework_completed'] / $lhMetrics['qc_rework']) * 100, 1)) : 100),
                    'paint' => ($lhRequired > 0 ? min(100, round(($lhMetrics['paint_completed'] / $lhRequired) * 100, 1)) : 100),
                    'assembly' => ($lhRequired > 0 ? min(100, round(($lhMetrics['assembly_completed'] / $lhRequired) * 100, 1)) : 100),
                    default => ($lhRequired > 0 ? min(100, round(($lhMetrics['assembly_completed'] / $lhRequired) * 100, 1)) : 100),
                };

                $rhCompletionPct = match ($department) {
                    'store' => ($rhRequired > 0 ? min(100, round(($rhReceived / $rhRequired) * 100, 1)) : 100),
                    'qc' => ($rhRequired > 0 ? min(100, round(($rhMetrics['qc_approved'] / $rhRequired) * 100, 1)) : 100),
                    'rework' => ($rhMetrics['qc_rework'] > 0 ? min(100, round(($rhMetrics['rework_completed'] / $rhMetrics['qc_rework']) * 100, 1)) : 100),
                    'paint' => ($rhRequired > 0 ? min(100, round(($rhMetrics['paint_completed'] / $rhRequired) * 100, 1)) : 100),
                    'assembly' => ($rhRequired > 0 ? min(100, round(($rhMetrics['assembly_completed'] / $rhRequired) * 100, 1)) : 100),
                    default => ($rhRequired > 0 ? min(100, round(($rhMetrics['assembly_completed'] / $rhRequired) * 100, 1)) : 100),
                };

                $lhHasEligible = match ($department) {
                    'store' => count($lhParts) > 0,
                    'qc' => (($lhMetrics['qc_pending_arrival'] + $lhMetrics['qc_pending_inspection']) > 0),
                    'rework' => (($lhMetrics['rework_pending'] + $lhMetrics['rework_in_progress']) > 0),
                    'paint' => ($lhMetrics['paint_ready'] > 0 || $lhMetrics['paint_completed'] > 0),
                    'assembly' => ($lhMetrics['assembly_ready'] > 0 || $lhMetrics['assembly_completed'] > 0),
                    default => count($lhParts) > 0,
                };

                $rhHasEligible = match ($department) {
                    'store' => count($rhParts) > 0,
                    'qc' => (($rhMetrics['qc_pending_arrival'] + $rhMetrics['qc_pending_inspection']) > 0),
                    'rework' => (($rhMetrics['rework_pending'] + $rhMetrics['rework_in_progress']) > 0),
                    'paint' => ($rhMetrics['paint_ready'] > 0 || $rhMetrics['paint_completed'] > 0),
                    'assembly' => ($rhMetrics['assembly_ready'] > 0 || $rhMetrics['assembly_completed'] > 0),
                    default => count($rhParts) > 0,
                };

                $unitData['sides'] = [
                    'LH' => [
                        'side' => 'LH',
                        'total_parts' => count($lhParts),
                        'total_required' => $lhRequired,
                        'total_received' => $lhReceived,
                        'pending_quantity' => $lhPending,
                        'completion_pct' => $lhCompletionPct,
                        'is_complete' => ($lhCompletionPct >= 100),
                        'has_eligible' => $lhHasEligible,
                        'metrics' => $lhMetrics,
                    ],
                    'RH' => [
                        'side' => 'RH',
                        'total_parts' => count($rhParts),
                        'total_required' => $rhRequired,
                        'total_received' => $rhReceived,
                        'pending_quantity' => $rhPending,
                        'completion_pct' => $rhCompletionPct,
                        'is_complete' => ($rhCompletionPct >= 100),
                        'has_eligible' => $rhHasEligible,
                        'metrics' => $rhMetrics,
                    ],
                ];

                $unitData['completion_pct'] = match ($department) {
                    'store' => ($req > 0 ? min(100, round(($rec / $req) * 100, 1)) : 100),
                    'qc' => ($req > 0 ? min(100, round(($unitData['metrics']['qc_approved'] / $req) * 100, 1)) : 100),
                    'rework' => ($unitData['metrics']['qc_rework'] > 0 ? min(100, round(($unitData['metrics']['rework_completed'] / $unitData['metrics']['qc_rework']) * 100, 1)) : 100),
                    'paint' => ($req > 0 ? min(100, round(($unitData['metrics']['paint_completed'] / $req) * 100, 1)) : 100),
                    'assembly' => ($req > 0 ? min(100, round(($unitData['metrics']['assembly_completed'] / $req) * 100, 1)) : 100),
                    default => ($req > 0 ? min(100, round(($unitData['metrics']['assembly_completed'] / $req) * 100, 1)) : 100),
                };

                $unitData['is_complete'] = ($unitData['completion_pct'] >= 100);
                if ($unitData['is_complete']) {
                    $completeUnitsCount++;
                }

                $formattedUnits[] = $unitData;
            }

            // Skip JIGs that have no eligible units for downstream departments
            if (empty($formattedUnits)) {
                continue;
            }

            $hasEligibleJigUnits = match ($department) {
                'qc' => (($jigData['metrics']['qc_pending_arrival'] + $jigData['metrics']['qc_pending_inspection']) > 0 || $jigData['metrics']['qc_approved'] > 0),
                'rework' => (($jigData['metrics']['rework_pending'] + $jigData['metrics']['rework_in_progress']) > 0),
                'paint' => ($jigData['metrics']['paint_ready'] > 0 || $jigData['metrics']['paint_completed'] > 0),
                'assembly' => ($jigData['metrics']['assembly_ready'] > 0 || $jigData['metrics']['assembly_completed'] > 0),
                default => true,
            };

            if (!$hasEligibleJigUnits) {
                continue;
            }

            usort($formattedUnits, fn($a, $b) => strcmp($a['unit_no'], $b['unit_no']));

            $totalUnitsCount = count($formattedUnits);
            $jigData['complete_units'] = $completeUnitsCount;
            $jigData['total_units'] = $totalUnitsCount;
            $jigData['is_complete'] = ($totalUnitsCount > 0 && $completeUnitsCount === $totalUnitsCount);

            $jigReq = $jigData['total_required'];
            $jigRec = $jigData['total_received'];

            $jigData['completion_pct'] = match ($department) {
                'store' => ($jigReq > 0 ? min(100, round(($jigRec / $jigReq) * 100, 1)) : 100),
                'qc' => ($jigReq > 0 ? min(100, round(($jigData['metrics']['qc_approved'] / $jigReq) * 100, 1)) : 100),
                'rework' => ($jigData['metrics']['qc_rework'] > 0 ? min(100, round(($jigData['metrics']['rework_completed'] / $jigData['metrics']['qc_rework']) * 100, 1)) : 100),
                'paint' => ($jigReq > 0 ? min(100, round(($jigData['metrics']['paint_completed'] / $jigReq) * 100, 1)) : 100),
                'assembly' => ($jigReq > 0 ? min(100, round(($jigData['metrics']['assembly_completed'] / $jigReq) * 100, 1)) : 100),
                default => ($jigReq > 0 ? min(100, round(($jigData['metrics']['assembly_completed'] / $jigReq) * 100, 1)) : 100),
            };

            $jigData['units'] = $formattedUnits;
            $formattedJigs[] = $jigData;
        }

        usort($formattedJigs, fn($a, $b) => strcmp($a['jig_name'], $b['jig_name']));

        // Retain all projects with active/completed categorization so completed projects remain accessible
        $statusFilter = $filters['status_filter'] ?? 'all';
        $projectsFiltered = $projectsList->values();
        if ($statusFilter === 'active') {
            $projectsFiltered = $projectsList->filter(fn($p) => $p['eligible_qty'] > 0 || !$p['is_complete'])->values();
        } elseif ($statusFilter === 'completed') {
            $projectsFiltered = $projectsList->filter(fn($p) => $p['is_complete'])->values();
        }

        return [
            'is_hierarchical' => count($formattedJigs) > 0,
            'department' => $department,
            'project' => $project,
            'jigs' => $formattedJigs,
            'projects' => $projectsFiltered,
            'message' => count($formattedJigs) === 0 ? "No parts currently eligible or queued for {$department} in this project." : null,
        ];
    }

    /**
     * Get high level progress stats for Project level cards
     */
    protected function getProjectOverviewStats(Project $proj, string $department): array
    {
        $reqSum = (int) BomRequirement::whereHas('bomItem', fn($q) => $q->where('project_id', $proj->id))->sum('required_quantity');
        $recSum = (int) ReceiptItem::whereHas('bomItem', fn($q) => $q->where('project_id', $proj->id))->sum('received_quantity');
        $appSum = (int) QcInspection::whereHas('bomItem', fn($q) => $q->where('project_id', $proj->id))->sum('approved_quantity');
        $rewCompSum = (int) ReworkRecord::whereHas('bomItem', fn($q) => $q->where('project_id', $proj->id))->where('status', 'completed')->sum('quantity');
        $rewActiveSum = (int) ReworkRecord::whereHas('bomItem', fn($q) => $q->where('project_id', $proj->id))->whereIn('status', ['pending', 'in_progress'])->sum('quantity');
        $paintCompSum = (int) PaintRecord::whereHas('bomItem', fn($q) => $q->where('project_id', $proj->id))->where('status', 'completed')->sum('quantity');
        $asmCompSum = (int) AssemblyRecord::whereHas('bomItem', fn($q) => $q->where('project_id', $proj->id))->where('status', 'completed')->sum('quantity');

        $qcPendingSum = (int) ReceiptItem::whereHas('bomItem', fn($q) => $q->where('project_id', $proj->id))->whereIn('status', ['received', 'sent_to_qc', 'qc_received'])->sum('received_quantity');
        $paintReadySum = max(0, $appSum - $paintCompSum);
        $asmReadySum = max(0, $paintCompSum - $asmCompSum);

        $eligibleCount = match ($department) {
            'store' => $reqSum,
            'qc' => $qcPendingSum,
            'rework' => $rewActiveSum,
            'paint' => $paintReadySum,
            'assembly' => $asmReadySum,
            default => $reqSum,
        };

        $progressPercent = match ($department) {
            'store' => ($reqSum > 0 ? round(($recSum / $reqSum) * 100, 1) : 0),
            'qc' => ($reqSum > 0 ? round(($appSum / $reqSum) * 100, 1) : 0),
            'rework' => ($rewCompSum + $rewActiveSum > 0 ? round(($rewCompSum / ($rewCompSum + $rewActiveSum)) * 100, 1) : 100),
            'paint' => ($reqSum > 0 ? round(($paintCompSum / $reqSum) * 100, 1) : 0),
            'assembly' => ($reqSum > 0 ? round(($asmCompSum / $reqSum) * 100, 1) : 0),
            default => ($reqSum > 0 ? round(($asmCompSum / $reqSum) * 100, 1) : 0),
        };

        return [
            'id' => $proj->id,
            'name' => $proj->name,
            'project_code' => $proj->project_code,
            'description' => $proj->description,
            'status' => $proj->status,
            'total_required' => $reqSum,
            'total_received' => $recSum,
            'required_qty' => $reqSum,
            'received_qty' => $recSum,
            'approved_qty' => $appSum,
            'paint_qty' => $paintCompSum,
            'assembly_qty' => $asmCompSum,
            'eligible_qty' => $eligibleCount,
            'has_eligible_parts' => ($department === 'store' || $department === 'manager' || $eligibleCount > 0 || $progressPercent >= 100),
            'progress_percent' => min(100, $progressPercent),
            'completion_pct' => min(100, $progressPercent),
            'is_complete' => ($progressPercent >= 100),
        ];
    }

    protected function initZeroMetrics(): array
    {
        return [
            'total_required' => 0,
            'total_received' => 0,
            'total_pending' => 0,
            'qc_pending_arrival' => 0,
            'qc_pending_inspection' => 0,
            'qc_approved' => 0,
            'qc_rejected' => 0,
            'qc_rework' => 0,
            'rework_pending' => 0,
            'rework_in_progress' => 0,
            'rework_completed' => 0,
            'paint_ready' => 0,
            'paint_completed' => 0,
            'assembly_ready' => 0,
            'assembly_completed' => 0,
        ];
    }

    protected function accumulateMetrics(array &$target, array $source): void
    {
        foreach ($source as $k => $v) {
            if (isset($target[$k])) {
                $target[$k] += (int) $v;
            }
        }
    }
}
