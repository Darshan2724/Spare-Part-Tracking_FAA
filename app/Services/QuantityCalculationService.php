<?php

namespace App\Services;

use App\Models\Project;
use App\Models\BomItem;
use App\Models\BomRequirement;
use App\Models\ReceiptItem;
use App\Models\QcInspection;
use App\Models\ReworkRecord;
use App\Models\PaintRecord;
use App\Models\AssemblyRecord;
use App\Models\PurchaseQueueItem;
use App\Models\Supplier;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class QuantityCalculationService
{
    /**
     * Active/Valid Receipt Statuses that represent valid physical inventory
     * (excludes reverted, returned_to_vendor, scrapped).
     */
    public const VALID_RECEIPT_STATUSES = [
        'received',
        'sent_to_qc',
        'qc_received',
        'qc_approved',
        'qc_rework',
        'qc_rejected',
        'qc_inspected',
        'paint_completed',
        'in_assembly',
        'assembly_completed',
        'returned_to_store'
    ];

    /**
     * Authoritative project health colors mapped to canonical CSS hex colors.
     * Near Completion -> green (#16a34a)
     * On Track -> blue (#2563eb)
     * At Risk -> yellow (#eab308)
     * Delayed -> red (#dc2626)
     */
    public const HEALTH_COLORS = [
        'near_completion' => '#16a34a',
        'on_track' => '#2563eb',
        'at_risk' => '#eab308',
        'delayed' => '#dc2626',
    ];

    /**
     * Calculate authoritative metrics for a single project.
     * Enforces mathematical consistency across all hierarchy levels.
     *
     * @param int|Project $project
     * @param string|null $sideFilter 'RH' | 'LH' | 'COMMON' | null
     * @param array $filters Additional filters: ['supplier_id', 'date_from', 'date_to', 'search']
     * @return array
     */
    public function calculateProjectMetrics(int|Project $project, ?string $sideFilter = null, array $filters = []): array
    {
        $proj = $project instanceof Project ? $project : Project::findOrFail($project);
        $bulk = $this->calculateBulkProjectsMetrics(collect([$proj]), $sideFilter, $filters);
        return $bulk->get($proj->id) ?? $this->formatProjectSummaryResult($proj, []);
    }

    /**
     * Compute authoritative metrics for multiple projects in a single bulk pass.
     * Eliminates N+1 database queries by pre-fetching BOM items, requirements,
     * and operational records across all requested projects in ~6 SQL queries.
     *
     * @param Collection|array $projects
     * @param string|null $sideFilter 'RH' | 'LH' | 'COMMON' | null
     * @param array $filters Additional filters: ['supplier_id', 'date_from', 'date_to', 'search']
     * @return Collection Collection of project metric dictionaries keyed by project ID
     */
    public function calculateBulkProjectsMetrics(Collection|array $projects, ?string $sideFilter = null, array $filters = []): Collection
    {
        if (is_array($projects)) {
            $projectIds = array_filter(array_map(fn($p) => $p instanceof Project ? $p->id : (is_numeric($p) ? (int)$p : null), $projects));
            if (empty($projectIds)) return collect();
            $projectsList = Project::whereIn('id', $projectIds)->get()->keyBy('id');
        } elseif ($projects instanceof Collection) {
            $projectsList = $projects->keyBy('id');
            $projectIds = $projectsList->keys()->toArray();
        } else {
            return collect();
        }

        if (empty($projectIds)) {
            return collect();
        }

        // Query 1: Bulk BOM items and requirements
        $bomItemsQuery = BomItem::query()
            ->with(['requirements', 'supplier'])
            ->whereIn('project_id', $projectIds);

        if (!empty($filters['part_type'])) {
            $bomItemsQuery->where('part_type', strtoupper($filters['part_type']));
        }

        if (!empty($filters['supplier_id'])) {
            $bomItemsQuery->where('supplier_id', $filters['supplier_id']);
        }

        if (!empty($filters['search'])) {
            $search = trim($filters['search']);
            $bomItemsQuery->where(function ($q) use ($search) {
                $q->where('standard_part_no', 'LIKE', "%{$search}%")
                  ->orWhere('item_no', 'LIKE', "%{$search}%");
            });
        }

        $allBomItems = $bomItemsQuery->orderBy('standard_part_no')->get();
        $bomItemsByProject = $allBomItems->groupBy('project_id');
        $allBomItemIds = $allBomItems->pluck('id')->toArray();

        if (empty($allBomItemIds)) {
            $emptyResults = collect();
            foreach ($projectsList as $projId => $proj) {
                $emptyResults->put($projId, $this->formatProjectSummaryResult($proj, [
                    'total_required' => 0,
                    'total_received' => 0,
                    'raw_received' => 0,
                    'excess_received' => 0,
                    'total_pending' => 0,
                    'completion_pct' => 0,
                    'parts_in_store' => 0,
                    'parts_in_qc' => 0,
                    'parts_in_rework' => 0,
                    'parts_in_paint' => 0,
                    'parts_in_assembly' => 0,
                    'awaiting_qc' => 0,
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
                    'total_items' => 0,
                ]));
            }
            return $emptyResults;
        }

        // Bulk load all operational records strictly across all target BOM items with targeted columns
        $recQuery = ReceiptItem::query()
            ->select(['id', 'bom_item_id', 'side', 'received_quantity', 'status'])
            ->whereIn('bom_item_id', $allBomItemIds)
            ->whereIn('status', self::VALID_RECEIPT_STATUSES);

        $qcQuery = QcInspection::query()
            ->select(['id', 'bom_item_id', 'side', 'result', 'approved_quantity', 'rework_quantity', 'rejected_quantity', 'destination'])
            ->whereIn('bom_item_id', $allBomItemIds);

        $reworkQuery = ReworkRecord::query()
            ->select(['id', 'bom_item_id', 'side', 'quantity', 'status'])
            ->whereIn('bom_item_id', $allBomItemIds);

        $paintQuery = PaintRecord::query()
            ->select(['id', 'bom_item_id', 'side', 'quantity', 'status'])
            ->whereIn('bom_item_id', $allBomItemIds);

        $asmQuery = AssemblyRecord::query()
            ->select(['id', 'bom_item_id', 'side', 'quantity', 'status'])
            ->whereIn('bom_item_id', $allBomItemIds);

        if (!empty($filters['date_from'])) {
            $recQuery->where('created_at', '>=', $filters['date_from']);
            $qcQuery->where('inspection_date', '>=', $filters['date_from']);
            $reworkQuery->where('created_at', '>=', $filters['date_from']);
            $paintQuery->where('created_at', '>=', $filters['date_from']);
            $asmQuery->where('created_at', '>=', $filters['date_from']);
        }

        if (!empty($filters['date_to'])) {
            $recQuery->where('created_at', '<=', $filters['date_to']);
            $qcQuery->where('inspection_date', '<=', $filters['date_to']);
            $reworkQuery->where('created_at', '<=', $filters['date_to']);
            $paintQuery->where('created_at', '<=', $filters['date_to']);
            $asmQuery->where('created_at', '<=', $filters['date_to']);
        }

        $receiptsGrouped = $recQuery->get()->groupBy('bom_item_id');
        $qcGrouped = $qcQuery->get()->groupBy('bom_item_id');
        $reworkGrouped = $reworkQuery->get()->groupBy('bom_item_id');
        $paintGrouped = $paintQuery->get()->groupBy('bom_item_id');
        $asmGrouped = $asmQuery->get()->groupBy('bom_item_id');

        $results = collect();

        foreach ($projectsList as $projId => $proj) {
            $bomItems = $bomItemsByProject->get($projId, collect());
            $results->put($projId, $this->computeProjectMetricsFromBomItems(
                $proj,
                $bomItems,
                $sideFilter,
                $receiptsGrouped,
                $qcGrouped,
                $reworkGrouped,
                $paintGrouped,
                $asmGrouped
            ));
        }

        return $results;
    }

    /**
     * Compute authoritative metrics for multiple projects across all 3 part types (ALL, MFG, BOP, STD)
     * in a single consolidated pass.
     * Eliminates 18 out of 24 database queries compared to 4 separate calculateBulkProjectsMetrics calls.
     *
     * @param Collection|array $projects
     * @param string|null $sideFilter
     * @param array $filters
     * @return array ['all' => Collection, 'mfg' => Collection, 'bop' => Collection, 'std' => Collection]
     */
    public function calculateBulkProjectsMetricsWithPartTypes(Collection|array $projects, ?string $sideFilter = null, array $filters = []): array
    {
        @ini_set('memory_limit', '512M');

        $baseFilters = $filters;
        unset($baseFilters['part_type']);

        if (is_array($projects)) {
            $projectIds = array_filter(array_map(fn($p) => $p instanceof Project ? $p->id : (is_numeric($p) ? (int)$p : null), $projects));
            if (empty($projectIds)) {
                $empty = collect();
                return ['all' => $empty, 'mfg' => $empty, 'bop' => $empty, 'std' => $empty];
            }
            $projectsList = Project::whereIn('id', $projectIds)->get()->keyBy('id');
        } elseif ($projects instanceof Collection) {
            $projectsList = $projects->keyBy('id');
            $projectIds = $projectsList->keys()->toArray();
        } else {
            $empty = collect();
            return ['all' => $empty, 'mfg' => $empty, 'bop' => $empty, 'std' => $empty];
        }

        if (empty($projectIds)) {
            $empty = collect();
            return ['all' => $empty, 'mfg' => $empty, 'bop' => $empty, 'std' => $empty];
        }

        // Query 1: Bulk BOM items and requirements across all projects (all part types)
        $bomItemsQuery = DB::table('bom_items')
            ->select(['id', 'project_id', 'part_type'])
            ->whereIn('project_id', $projectIds);

        if (!empty($baseFilters['supplier_id'])) {
            $bomItemsQuery->where('supplier_id', $baseFilters['supplier_id']);
        }

        if (!empty($baseFilters['search'])) {
            $search = trim($baseFilters['search']);
            $bomItemsQuery->where(function ($q) use ($search) {
                $q->where('standard_part_no', 'LIKE', "%{$search}%")
                  ->orWhere('item_no', 'LIKE', "%{$search}%");
            });
        }

        $allBomItems = $bomItemsQuery->get();
        $allBomItemIds = $allBomItems->pluck('id')->toArray();

        if (empty($allBomItemIds)) {
            $emptyResults = collect();
            foreach ($projectsList as $projId => $proj) {
                $emptyResults->put($projId, $this->formatProjectSummaryResult($proj, $this->getEmptyMetricsArray()));
            }
            return [
                'all' => $emptyResults,
                'mfg' => $emptyResults,
                'bop' => $emptyResults,
                'std' => $emptyResults,
            ];
        }

        $requirementsGrouped = DB::table('bom_requirements')
            ->select(['id', 'bom_item_id', 'side', 'required_quantity'])
            ->whereIn('bom_item_id', $allBomItemIds)
            ->get()
            ->groupBy('bom_item_id');

        foreach ($allBomItems as $item) {
            $item->requirements = $requirementsGrouped->get($item->id, collect());
        }

        $bomItemsByProject = $allBomItems->groupBy('project_id');

        // Bulk load all operational records strictly across all target BOM items with targeted columns
        $recQuery = DB::table('receipt_items')
            ->select(['id', 'bom_item_id', 'side', 'received_quantity', 'status'])
            ->whereIn('bom_item_id', $allBomItemIds)
            ->whereIn('status', self::VALID_RECEIPT_STATUSES);

        $qcQuery = DB::table('qc_inspections')
            ->select(['id', 'bom_item_id', 'side', 'result', 'approved_quantity', 'rework_quantity', 'rejected_quantity', 'destination'])
            ->whereIn('bom_item_id', $allBomItemIds);

        $reworkQuery = DB::table('rework_records')
            ->select(['id', 'bom_item_id', 'side', 'quantity', 'status'])
            ->whereIn('bom_item_id', $allBomItemIds);

        $paintQuery = DB::table('paint_records')
            ->select(['id', 'bom_item_id', 'side', 'quantity', 'status'])
            ->whereIn('bom_item_id', $allBomItemIds);

        $asmQuery = DB::table('assembly_records')
            ->select(['id', 'bom_item_id', 'side', 'quantity', 'status'])
            ->whereIn('bom_item_id', $allBomItemIds);

        if (!empty($baseFilters['date_from'])) {
            $recQuery->where('created_at', '>=', $baseFilters['date_from']);
            $qcQuery->where('inspection_date', '>=', $baseFilters['date_from']);
            $reworkQuery->where('created_at', '>=', $baseFilters['date_from']);
            $paintQuery->where('created_at', '>=', $baseFilters['date_from']);
            $asmQuery->where('created_at', '>=', $baseFilters['date_from']);
        }

        if (!empty($baseFilters['date_to'])) {
            $recQuery->where('created_at', '<=', $baseFilters['date_to']);
            $qcQuery->where('inspection_date', '<=', $baseFilters['date_to']);
            $reworkQuery->where('created_at', '<=', $baseFilters['date_to']);
            $paintQuery->where('created_at', '<=', $baseFilters['date_to']);
            $asmQuery->where('created_at', '<=', $baseFilters['date_to']);
        }

        $receiptsGrouped = $recQuery->get()->groupBy('bom_item_id');
        $qcGrouped = $qcQuery->get()->groupBy('bom_item_id');
        $reworkGrouped = $reworkQuery->get()->groupBy('bom_item_id');
        $paintGrouped = $paintQuery->get()->groupBy('bom_item_id');
        $asmGrouped = $asmQuery->get()->groupBy('bom_item_id');

        $allResults = collect();
        $mfgResults = collect();
        $bopResults = collect();
        $stdResults = collect();

        foreach ($projectsList as $projId => $proj) {
            $projBom = $bomItemsByProject->get($projId, collect());

            $allMetrics = $this->getEmptyMetricsArray();
            $mfgMetrics = $this->getEmptyMetricsArray();
            $bopMetrics = $this->getEmptyMetricsArray();
            $stdMetrics = $this->getEmptyMetricsArray();

            $this->accumulateProjectMetrics(
                $projBom,
                $sideFilter,
                $receiptsGrouped,
                $qcGrouped,
                $reworkGrouped,
                $paintGrouped,
                $asmGrouped,
                $allMetrics,
                $mfgMetrics,
                $bopMetrics,
                $stdMetrics
            );

            $allResults->put($projId, $this->formatProjectSummaryResult($proj, $allMetrics));
            $mfgResults->put($projId, $this->formatProjectSummaryResult($proj, $mfgMetrics));
            $bopResults->put($projId, $this->formatProjectSummaryResult($proj, $bopMetrics));
            $stdResults->put($projId, $this->formatProjectSummaryResult($proj, $stdMetrics));
        }

        return [
            'all' => $allResults,
            'mfg' => $mfgResults,
            'bop' => $bopResults,
            'std' => $stdResults,
        ];
    }

    /**
     * Reusable helper to compute project metrics from a collection of BOM items and pre-grouped operational data.
     */
    public function computeProjectMetricsFromBomItems(
        Project $proj,
        Collection $bomItems,
        ?string $sideFilter,
        Collection $receiptsGrouped,
        Collection $qcGrouped,
        Collection $reworkGrouped,
        Collection $paintGrouped,
        Collection $asmGrouped
    ): array {
        if ($bomItems->isEmpty()) {
            return $this->formatProjectSummaryResult($proj, $this->getEmptyMetricsArray());
        }

        $metrics = $this->getEmptyMetricsArray();
        $dummy1 = $this->getEmptyMetricsArray();
        $dummy2 = $this->getEmptyMetricsArray();
        $dummy3 = $this->getEmptyMetricsArray();

        $this->accumulateProjectMetrics(
            $bomItems,
            $sideFilter,
            $receiptsGrouped,
            $qcGrouped,
            $reworkGrouped,
            $paintGrouped,
            $asmGrouped,
            $metrics,
            $dummy1,
            $dummy2,
            $dummy3
        );

        return $this->formatProjectSummaryResult($proj, $metrics);
    }

    /**
     * High-performance single-pass metric accumulation with zero Collection allocation.
     */
    protected function accumulateProjectMetrics(
        Collection $bomItems,
        ?string $sideFilter,
        Collection $receiptsGrouped,
        Collection $qcGrouped,
        Collection $reworkGrouped,
        Collection $paintGrouped,
        Collection $asmGrouped,
        array &$allMetrics,
        array &$mfgMetrics,
        array &$bopMetrics,
        array &$stdMetrics
    ): void {
        foreach ($bomItems as $item) {
            $rawType = strtoupper($item->part_type ?? 'MFG');
            $targetType = match ($rawType) {
                'BOP' => 'BOP',
                'STD' => 'STD',
                default => 'MFG',
            };

            $allMetrics['total_items']++;
            if ($targetType === 'MFG') $mfgMetrics['total_items']++;
            elseif ($targetType === 'BOP') $bopMetrics['total_items']++;
            else $stdMetrics['total_items']++;

            $itemReceipts = $receiptsGrouped->get($item->id);
            $itemQc = $qcGrouped->get($item->id);
            $itemRework = $reworkGrouped->get($item->id);
            $itemPaint = $paintGrouped->get($item->id);
            $itemAsm = $asmGrouped->get($item->id);

            foreach ($item->requirements as $req) {
                $side = $req->side;
                if (!empty($sideFilter) && $sideFilter !== $side) {
                    continue;
                }

                $reqQty = (int) $req->required_quantity;

                // Process receipts for this side
                $rawRecQty = 0;
                $asmReadyFromRec = 0;
                $storeResidentFromRec = 0;
                $qcDispatchedFromReceipts = 0;

                if ($itemReceipts) {
                    foreach ($itemReceipts as $rec) {
                        if ($rec->side === $side) {
                            $qty = (int) $rec->received_quantity;
                            $rawRecQty += $qty;
                            $status = $rec->status;
                            if ($status === 'in_assembly') {
                                $asmReadyFromRec += $qty;
                            } elseif ($status === 'received' || $status === 'returned_to_store') {
                                $storeResidentFromRec += $qty;
                            } else {
                                $qcDispatchedFromReceipts += $qty;
                            }
                        }
                    }
                }

                $effectiveRecQty = min($rawRecQty, $reqQty);
                $excessQty = max(0, $rawRecQty - $reqQty);
                $pendingQty = max(0, $reqQty - $effectiveRecQty);

                if ($targetType === 'BOP') {
                    $asmComp = 0;
                    if ($itemAsm) {
                        foreach ($itemAsm as $a) {
                            if ($a->side === $side && $a->status === 'completed') {
                                $asmComp += (int) $a->quantity;
                            }
                        }
                    }
                    $asmReady = $asmReadyFromRec;
                    $storeResident = $storeResidentFromRec;

                    $qcApp = 0;
                    $qcRej = 0;
                    $qcRew = 0;
                    $rewComp = 0;
                    $rewActive = 0;
                    $rewInProgress = 0;
                    $paintComp = 0;
                    $paintActive = 0;
                    $qcResident = 0;
                } else {
                    $qcAppPaint = 0;
                    $qcAppDirectAssembly = 0;
                    $qcRej = 0;
                    $qcRew = 0;

                    if ($itemQc) {
                        foreach ($itemQc as $q) {
                            if ($q->side === $side) {
                                $appQty = (int) $q->approved_quantity;
                                if ($appQty > 0) {
                                    if ($q->destination === 'ASSEMBLY') {
                                        $qcAppDirectAssembly += $appQty;
                                    } else {
                                        $qcAppPaint += $appQty;
                                    }
                                }
                                $qcRej += (int) $q->rejected_quantity;
                                $qcRew += (int) $q->rework_quantity;
                            }
                        }
                    }
                    $qcApp = $qcAppPaint + $qcAppDirectAssembly;

                    $rewComp = 0;
                    $rewInProgress = 0;
                    if ($itemRework) {
                        foreach ($itemRework as $rew) {
                            if ($rew->side === $side) {
                                $rQty = (int) $rew->quantity;
                                $rStatus = $rew->status;
                                if ($rStatus === 'completed' || $rStatus === 'returned_to_qc') {
                                    $rewComp += $rQty;
                                }
                                if ($rStatus === 'in_progress') {
                                    $rewInProgress += $rQty;
                                }
                            }
                        }
                    }
                    $rewActive = max(0, $qcRew - $rewComp);

                    $paintComp = 0;
                    if ($itemPaint) {
                        foreach ($itemPaint as $p) {
                            if ($p->side === $side) {
                                $pStatus = $p->status;
                                if ($pStatus === 'completed' || $pStatus === 'assembled') {
                                    $paintComp += (int) $p->quantity;
                                }
                            }
                        }
                    }
                    $paintActive = max(0, $qcAppPaint - $paintComp);

                    $asmComp = 0;
                    if ($itemAsm) {
                        foreach ($itemAsm as $a) {
                            if ($a->side === $side && $a->status === 'completed') {
                                $asmComp += (int) $a->quantity;
                            }
                        }
                    }
                    $asmReached = $paintComp + $qcAppDirectAssembly;
                    $asmReady = max(0, $asmReached - $asmComp) + $asmReadyFromRec;

                    $qcTotalAccounted = $qcApp + $qcRej + $qcRew;
                    $sentToQc = min($effectiveRecQty, max($qcDispatchedFromReceipts, $qcTotalAccounted));

                    $qcResident = max(0, $sentToQc + $rewComp - ($qcApp + $qcRej + $qcRew));
                    $storeResident = max(0, $effectiveRecQty - ($qcResident + $qcRej + $rewActive + $paintActive + $asmReady + $asmComp));
                }

                $allMetrics['total_required'] += $reqQty;
                $allMetrics['total_received'] += $effectiveRecQty;
                $allMetrics['raw_received'] += $rawRecQty;
                $allMetrics['excess_received'] += $excessQty;
                $allMetrics['total_pending'] += $pendingQty;
                $allMetrics['parts_in_store'] += $storeResident;
                $allMetrics['parts_in_qc'] += $qcResident;
                $allMetrics['parts_in_rework'] += $rewActive;
                $allMetrics['parts_in_paint'] += $paintActive;
                $allMetrics['parts_in_assembly'] += $asmReady;
                $allMetrics['awaiting_qc'] += $qcResident;
                $allMetrics['qc_approved'] += $qcApp;
                $allMetrics['qc_rejected'] += $qcRej;
                $allMetrics['qc_rework'] += $qcRew;
                $allMetrics['rework_pending'] += $rewActive;
                $allMetrics['rework_in_progress'] += $rewInProgress;
                $allMetrics['rework_completed'] += $rewComp;
                $allMetrics['paint_ready'] += $paintActive;
                $allMetrics['paint_completed'] += $paintComp;
                $allMetrics['assembly_ready'] += $asmReady;
                $allMetrics['assembly_completed'] += $asmComp;

                if ($targetType === 'MFG') {
                    $mfgMetrics['total_required'] += $reqQty;
                    $mfgMetrics['total_received'] += $effectiveRecQty;
                    $mfgMetrics['raw_received'] += $rawRecQty;
                    $mfgMetrics['excess_received'] += $excessQty;
                    $mfgMetrics['total_pending'] += $pendingQty;
                    $mfgMetrics['parts_in_store'] += $storeResident;
                    $mfgMetrics['parts_in_qc'] += $qcResident;
                    $mfgMetrics['parts_in_rework'] += $rewActive;
                    $mfgMetrics['parts_in_paint'] += $paintActive;
                    $mfgMetrics['parts_in_assembly'] += $asmReady;
                    $mfgMetrics['awaiting_qc'] += $qcResident;
                    $mfgMetrics['qc_approved'] += $qcApp;
                    $mfgMetrics['qc_rejected'] += $qcRej;
                    $mfgMetrics['qc_rework'] += $qcRew;
                    $mfgMetrics['rework_pending'] += $rewActive;
                    $mfgMetrics['rework_in_progress'] += $rewInProgress;
                    $mfgMetrics['rework_completed'] += $rewComp;
                    $mfgMetrics['paint_ready'] += $paintActive;
                    $mfgMetrics['paint_completed'] += $paintComp;
                    $mfgMetrics['assembly_ready'] += $asmReady;
                    $mfgMetrics['assembly_completed'] += $asmComp;
                } elseif ($targetType === 'BOP') {
                    $bopMetrics['total_required'] += $reqQty;
                    $bopMetrics['total_received'] += $effectiveRecQty;
                    $bopMetrics['raw_received'] += $rawRecQty;
                    $bopMetrics['excess_received'] += $excessQty;
                    $bopMetrics['total_pending'] += $pendingQty;
                    $bopMetrics['parts_in_store'] += $storeResident;
                    $bopMetrics['parts_in_qc'] += $qcResident;
                    $bopMetrics['parts_in_rework'] += $rewActive;
                    $bopMetrics['parts_in_paint'] += $paintActive;
                    $bopMetrics['parts_in_assembly'] += $asmReady;
                    $bopMetrics['awaiting_qc'] += $qcResident;
                    $bopMetrics['qc_approved'] += $qcApp;
                    $bopMetrics['qc_rejected'] += $qcRej;
                    $bopMetrics['qc_rework'] += $qcRew;
                    $bopMetrics['rework_pending'] += $rewActive;
                    $bopMetrics['rework_in_progress'] += $rewInProgress;
                    $bopMetrics['rework_completed'] += $rewComp;
                    $bopMetrics['paint_ready'] += $paintActive;
                    $bopMetrics['paint_completed'] += $paintComp;
                    $bopMetrics['assembly_ready'] += $asmReady;
                    $bopMetrics['assembly_completed'] += $asmComp;
                } else {
                    $stdMetrics['total_required'] += $reqQty;
                    $stdMetrics['total_received'] += $effectiveRecQty;
                    $stdMetrics['raw_received'] += $rawRecQty;
                    $stdMetrics['excess_received'] += $excessQty;
                    $stdMetrics['total_pending'] += $pendingQty;
                    $stdMetrics['parts_in_store'] += $storeResident;
                    $stdMetrics['parts_in_qc'] += $qcResident;
                    $stdMetrics['parts_in_rework'] += $rewActive;
                    $stdMetrics['parts_in_paint'] += $paintActive;
                    $stdMetrics['parts_in_assembly'] += $asmReady;
                    $stdMetrics['awaiting_qc'] += $qcResident;
                    $stdMetrics['qc_approved'] += $qcApp;
                    $stdMetrics['qc_rejected'] += $qcRej;
                    $stdMetrics['qc_rework'] += $qcRew;
                    $stdMetrics['rework_pending'] += $rewActive;
                    $stdMetrics['rework_in_progress'] += $rewInProgress;
                    $stdMetrics['rework_completed'] += $rewComp;
                    $stdMetrics['paint_ready'] += $paintActive;
                    $stdMetrics['paint_completed'] += $paintComp;
                    $stdMetrics['assembly_ready'] += $asmReady;
                    $stdMetrics['assembly_completed'] += $asmComp;
                }
            }
        }

        $allMetrics['completion_pct'] = $allMetrics['total_required'] > 0
            ? min(100, round(($allMetrics['total_received'] / $allMetrics['total_required']) * 100, 1))
            : 0;
        $mfgMetrics['completion_pct'] = $mfgMetrics['total_required'] > 0
            ? min(100, round(($mfgMetrics['total_received'] / $mfgMetrics['total_required']) * 100, 1))
            : 0;
        $bopMetrics['completion_pct'] = $bopMetrics['total_required'] > 0
            ? min(100, round(($bopMetrics['total_received'] / $bopMetrics['total_required']) * 100, 1))
            : 0;
        $stdMetrics['completion_pct'] = $stdMetrics['total_required'] > 0
            ? min(100, round(($stdMetrics['total_received'] / $stdMetrics['total_required']) * 100, 1))
            : 0;
    }

    /**
     * Default empty metrics dictionary
     */
    public function getEmptyMetricsArray(): array
    {
        return [
            'total_required' => 0,
            'total_received' => 0,
            'raw_received' => 0,
            'excess_received' => 0,
            'total_pending' => 0,
            'completion_pct' => 0,
            'parts_in_store' => 0,
            'parts_in_qc' => 0,
            'parts_in_rework' => 0,
            'parts_in_paint' => 0,
            'parts_in_assembly' => 0,
            'awaiting_qc' => 0,
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
            'total_items' => 0,
        ];
    }

    /**
     * Compute authoritative dashboard summary across all projects (or filtered project).
     * Guarantees that Dashboard KPI cards equal the sum of active project metrics.
     *
     * @param array $filters ['project_id', 'side', 'supplier_id', 'date_from', 'date_to']
     * @param Collection|null $precomputedBulkMetrics
     * @return array
     */
    public function calculateDashboardSummary(array $filters = [], ?Collection $precomputedBulkMetrics = null): array
    {
        @ini_set('memory_limit', '512M');

        $projectId = !empty($filters['project_id']) ? (int) $filters['project_id'] : null;
        $side = !empty($filters['side']) ? $filters['side'] : null;

        $projectsQuery = Project::query();
        if ($projectId) {
            $projectsQuery->where('id', $projectId);
        } else {
            $projectsQuery->where('status', 'active');
        }

        $projects = $projectsQuery->get();
        $isSingleProject = ($projectId !== null);

        if ($isSingleProject) {
            $singleProj = $projects->first();
            $totalActiveProjects = ($singleProj && $singleProj->status === 'active') ? 1 : 0;
            $completedProjects = ($singleProj && $singleProj->status === 'completed') ? 1 : 0;
            $delayedProjects = 0;
            if ($singleProj && $singleProj->status === 'active') {
                $isDelayed = Project::where('id', $singleProj->id)
                    ->where('created_at', '<', now()->subDays(14))
                    ->whereDoesntHave('bomItems.receiptItems', fn($q) => $q->where('updated_at', '>=', now()->subDays(14)))
                    ->exists();
                $delayedProjects = $isDelayed ? 1 : 0;
            }
        } else {
            $totalActiveProjects = Project::where('status', 'active')->count();
            $completedProjects = Project::where('status', 'completed')->count();
            $delayedProjects = Project::where('status', 'active')
                ->where('created_at', '<', now()->subDays(14))
                ->whereDoesntHave('bomItems.receiptItems', fn($q) => $q->where('updated_at', '>=', now()->subDays(14)))
                ->count();
        }

        $pqQuery = PurchaseQueueItem::query();
        if ($projectId) {
            $pqQuery->where('project_id', $projectId);
        }
        if ($side) {
            $pqQuery->where('side', $side);
        }
        if (!empty($filters['date_from'])) {
            $pqQuery->where('created_at', '>=', $filters['date_from']);
        }
        if (!empty($filters['date_to'])) {
            $pqQuery->where('created_at', '<=', $filters['date_to']);
        }
        if (!empty($filters['part_type'])) {
            $partType = strtoupper($filters['part_type']);
            $pqQuery->whereHas('bomItem', fn($q) => $q->where('part_type', $partType));
        }
        $pendingPurchase = (int) $pqQuery->where('status', 'pending_purchase')->count();

        $grandSummary = [
            'total_projects' => $totalActiveProjects,
            'active_projects' => $totalActiveProjects,
            'completed_projects' => $completedProjects,
            'delayed_projects' => $delayedProjects,
            'total_parts' => 0,
            'total_parts_received' => 0,
            'parts_pending' => 0,
            'total_required' => 0,
            'total_received' => 0,
            'raw_received' => 0,
            'excess_received' => 0,
            'total_pending' => 0,
            'pending_store' => 0,
            'parts_in_store' => 0,
            'parts_in_qc' => 0,
            'parts_in_rework' => 0,
            'parts_in_paint' => 0,
            'parts_in_assembly' => 0,
            'awaiting_qc' => 0,
            'qc_approved' => 0,
            'qc_rework' => 0,
            'qc_rejected' => 0,
            'pending_purchase' => $pendingPurchase,
            'paint_pending' => 0,
            'paint_completed' => 0,
            'assembly_pending' => 0,
            'assembly_completed' => 0,
            'completion_pct' => 0,
        ];

        $bulkMetrics = $precomputedBulkMetrics ?? $this->calculateBulkProjectsMetrics($projects, $side, $filters);

        foreach ($projects as $proj) {
            $pMetrics = $bulkMetrics->get($proj->id) ?? $this->formatProjectSummaryResult($proj, []);
            $grandSummary['total_required'] += $pMetrics['required_qty'];
            $grandSummary['total_received'] += $pMetrics['received_qty'];
            $grandSummary['total_parts'] += $pMetrics['required_qty'];
            $grandSummary['total_parts_received'] += $pMetrics['received_qty'];
            $grandSummary['raw_received'] += $pMetrics['raw_received'];
            $grandSummary['excess_received'] += $pMetrics['excess_received'];
            $grandSummary['total_pending'] += $pMetrics['pending_qty'];
            $grandSummary['parts_pending'] += $pMetrics['pending_qty'];
            $grandSummary['pending_store'] += $pMetrics['pending_qty'];
            $grandSummary['parts_in_store'] += $pMetrics['parts_in_store'];
            $grandSummary['parts_in_qc'] += $pMetrics['parts_in_qc'];
            $grandSummary['parts_in_rework'] += $pMetrics['parts_in_rework'];
            $grandSummary['parts_in_paint'] += $pMetrics['parts_in_paint'];
            $grandSummary['parts_in_assembly'] += $pMetrics['parts_in_assembly'];
            $grandSummary['awaiting_qc'] += $pMetrics['awaiting_qc'];
            $grandSummary['qc_approved'] += $pMetrics['approved_qty'];
            $grandSummary['qc_rework'] += $pMetrics['qc_rework'];
            $grandSummary['qc_rejected'] += $pMetrics['rejected_qty'];
            $grandSummary['paint_pending'] += $pMetrics['paint_ready'];
            $grandSummary['paint_completed'] += $pMetrics['paint_qty'];
            $grandSummary['assembly_pending'] += $pMetrics['assembly_ready'];
            $grandSummary['assembly_completed'] += $pMetrics['assembly_qty'];
        }

        $grandSummary['completion_pct'] = $grandSummary['total_required'] > 0
            ? min(100, round(($grandSummary['total_received'] / $grandSummary['total_required']) * 100, 1))
            : 0;

        return $grandSummary;
    }

    /**
     * Compute progress breakdown across all projects for Dashboard Project Progress table.
     *
     * @param array $filters
     * @param Collection|null $precomputedBulkMetrics
     * @return Collection
     */
    public function calculateProjectsProgress(array $filters = [], ?Collection $precomputedBulkMetrics = null): Collection
    {
        $projects = Project::withActiveEcn()
            ->activeOrHasActiveEcn()
            ->withCount('bomItems')
            ->orderBy('name')
            ->get();
        $side = $filters['side'] ?? null;

        $bulkMetrics = $precomputedBulkMetrics ?? $this->calculateBulkProjectsMetrics($projects, $side, $filters);

        return $projects->map(function ($proj) use ($bulkMetrics) {
            $pMetrics = $bulkMetrics->get($proj->id) ?? $this->formatProjectSummaryResult($proj, []);
            $req = $pMetrics['required_qty'] ?? 0;
            $asm = $pMetrics['assembly_completed'] ?? $pMetrics['assembly_qty'] ?? 0;
            $weightedProgress = $req > 0 ? min(100, round(($asm / $req) * 100, 1)) : 0.0;

            return [
                'id' => $proj->id,
                'project_code' => $proj->project_code,
                'name' => $proj->name,
                'status' => $proj->status,
                'has_active_ecn' => (bool)($proj->has_active_ecn ?? false),
                'is_ecn_active' => $proj->is_ecn_active,
                'total_items' => $proj->bom_items_count,
                'required_qty' => $pMetrics['required_qty'],
                'received_qty' => $pMetrics['received_qty'],
                'raw_received' => $pMetrics['raw_received'],
                'excess_received' => $pMetrics['excess_received'],
                'pending_qty' => $pMetrics['pending_qty'],
                'parts_in_store' => $pMetrics['parts_in_store'],
                'parts_in_qc' => $pMetrics['parts_in_qc'],
                'parts_in_rework' => $pMetrics['parts_in_rework'],
                'parts_in_paint' => $pMetrics['parts_in_paint'],
                'parts_in_assembly' => $pMetrics['parts_in_assembly'],
                'approved_qty' => $pMetrics['approved_qty'],
                'rework_qty' => $pMetrics['rework_qty'],
                'rejected_qty' => $pMetrics['rejected_qty'],
                'paint_qty' => $pMetrics['paint_qty'],
                'assembly_qty' => $pMetrics['assembly_qty'],
                'assembly_completed' => $asm,
                'weighted_completion' => $weightedProgress,
                'progress_percent' => $pMetrics['progress_percent'],
                'completion_pct' => $pMetrics['completion_pct'],
                'is_complete' => $pMetrics['is_complete'],
            ];
        });
    }

    /**
     * Calculate Top Projects Near Completion for Dashboard.
     * Ranks active projects by completion percentage descending.
     * Attaches authoritative health classification (Near Completion: green, On Track: blue, At Risk: yellow, Delayed: red).
     * When $limit <= 0, returns all qualifying active incomplete projects.
     *
     * @param array $filters
     * @param int $limit
     * @param Collection|null $precomputedProgress
     * @param Collection|null $precomputedBulkMetrics
     * @return array
     */
    public function getTopProjectsNearCompletion(array $filters = [], int $limit = 0, ?Collection $precomputedProgress = null, ?Collection $precomputedBulkMetrics = null): array
    {
        $progress = $precomputedProgress ?? $this->calculateProjectsProgress($filters, $precomputedBulkMetrics);

        $projects = Project::whereIn('id', $progress->pluck('id'))->get();
        $healthMap = $this->getProjectHealthMap($projects, $filters, $precomputedBulkMetrics);

        // Filter active incomplete projects with required > 0 and calculate exact weighted assembly completion
        $activeIncomplete = $progress->map(function ($p) use ($healthMap) {
            $req = $p['required_qty'] ?? 0;
            $asm = $p['assembly_completed'] ?? $p['assembly_qty'] ?? 0;
            $weighted = $req > 0 ? min(100, round(($asm / $req) * 100, 1)) : 0.0;
            $p['weighted_completion'] = $weighted;
            $p['assembly_completed'] = $asm;

            $h = $healthMap[$p['id']] ?? null;
            $p['health_status'] = $h['category'] ?? ($weighted >= 85.0 ? 'near_completion' : 'on_track');
            $p['health_color'] = $h['color'] ?? (self::HEALTH_COLORS[$p['health_status']] ?? '#2563eb');
            $p['days_inactive'] = $h['days_inactive'] ?? 0;

            return $p;
        })->filter(function ($p) {
            return !$p['is_complete'] && $p['required_qty'] > 0;
        })->sortByDesc('weighted_completion')->values();

        $topSubset = ($limit > 0) ? $activeIncomplete->take($limit) : $activeIncomplete;

        return [
            'labels' => $topSubset->pluck('project_code')->toArray(),
            'names' => $topSubset->map(fn($p) => ($p['project_code'] ?: $p['name']) . ' - ' . $p['name'])->toArray(),
            'percentages' => $topSubset->pluck('weighted_completion')->toArray(),
            'required' => $topSubset->pluck('required_qty')->toArray(),
            'received' => $topSubset->pluck('received_qty')->toArray(),
            'pending' => $topSubset->pluck('pending_qty')->toArray(),
            'health_statuses' => $topSubset->pluck('health_status')->toArray(),
            'health_colors' => $topSubset->pluck('health_color')->toArray(),
            'projects' => $topSubset->toArray(),
            'total_active_incomplete' => $activeIncomplete->count(),
        ];
    }

    /**
     * Compute authoritative project health classification map for a collection of projects.
     * Near Completion (>=85% complete) -> green (#16a34a)
     * On Track (active within 7 days) -> blue (#2563eb)
     * At Risk (inactivity 7-14 days) -> yellow (#eab308)
     * Delayed (inactivity > 14 days and <85% complete) -> red (#dc2626)
     *
     * @param Collection $projects
     * @param array $filters
     * @param Collection|null $precomputedBulkMetrics
     * @return array [projectId => ['category' => string, 'color' => string, 'days_inactive' => int, 'completion_pct' => float]]
     */
    public function getProjectHealthMap(Collection $projects, array $filters = [], ?Collection $precomputedBulkMetrics = null): array
    {
        if ($projects->isEmpty()) {
            return [];
        }

        $bulkMetrics = $precomputedBulkMetrics ?? $this->calculateBulkProjectsMetrics($projects, $filters['side'] ?? null, $filters);

        $latestReceiptsQuery = ReceiptItem::query()
            ->join('bom_items', 'bom_items.id', '=', 'receipt_items.bom_item_id')
            ->whereIn('bom_items.project_id', $projects->pluck('id'));

        $latestQcQuery = QcInspection::query()
            ->join('bom_items', 'bom_items.id', '=', 'qc_inspections.bom_item_id')
            ->whereIn('bom_items.project_id', $projects->pluck('id'));

        if (!empty($filters['part_type'])) {
            $pType = strtoupper($filters['part_type']);
            $latestReceiptsQuery->where('bom_items.part_type', $pType);
            $latestQcQuery->where('bom_items.part_type', $pType);
        }

        $latestReceipts = $latestReceiptsQuery
            ->select('bom_items.project_id', DB::raw('MAX(receipt_items.updated_at) as max_updated'))
            ->groupBy('bom_items.project_id')
            ->pluck('max_updated', 'project_id');

        $latestQc = $latestQcQuery
            ->select('bom_items.project_id', DB::raw('MAX(qc_inspections.updated_at) as max_updated'))
            ->groupBy('bom_items.project_id')
            ->pluck('max_updated', 'project_id');

        $healthMap = [];

        foreach ($projects as $proj) {
            $pMetrics = $bulkMetrics->get($proj->id) ?? $this->formatProjectSummaryResult($proj, []);
            $req = $pMetrics['required_qty'] ?? 0;
            $asm = $pMetrics['assembly_completed'] ?? $pMetrics['assembly_qty'] ?? 0;
            $completion = $req > 0 ? min(100, round(($asm / $req) * 100, 1)) : 0.0;

            $recUpdate = $latestReceipts->get($proj->id);
            $qcUpdate = $latestQc->get($proj->id);
            $latestActivity = max($recUpdate, $qcUpdate, $proj->created_at);

            $daysSinceActivity = $latestActivity ? max(0, (int) now()->diffInDays($latestActivity)) : 999;

            if ($completion >= 85.0) {
                $category = 'near_completion';
            } elseif ($daysSinceActivity > 14 && $completion < 85.0) {
                $category = 'delayed';
            } elseif ($daysSinceActivity > 7 && $completion < 85.0) {
                $category = 'at_risk';
            } else {
                $category = 'on_track';
            }

            $healthMap[$proj->id] = [
                'category' => $category,
                'color' => self::HEALTH_COLORS[$category] ?? '#2563eb',
                'days_inactive' => $daysSinceActivity,
                'completion_pct' => $completion,
            ];
        }

        return $healthMap;
    }

    /**
     * Calculate Overall Project Health Distribution for Upper Management.
     * Segments active projects into: Near Completion, On Track, At Risk, Delayed.
     *
     * Weighted Completion Formula:
     *   Numerator:   Sum of all assembly_completed quantities for the project
     *   Denominator: Sum of all required_quantity amounts for the project
     *   Completion%: min(100, round((assembly_completed / required_quantity) * 100, 1))
     *
     * Under "All Types", this aggregates across all MFG + BOP + STD BOM items.
     * Under a specific part_type filter, it filters strictly by that part_type.
     * Zero-required projects are safely handled with 0% completion and excluded.
     *
     * @param array $filters
     * @param Collection|null $precomputedBulkMetrics
     * @return array
     */
    public function calculateProjectHealthDistribution(array $filters = [], ?Collection $precomputedBulkMetrics = null): array
    {
        $projects = Project::where('status', 'active')->get();

        $counts = [
            'near_completion' => 0,
            'on_track' => 0,
            'at_risk' => 0,
            'delayed' => 0,
        ];

        $projectsByHealth = [
            'near_completion' => [],
            'on_track' => [],
            'at_risk' => [],
            'delayed' => [],
        ];

        $bulkMetrics = $precomputedBulkMetrics ?? $this->calculateBulkProjectsMetrics($projects, $filters['side'] ?? null, $filters);
        $healthMap = $this->getProjectHealthMap($projects, $filters, $bulkMetrics);

        foreach ($projects as $proj) {
            $pMetrics = $bulkMetrics->get($proj->id) ?? $this->formatProjectSummaryResult($proj, []);
            $req = $pMetrics['required_qty'] ?? 0;

            if ($req === 0) {
                continue;
            }

            $h = $healthMap[$proj->id] ?? [
                'category' => 'on_track',
                'color' => self::HEALTH_COLORS['on_track'],
                'days_inactive' => 0,
                'completion_pct' => 0.0,
            ];

            $category = $h['category'];
            $counts[$category]++;
            $projectsByHealth[$category][] = [
                'id' => $proj->id,
                'project_code' => $proj->project_code,
                'name' => $proj->name,
                'completion_pct' => $h['completion_pct'],
                'days_inactive' => $h['days_inactive'],
                'category' => $category,
                'color' => $h['color'],
            ];
        }

        $totalActive = array_sum($counts);

        return [
            'counts' => $counts,
            'percentages' => [
                'near_completion' => $totalActive > 0 ? round(($counts['near_completion'] / $totalActive) * 100, 1) : 0,
                'on_track' => $totalActive > 0 ? round(($counts['on_track'] / $totalActive) * 100, 1) : 0,
                'at_risk' => $totalActive > 0 ? round(($counts['at_risk'] / $totalActive) * 100, 1) : 0,
                'delayed' => $totalActive > 0 ? round(($counts['delayed'] / $totalActive) * 100, 1) : 0,
            ],
            'total_active' => $totalActive,
            'details' => $projectsByHealth,
        ];
    }

    /**
     * Format standardized project summary result dictionary.
     */
    protected function formatProjectSummaryResult(Project $proj, array $m): array
    {
        $req = $m['total_required'] ?? $m['required_qty'] ?? 0;
        $rec = $m['total_received'] ?? $m['received_qty'] ?? 0;
        $pending = $m['total_pending'] ?? $m['pending_qty'] ?? max(0, $req - $rec);
        $completion = $m['completion_pct'] ?? ($req > 0 ? min(100, round(($rec / $req) * 100, 1)) : 0);
        $asmComp = $m['assembly_completed'] ?? $m['assembly_qty'] ?? 0;
        $weightedCompletion = $req > 0 ? min(100, round(($asmComp / $req) * 100, 1)) : 0.0;
        $isComplete = ($req > 0 && $asmComp >= $req);

        return [
            'id' => $proj->id,
            'name' => $proj->name,
            'project_code' => $proj->project_code,
            'description' => $proj->description,
            'status' => $proj->status,
            'total_items' => $m['total_items'] ?? 0,
            'total_parts' => $req,
            'total_parts_received' => $rec,
            'parts_pending' => $pending,
            'total_required' => $req,
            'total_received' => $rec,
            'required_qty' => $req,
            'received_qty' => $rec,
            'raw_received' => $m['raw_received'] ?? $rec,
            'excess_received' => $m['excess_received'] ?? 0,
            'pending_qty' => $pending,
            'total_pending' => $pending,
            'parts_in_store' => $m['parts_in_store'] ?? 0,
            'parts_in_qc' => $m['parts_in_qc'] ?? $m['awaiting_qc'] ?? 0,
            'parts_in_rework' => $m['parts_in_rework'] ?? $m['rework_pending'] ?? 0,
            'parts_in_paint' => $m['parts_in_paint'] ?? $m['paint_ready'] ?? 0,
            'parts_in_assembly' => $m['parts_in_assembly'] ?? $m['assembly_ready'] ?? 0,
            'awaiting_qc' => $m['awaiting_qc'] ?? 0,
            'approved_qty' => $m['qc_approved'] ?? $m['approved_qty'] ?? 0,
            'rejected_qty' => $m['qc_rejected'] ?? $m['rejected_qty'] ?? 0,
            'qc_rejected' => $m['qc_rejected'] ?? $m['rejected_qty'] ?? 0,
            'rework_qty' => $m['rework_pending'] ?? $m['rework_qty'] ?? 0,
            'qc_rework' => $m['qc_rework'] ?? 0,
            'paint_ready' => $m['paint_ready'] ?? 0,
            'paint_qty' => $m['paint_completed'] ?? $m['paint_qty'] ?? 0,
            'assembly_ready' => $m['assembly_ready'] ?? 0,
            'assembly_qty' => $asmComp,
            'assembly_completed' => $asmComp,
            'progress_percent' => $completion,
            'completion_pct' => $completion,
            'assembly_completion_pct' => $weightedCompletion,
            'weighted_completion' => $weightedCompletion,
            'is_complete' => $isComplete,
        ];
    }

    /**
     * Get aggregate ECN count for main dashboard without modifying regular calculations.
     */
    public function getEcnTotalForDashboard(array $filters = []): int
    {
        return (new EcnQuantityCalculationService())->getEcnTotalForDashboardCard($filters);
    }
}
