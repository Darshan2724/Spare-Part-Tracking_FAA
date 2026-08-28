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
use App\Models\EcnRequirement;
use App\Models\EcnReceiptItem;
use App\Models\EcnWorkflowRecord;
use App\Services\QuantityCalculationService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class KpiDrilldownService
{
    public function __construct(
        protected QuantityCalculationService $quantityService = new QuantityCalculationService()
    ) {}

    /**
     * Get drill-down items and metadata for a specific KPI.
     *
     * @param string $kpiKey 'active_projects'|'completed_projects'|'delayed_projects'|'total_parts'|'total_parts_received'|'parts_pending'|'store'|'qc'|'rework'|'paint'|'assembly'|'ecn'
     * @param array $filters ['project_id', 'side', 'substate', 'search', 'date_from', 'date_to', 'supplier_id']
     * @param int $page
     * @param int $perPage
     * @return array
     */
    public function getDrilldownData(string $kpiKey, array $filters = [], int $page = 1, int $perPage = 50): array
    {
        $projectId = !empty($filters['project_id']) ? (int) $filters['project_id'] : null;
        $side = !empty($filters['side']) ? $filters['side'] : null;
        $substate = !empty($filters['substate']) ? strtolower(trim($filters['substate'])) : 'all';
        $search = !empty($filters['search']) ? trim($filters['search']) : null;

        // Resolve Project Scope
        $projectScope = 'All Active Projects';
        $selectedProject = null;
        if ($projectId) {
            $selectedProject = Project::find($projectId);
            $projectScope = $selectedProject ? "{$selectedProject->project_code} - {$selectedProject->name}" : 'Selected Project';
        }

        // Branch between Project-level KPIs vs ECN vs Part-level KPIs
        if (in_array($kpiKey, ['active_projects', 'completed_projects', 'delayed_projects'])) {
            return $this->getProjectLevelDrilldown($kpiKey, $filters, $projectScope, $selectedProject, $page, $perPage);
        }

        if ($kpiKey === 'ecn' || str_starts_with($kpiKey, 'ecn_') || !empty($filters['is_ecn']) || in_array($kpiKey, ['total_ecn_parts', 'parts_in_store', 'parts_in_qc', 'parts_in_rework', 'parts_in_paint', 'parts_in_assembly', 'assembly_completed', 'qc_rejected'])) {
            return $this->getEcnKpiDrilldown($kpiKey, $filters, $projectScope, $selectedProject, $page, $perPage);
        }

        return $this->getPartLevelDrilldown($kpiKey, $filters, $projectScope, $selectedProject, $page, $perPage);
    }

    /**
     * Resolve Project-level drilldown datasets (Active, Completed, Delayed).
     */
    protected function getProjectLevelDrilldown(
        string $kpiKey,
        array $filters,
        string $projectScope,
        ?Project $selectedProject,
        int $page,
        int $perPage
    ): array {
        $projectId = $selectedProject?->id;
        $side = $filters['side'] ?? null;
        $search = $filters['search'] ?? null;

        $query = Project::query();

        if ($kpiKey === 'active_projects') {
            $query->where('status', 'active');
        } elseif ($kpiKey === 'completed_projects') {
            $query->where('status', 'completed');
        } elseif ($kpiKey === 'delayed_projects') {
            $query->where('status', 'active')
                ->where('created_at', '<', now()->subDays(14))
                ->whereDoesntHave('bomItems.receiptItems', fn($q) => $q->where('updated_at', '>=', now()->subDays(14)));
        }

        if ($projectId) {
            $query->where('id', $projectId);
        }

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('project_code', 'ILIKE', "%{$search}%")
                  ->orWhere('name', 'ILIKE', "%{$search}%");
            });
        }

        $allProjects = $query->orderBy('project_code')->get();

        $rows = $allProjects->map(function ($proj) use ($side, $filters, $kpiKey) {
            $metrics = $this->quantityService->calculateProjectMetrics($proj, $side, $filters);
            return [
                'id' => $proj->id,
                'project_id' => $proj->id,
                'project_code' => $proj->project_code ?: 'N/A',
                'project_name' => $proj->name,
                'total_parts' => $metrics['required_qty'],
                'total_parts_received' => $metrics['received_qty'],
                'parts_pending' => $metrics['pending_qty'],
                'assembly_completed' => $metrics['assembly_qty'],
                'completion_pct' => $metrics['completion_pct'],
                'status' => $kpiKey === 'delayed_projects' ? 'Delayed (>14 days inactivity)' : ucfirst($proj->status),
                'quantity' => $kpiKey === 'completed_projects' ? $metrics['assembly_qty'] : $metrics['required_qty'],
            ];
        });

        $totalCount = $rows->count();
        $totalQuantity = $rows->sum('quantity');

        // Apply Pagination
        $paginatedRows = $rows->slice(($page - 1) * $perPage, $perPage)->values();

        return [
            'kpi' => $kpiKey,
            'kpi_type' => 'project',
            'project_scope' => $projectScope,
            'is_single_project' => ($projectId !== null),
            'selected_project' => $selectedProject ? [
                'id' => $selectedProject->id,
                'project_code' => $selectedProject->project_code,
                'name' => $selectedProject->name,
                'status' => $selectedProject->status,
            ] : null,
            'total_records' => $totalCount,
            'total_quantity' => $totalQuantity,
            'page' => $page,
            'per_page' => $perPage,
            'total_pages' => (int) ceil($totalCount / max(1, $perPage)),
            'columns' => $this->getColumnsForKpi($kpiKey),
            'data' => $paginatedRows,
            'all_data' => $rows, // Used for full Excel export
        ];
    }

    /**
     * Resolve Part-level drilldown datasets (Total Parts, Total Received, Pending, Store, QC, Rework, Paint, Assembly).
     */
    protected function getPartLevelDrilldown(
        string $kpiKey,
        array $filters,
        string $projectScope,
        ?Project $selectedProject,
        int $page,
        int $perPage
    ): array {
        $projectId = $selectedProject?->id;
        $side = $filters['side'] ?? null;
        $substate = !empty($filters['substate']) ? strtolower(trim($filters['substate'])) : 'all';
        $search = $filters['search'] ?? null;

        // Base projects to query
        $projectsQuery = Project::query();
        if ($projectId) {
            $projectsQuery->where('id', $projectId);
        } else {
            $projectsQuery->where('status', 'active');
        }
        $projects = $projectsQuery->get();
        $projectIds = $projects->pluck('id')->toArray();

        // Query BOM items
        $bomQuery = BomItem::query()
            ->with(['requirements', 'supplier', 'project'])
            ->whereIn('project_id', $projectIds);

        if ($search) {
            $bomQuery->where(function ($q) use ($search) {
                $q->where('standard_part_no', 'ILIKE', "%{$search}%")
                  ->orWhere('item_no', 'ILIKE', "%{$search}%")
                  ->orWhere('jig_no', 'ILIKE', "%{$search}%")
                  ->orWhere('unit_no', 'ILIKE', "%{$search}%")
                  ->orWhereHas('project', fn($pq) => $pq->where('project_code', 'ILIKE', "%{$search}%")->orWhere('name', 'ILIKE', "%{$search}%"))
                  ->orWhereHas('supplier', fn($sq) => $sq->where('name', 'ILIKE', "%{$search}%"));
            });
        }

        $bomItems = $bomQuery->orderBy('jig_no')->orderBy('unit_no')->orderBy('standard_part_no')->get();
        $bomItemIds = $bomItems->pluck('id')->toArray();

        // Load operational records in bulk
        $recQuery = ReceiptItem::query()
            ->whereIn('bom_item_id', $bomItemIds)
            ->whereIn('status', QuantityCalculationService::VALID_RECEIPT_STATUSES);

        $qcQuery = QcInspection::query()->whereIn('bom_item_id', $bomItemIds);
        $reworkQuery = ReworkRecord::query()->whereIn('bom_item_id', $bomItemIds);
        $paintQuery = PaintRecord::query()->whereIn('bom_item_id', $bomItemIds);
        $asmQuery = AssemblyRecord::query()->whereIn('bom_item_id', $bomItemIds);

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

        $rows = new Collection();

        foreach ($bomItems as $item) {
            $itemReceipts = $receiptsGrouped->get($item->id, collect());
            $itemQc = $qcGrouped->get($item->id, collect());
            $itemRework = $reworkGrouped->get($item->id, collect());
            $itemPaint = $paintGrouped->get($item->id, collect());
            $itemAsm = $asmGrouped->get($item->id, collect());

            // Check requirements per side
            $reqs = $item->requirements;
            if ($reqs->isEmpty()) {
                continue;
            }

            foreach ($reqs as $req) {
                $curSide = $req->side;
                if ($side && $curSide !== $side && $curSide !== 'COMMON') {
                    continue;
                }

                $reqQty = (int) $req->required_quantity;
                if ($reqQty <= 0) {
                    continue;
                }

                // Filter side-specific records
                $sideReceipts = $itemReceipts->filter(fn($r) => $r->side === $curSide || $r->side === 'COMMON');
                $sideQc = $itemQc->filter(fn($q) => $q->side === $curSide || $q->side === 'COMMON');
                $sideRework = $itemRework->filter(fn($rw) => $rw->side === $curSide || $rw->side === 'COMMON');
                $sidePaint = $itemPaint->filter(fn($p) => $p->side === $curSide || $p->side === 'COMMON');
                $sideAsm = $itemAsm->filter(fn($a) => $a->side === $curSide || $a->side === 'COMMON');

                // Compute canonical metrics for this part + side
                $rawReceived = (int) $sideReceipts->sum('received_quantity');
                $canonicalReceived = min($rawReceived, $reqQty);
                $canonicalPending = max(0, $reqQty - $canonicalReceived);

                $storeQty = (int) $sideReceipts->whereIn('status', ['received', 'returned_to_store'])->sum('received_quantity');
                $qcInspectionQty = (int) $sideReceipts->whereIn('status', ['sent_to_qc', 'qc_received'])->sum('received_quantity');
                $qcRejectedQty = (int) $sideQc->sum('rejected_quantity');
                $reworkActiveQty = (int) $sideRework->whereIn('status', ['pending', 'in_progress'])->sum('quantity');
                
                $paintApprovedQty = (int) $sideQc->where('approved_quantity', '>', 0)->filter(fn($q) => $q->destination === 'PAINT' || empty($q->destination))->sum('approved_quantity');
                $paintCompletedQty = (int) $sidePaint->sum('quantity');
                $paintActiveQty = max(0, $paintApprovedQty - $paintCompletedQty);

                $directAsmQty = (int) $sideQc->where('approved_quantity', '>', 0)->filter(fn($q) => $q->destination === 'ASSEMBLY')->sum('approved_quantity');
                $asmTotalIncoming = $paintCompletedQty + $directAsmQty;
                $asmCompletedQty = (int) $sideAsm->sum('quantity');
                $asmActiveQty = max(0, $asmTotalIncoming - $asmCompletedQty);

                $sideChar = match (strtoupper($curSide)) {
                    'RH', 'R' => 'R',
                    'LH', 'L' => 'L',
                    default => '',
                };
                $excelPartNumber = "{$item->jig_no}{$item->unit_no}{$item->standard_part_no}{$sideChar}";

                $baseRow = [
                    'project_id' => $item->project_id,
                    'project_code' => $item->project?->project_code ?: 'N/A',
                    'project_name' => $item->project?->name ?: 'N/A',
                    'jig_no' => $item->jig_no ?: '00',
                    'unit_no' => $item->unit_no ?: '00',
                    'part_no' => $item->standard_part_no,
                    'item_no' => $item->item_no,
                    'side' => $curSide,
                    'combined_identifier' => "{$item->jig_no} / {$item->unit_no} / {$item->standard_part_no} / {$curSide}",
                    'excel_part_number' => $excelPartNumber,
                    'supplier' => $item->supplier?->name ?? $item->supplier_name_raw ?? 'Standard',
                ];

                // Append rows based on clicked KPI
                if ($kpiKey === 'total_parts') {
                    $rows->push(array_merge($baseRow, [
                        'id' => "total_parts_{$item->id}_{$curSide}",
                        'status' => 'BOM Required',
                        'quantity' => $reqQty,
                        'substate' => 'required',
                    ]));
                } elseif ($kpiKey === 'total_parts_received' && $canonicalReceived > 0) {
                    $rows->push(array_merge($baseRow, [
                        'id' => "rec_{$item->id}_{$curSide}",
                        'status' => 'Store Received',
                        'quantity' => $canonicalReceived,
                        'substate' => 'received',
                    ]));
                } elseif ($kpiKey === 'parts_pending' && $canonicalPending > 0) {
                    $rows->push(array_merge($baseRow, [
                        'id' => "pen_{$item->id}_{$curSide}",
                        'status' => 'Pending Store Receipt',
                        'quantity' => $canonicalPending,
                        'substate' => 'pending',
                    ]));
                } elseif ($kpiKey === 'store' && $storeQty > 0) {
                    $rows->push(array_merge($baseRow, [
                        'id' => "store_{$item->id}_{$curSide}",
                        'status' => 'In Store Bay',
                        'quantity' => $storeQty,
                        'substate' => 'store',
                    ]));
                } elseif ($kpiKey === 'qc') {
                    if (($substate === 'all' || $substate === 'inspection') && $qcInspectionQty > 0) {
                        $rows->push(array_merge($baseRow, [
                            'id' => "qc_insp_{$item->id}_{$curSide}",
                            'status' => 'QC Inspection Queue',
                            'quantity' => $qcInspectionQty,
                            'substate' => 'inspection',
                        ]));
                    }
                    if (($substate === 'all' || $substate === 'rejected') && $qcRejectedQty > 0) {
                        $rows->push(array_merge($baseRow, [
                            'id' => "qc_rej_{$item->id}_{$curSide}",
                            'status' => 'QC Rejected',
                            'quantity' => $qcRejectedQty,
                            'substate' => 'rejected',
                        ]));
                    }
                } elseif ($kpiKey === 'rework' && $reworkActiveQty > 0) {
                    $rows->push(array_merge($baseRow, [
                        'id' => "rework_{$item->id}_{$curSide}",
                        'status' => 'In Rework Queue',
                        'quantity' => $reworkActiveQty,
                        'substate' => 'rework',
                    ]));
                } elseif ($kpiKey === 'paint' && $paintActiveQty > 0) {
                    $rows->push(array_merge($baseRow, [
                        'id' => "paint_{$item->id}_{$curSide}",
                        'status' => 'In Paint Queue',
                        'quantity' => $paintActiveQty,
                        'substate' => 'paint',
                    ]));
                } elseif ($kpiKey === 'assembly') {
                    if (($substate === 'all' || $substate === 'queue') && $asmActiveQty > 0) {
                        $rows->push(array_merge($baseRow, [
                            'id' => "asm_queue_{$item->id}_{$curSide}",
                            'status' => 'In Assembly Queue',
                            'quantity' => $asmActiveQty,
                            'substate' => 'queue',
                        ]));
                    }
                    if (($substate === 'all' || $substate === 'completed') && $asmCompletedQty > 0) {
                        $rows->push(array_merge($baseRow, [
                            'id' => "asm_comp_{$item->id}_{$curSide}",
                            'status' => 'Assembly Completed',
                            'quantity' => $asmCompletedQty,
                            'substate' => 'completed',
                        ]));
                    }
                }
            }
        }

        $totalCount = $rows->count();
        $totalQuantity = (int) $rows->sum('quantity');

        // Apply Pagination
        $paginatedRows = $rows->slice(($page - 1) * $perPage, $perPage)->values();

        return [
            'kpi' => $kpiKey,
            'kpi_type' => 'part',
            'project_scope' => $projectScope,
            'is_single_project' => ($projectId !== null),
            'selected_project' => $selectedProject ? [
                'id' => $selectedProject->id,
                'project_code' => $selectedProject->project_code,
                'name' => $selectedProject->name,
                'status' => $selectedProject->status,
            ] : null,
            'substate' => $substate,
            'total_records' => $totalCount,
            'total_quantity' => $totalQuantity,
            'page' => $page,
            'per_page' => $perPage,
            'total_pages' => (int) ceil($totalCount / max(1, $perPage)),
            'columns' => $this->getColumnsForKpi($kpiKey),
            'data' => $paginatedRows,
            'all_data' => $rows, // Used for full Excel export
        ];
    }

    /**
     * Resolve ECN KPI drilldown dataset specifically for each operational KPI.
     */
    protected function getEcnKpiDrilldown(
        string $kpiKey,
        array $filters,
        string $projectScope,
        ?Project $selectedProject,
        int $page,
        int $perPage
    ): array {
        $projectId = $selectedProject?->id;
        $side = $filters['side'] ?? null;
        $substate = !empty($filters['substate']) ? strtolower(trim($filters['substate'])) : 'all';
        $search = $filters['search'] ?? null;

        // Base requirements query
        $reqQuery = EcnRequirement::query()->with(['project']);
        if ($projectId) {
            $reqQuery->where('project_id', $projectId);
        }
        if ($side) {
            $sUpper = strtoupper(trim($side));
            $reqQuery->where(function ($q) use ($sUpper) {
                $q->where('side', $sUpper)->orWhere('side_display', $sUpper);
            });
        }
        if ($search) {
            $reqQuery->where(function ($q) use ($search) {
                $q->where('part_no', 'ILIKE', "%{$search}%")
                  ->orWhere('jig_no', 'ILIKE', "%{$search}%")
                  ->orWhere('unit_no', 'ILIKE', "%{$search}%")
                  ->orWhere('ecn_number', 'ILIKE', "%{$search}%")
                  ->orWhereHas('project', fn($pq) => $pq->where('project_code', 'ILIKE', "%{$search}%")->orWhere('name', 'ILIKE', "%{$search}%"));
            });
        }
        if (!empty($filters['date_from'])) {
            $reqQuery->where('created_at', '>=', $filters['date_from']);
        }
        if (!empty($filters['date_to'])) {
            $reqQuery->where('created_at', '<=', $filters['date_to']);
        }

        $allReqs = $reqQuery->orderBy('project_id')->orderBy('ecn_number')->orderBy('jig_no')->orderBy('unit_no')->orderBy('part_no')->get();
        $reqIds = $allReqs->pluck('id')->toArray();

        // Load receipts and workflow records in bulk
        $receiptQuery = EcnReceiptItem::query()->whereIn('ecn_requirement_id', $reqIds);
        $wfQuery = EcnWorkflowRecord::query()->whereIn('ecn_requirement_id', $reqIds);

        if (!empty($filters['date_from'])) {
            $receiptQuery->where('created_at', '>=', $filters['date_from']);
            $wfQuery->where('created_at', '>=', $filters['date_from']);
        }
        if (!empty($filters['date_to'])) {
            $receiptQuery->where('created_at', '<=', $filters['date_to']);
            $wfQuery->where('created_at', '<=', $filters['date_to']);
        }

        $receiptsGrouped = $receiptQuery->get()->groupBy('ecn_requirement_id');
        $wfGrouped = $wfQuery->get()->groupBy('ecn_requirement_id');

        $rows = new Collection();

        // Normalize KPI key
        $normKpi = match ($kpiKey) {
            'ecn_total_parts', 'total_ecn_parts', 'total_parts', 'ecn' => 'total_parts',
            'ecn_total_parts_received', 'total_received', 'total_parts_received' => 'total_parts_received',
            'ecn_parts_pending', 'parts_pending' => 'parts_pending',
            'ecn_store', 'parts_in_store', 'store' => 'store',
            'ecn_qc', 'parts_in_qc', 'qc' => 'qc',
            'qc_rejected' => 'qc_rejected',
            'ecn_rework', 'parts_in_rework', 'rework' => 'rework',
            'ecn_paint', 'parts_in_paint', 'paint' => 'paint',
            'ecn_assembly', 'parts_in_assembly', 'assembly' => 'assembly',
            'assembly_completed' => 'assembly_completed',
            default => 'total_parts',
        };

        foreach ($allReqs as $req) {
            $reqReceipts = $receiptsGrouped->get($req->id, collect());
            $reqWf = $wfGrouped->get($req->id, collect());

            $reqQty = (int) $req->required_qty;
            $receivedQty = (int) $reqReceipts->whereIn('status', EcnQuantityCalculationService::VALID_RECEIPT_STATUSES)->sum('received_quantity');
            $pendingQty = max(0, $reqQty - $receivedQty);

            $storeQty = (int) $reqReceipts->whereIn('status', ['received', 'store_received'])->sum('received_quantity');
            $qcInspectionQty = (int) $reqReceipts->whereIn('status', ['sent_to_qc', 'qc_received'])->sum('received_quantity');
            $qcRejectedQty = (int) $reqWf->where('department', 'QC')->where('action', 'qc_rejected')->sum('rejected_quantity');
            $reworkActiveQty = (int) $reqWf->where('department', 'REWORK')->where('status', 'in_progress')->sum('quantity');
            $paintActiveQty = (int) $reqWf->where('department', 'PAINT')->where('status', 'in_progress')->sum('quantity');
            $asmActiveQty = (int) $reqWf->where('department', 'ASSEMBLY')->where('status', 'in_progress')->sum('quantity');
            $asmCompletedQty = (int) $reqWf->where('department', 'ASSEMBLY')->where('action', 'assembly_completed')->sum('quantity');

            $sideChar = match (strtoupper($req->side)) {
                'LA', 'AL', 'L', 'LH' => 'L',
                'RA', 'AR', 'R', 'RH' => 'R',
                default => '',
            };
            $excelPartNumber = "{$req->jig_no}{$req->unit_no}{$req->part_no}{$sideChar}";

            $baseRow = [
                'ecn_id' => $req->id,
                'ecn_number' => $req->ecn_number,
                'is_ecn' => true,
                'classification' => 'ECN',
                'project_id' => $req->project_id,
                'project_code' => $req->project?->project_code ?: 'N/A',
                'project_name' => $req->project?->name ?: 'N/A',
                'jig_no' => $req->jig_no ?: '00',
                'unit_no' => $req->unit_no ?: '00',
                'part_no' => $req->part_no,
                'part_number' => $req->part_no,
                'side' => $req->side,
                'source_side' => $req->side,
                'display_side' => $req->side,
                'original_side' => $req->side,
                'normalized_side' => $req->side_display,
                'combined_identifier' => "{$req->ecn_number} | {$req->jig_no} | Unit {$req->unit_no} | {$req->part_no} | {$req->side}",
                'excel_part_number' => $excelPartNumber,
                'required_qty' => $reqQty,
                'received_qty' => $receivedQty,
                'pending_qty' => $pendingQty,
            ];

            if ($normKpi === 'total_parts') {
                $rows->push(array_merge($baseRow, [
                    'id' => "ecn_total_{$req->id}",
                    'status' => "ECN Required ({$req->current_state})",
                    'quantity' => $reqQty,
                    'substate' => 'required',
                ]));
            } elseif ($normKpi === 'total_parts_received' && $receivedQty > 0) {
                $rows->push(array_merge($baseRow, [
                    'id' => "ecn_rec_{$req->id}",
                    'status' => 'ECN Received',
                    'quantity' => $receivedQty,
                    'substate' => 'received',
                ]));
            } elseif ($normKpi === 'parts_pending' && $pendingQty > 0) {
                $rows->push(array_merge($baseRow, [
                    'id' => "ecn_pen_{$req->id}",
                    'status' => 'Pending Store Intake',
                    'quantity' => $pendingQty,
                    'substate' => 'pending',
                ]));
            } elseif ($normKpi === 'store' && $storeQty > 0) {
                $rows->push(array_merge($baseRow, [
                    'id' => "ecn_store_{$req->id}",
                    'status' => 'In Store',
                    'quantity' => $storeQty,
                    'substate' => 'store',
                ]));
            } elseif ($normKpi === 'qc') {
                if (($substate === 'all' || $substate === 'inspection') && $qcInspectionQty > 0) {
                    $rows->push(array_merge($baseRow, [
                        'id' => "ecn_qc_insp_{$req->id}",
                        'status' => 'QC Inspection Queue',
                        'quantity' => $qcInspectionQty,
                        'substate' => 'inspection',
                    ]));
                }
                if (($substate === 'all' || $substate === 'rejected') && $qcRejectedQty > 0) {
                    $rows->push(array_merge($baseRow, [
                        'id' => "ecn_qc_rej_{$req->id}",
                        'status' => 'QC Rejected',
                        'quantity' => $qcRejectedQty,
                        'substate' => 'rejected',
                    ]));
                }
            } elseif ($normKpi === 'qc_rejected' && $qcRejectedQty > 0) {
                $rows->push(array_merge($baseRow, [
                    'id' => "ecn_qc_rej_{$req->id}",
                    'status' => 'QC Rejected',
                    'quantity' => $qcRejectedQty,
                    'substate' => 'rejected',
                ]));
            } elseif ($normKpi === 'rework' && $reworkActiveQty > 0) {
                $rows->push(array_merge($baseRow, [
                    'id' => "ecn_rework_{$req->id}",
                    'status' => 'In Rework Queue',
                    'quantity' => $reworkActiveQty,
                    'substate' => 'rework',
                ]));
            } elseif ($normKpi === 'paint' && $paintActiveQty > 0) {
                $rows->push(array_merge($baseRow, [
                    'id' => "ecn_paint_{$req->id}",
                    'status' => 'In Paint Shop',
                    'quantity' => $paintActiveQty,
                    'substate' => 'paint',
                ]));
            } elseif ($normKpi === 'assembly') {
                if (($substate === 'all' || $substate === 'queue') && $asmActiveQty > 0) {
                    $rows->push(array_merge($baseRow, [
                        'id' => "ecn_asm_queue_{$req->id}",
                        'status' => 'In Assembly Queue',
                        'quantity' => $asmActiveQty,
                        'substate' => 'queue',
                    ]));
                }
                if (($substate === 'all' || $substate === 'completed') && $asmCompletedQty > 0) {
                    $rows->push(array_merge($baseRow, [
                        'id' => "ecn_asm_comp_{$req->id}",
                        'status' => 'Assembly Completed',
                        'quantity' => $asmCompletedQty,
                        'substate' => 'completed',
                    ]));
                }
            } elseif ($normKpi === 'assembly_completed' && $asmCompletedQty > 0) {
                $rows->push(array_merge($baseRow, [
                    'id' => "ecn_asm_comp_{$req->id}",
                    'status' => 'Assembly Completed',
                    'quantity' => $asmCompletedQty,
                    'substate' => 'completed',
                ]));
            }
        }

        $totalCount = $rows->count();
        $totalQuantity = (int)$rows->sum('quantity');
        $paginatedRows = $rows->slice(($page - 1) * $perPage, $perPage)->values();

        return [
            'kpi' => $normKpi,
            'kpi_type' => 'part',
            'is_ecn' => true,
            'project_scope' => $projectScope,
            'is_single_project' => ($projectId !== null),
            'selected_project' => $selectedProject ? [
                'id' => $selectedProject->id,
                'project_code' => $selectedProject->project_code,
                'name' => $selectedProject->name,
                'status' => $selectedProject->status,
            ] : null,
            'substate' => $substate,
            'total_records' => $totalCount,
            'total_quantity' => $totalQuantity,
            'page' => $page,
            'per_page' => $perPage,
            'total_pages' => (int) ceil($totalCount / max(1, $perPage)),
            'columns' => $this->getColumnsForKpi('ecn'),
            'data' => $paginatedRows,
            'all_data' => $rows,
        ];
    }

    /**
     * Get table column configuration for UI and Excel export.
     */
    public function getColumnsForKpi(string $kpiKey): array
    {
        if (in_array($kpiKey, ['active_projects', 'completed_projects', 'delayed_projects'])) {
            return [
                ['label' => 'Project Code', 'key' => 'project_code', 'width' => '15%'],
                ['label' => 'Project Name', 'key' => 'project_name', 'width' => '25%'],
                ['label' => 'Total Parts', 'key' => 'total_parts', 'width' => '12%', 'align' => 'center'],
                ['label' => 'Total Received', 'key' => 'total_parts_received', 'width' => '12%', 'align' => 'center'],
                ['label' => 'Parts Pending', 'key' => 'parts_pending', 'width' => '12%', 'align' => 'center'],
                ['label' => 'Completion %', 'key' => 'completion_pct', 'width' => '12%', 'align' => 'center'],
                ['label' => 'Status', 'key' => 'status', 'width' => '12%', 'align' => 'center'],
            ];
        }

        if ($kpiKey === 'ecn' || str_starts_with($kpiKey, 'ecn_')) {
            return [
                ['label' => 'ECN NO', 'key' => 'ecn_number', 'width' => '10%'],
                ['label' => 'Project', 'key' => 'project_code', 'width' => '10%'],
                ['label' => 'Jig No', 'key' => 'jig_no', 'width' => '10%'],
                ['label' => 'Unit No', 'key' => 'unit_no', 'width' => '8%', 'align' => 'center'],
                ['label' => 'Part Number', 'key' => 'part_no', 'width' => '14%'],
                ['label' => 'Side', 'key' => 'side', 'width' => '6%', 'align' => 'center'],
                ['label' => 'Combined Identifier', 'key' => 'combined_identifier', 'width' => '22%'],
                ['label' => 'Status', 'key' => 'status', 'width' => '12%'],
                ['label' => 'Quantity', 'key' => 'quantity', 'width' => '8%', 'align' => 'center'],
            ];
        }

        return [
            ['label' => 'Project', 'key' => 'project_code', 'width' => '10%'],
            ['label' => 'Jig No', 'key' => 'jig_no', 'width' => '10%'],
            ['label' => 'Unit No', 'key' => 'unit_no', 'width' => '8%', 'align' => 'center'],
            ['label' => 'Part No', 'key' => 'part_no', 'width' => '16%'],
            ['label' => 'Side', 'key' => 'side', 'width' => '6%', 'align' => 'center'],
            ['label' => 'Combined Identifier', 'key' => 'combined_identifier', 'width' => '22%'],
            ['label' => 'Status', 'key' => 'status', 'width' => '16%'],
            ['label' => 'Quantity', 'key' => 'quantity', 'width' => '10%', 'align' => 'center'],
        ];
    }
}
