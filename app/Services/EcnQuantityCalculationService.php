<?php

namespace App\Services;

use App\Models\EcnRequirement;
use App\Models\EcnReceiptItem;
use App\Models\EcnWorkflowRecord;
use App\Models\Project;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class EcnQuantityCalculationService
{
    public const VALID_RECEIPT_STATUSES = [
        'received',
        'store_received',
        'pending_qc',
        'sent_to_qc',
        'qc_received',
        'qc_approved',
        'qc_rejected',
        'qc_rework',
        'paint_completed',
        'assembly_completed',
    ];

    /**
     * Get single aggregate ECN count for main dashboard KPI card.
     */
    public function getEcnTotalForDashboardCard(array $filters = []): int
    {
        $query = EcnRequirement::query();

        if (!empty($filters['project_id'])) {
            $query->where('project_id', (int)$filters['project_id']);
        }

        if (!empty($filters['side'])) {
            $side = strtoupper(trim($filters['side']));
            $query->where(function ($q) use ($side) {
                $q->where('side', $side)
                  ->orWhere('side_display', $side);
            });
        }

        return (int)$query->sum('required_qty');
    }

    /**
     * Calculate 9 executive KPI cards for dedicated ECN Reports page.
     */
    public function calculateEcnDashboardSummary(array $filters = []): array
    {
        $reqQuery = EcnRequirement::query();
        $receiptQuery = EcnReceiptItem::query()->whereIn('status', self::VALID_RECEIPT_STATUSES);
        $wfQuery = EcnWorkflowRecord::query();

        if (!empty($filters['project_id'])) {
            $projId = (int)$filters['project_id'];
            $reqQuery->where('project_id', $projId);
            $receiptQuery->where('project_id', $projId);
            $wfQuery->where('project_id', $projId);
        }

        if (!empty($filters['ecn_number'])) {
            $ecnNum = trim($filters['ecn_number']);
            $reqQuery->where('ecn_number', $ecnNum);
            $receiptQuery->where('ecn_number', $ecnNum);
            $wfQuery->where('ecn_number', $ecnNum);
        }

        if (!empty($filters['jig_no'])) {
            $jig = trim($filters['jig_no']);
            $reqQuery->where('jig_no', $jig);
        }

        if (!empty($filters['unit_no'])) {
            $unit = trim($filters['unit_no']);
            $reqQuery->where('unit_no', $unit);
        }

        if (!empty($filters['side'])) {
            $side = strtoupper(trim($filters['side']));
            $reqQuery->where(function ($q) use ($side) {
                $q->where('side', $side)->orWhere('side_display', $side);
            });
            $receiptQuery->where(function ($q) use ($side) {
                $q->where('side', $side)->orWhere('side_display', $side);
            });
            $wfQuery->where(function ($q) use ($side) {
                $q->where('side', $side)->orWhere('side_display', $side);
            });
        }

        if (!empty($filters['date_from'])) {
            $reqQuery->where('created_at', '>=', $filters['date_from']);
            $receiptQuery->where('created_at', '>=', $filters['date_from']);
            $wfQuery->where('created_at', '>=', $filters['date_from']);
        }

        if (!empty($filters['date_to'])) {
            $reqQuery->where('created_at', '<=', $filters['date_to']);
            $receiptQuery->where('created_at', '<=', $filters['date_to']);
            $wfQuery->where('created_at', '<=', $filters['date_to']);
        }

        $totalParts = (int)$reqQuery->sum('required_qty');
        $totalReceived = (int)$receiptQuery->sum('received_quantity');
        $partsPending = max(0, $totalParts - $totalReceived);

        // Store Bay (received in store, not yet sent to QC)
        $storeItems = (clone $receiptQuery)->whereIn('status', ['received', 'store_received'])->sum('received_quantity');

        // QC Queue (sent to QC, awaiting inspection)
        $qcItems = (clone $receiptQuery)->whereIn('status', ['sent_to_qc', 'qc_received'])->sum('received_quantity');

        // QC Rejected
        $qcRejected = (int)(clone $wfQuery)->where('department', 'QC')->where('action', 'qc_rejected')->sum('rejected_quantity');

        // Rework Queue
        $reworkActive = (int)(clone $wfQuery)->where('department', 'REWORK')->where('status', 'in_progress')->sum('quantity');

        // Paint Shop Queue
        $paintActive = (int)(clone $wfQuery)->where('department', 'PAINT')->where('status', 'in_progress')->sum('quantity');

        // Assembly Queue
        $assemblyActive = (int)(clone $wfQuery)->where('department', 'ASSEMBLY')->where('status', 'in_progress')->sum('quantity');

        // Assembly Completed
        $assemblyCompleted = (int)(clone $wfQuery)->where('department', 'ASSEMBLY')->where('action', 'assembly_completed')->sum('quantity');

        $completionPct = $totalParts > 0 ? round(($assemblyCompleted / $totalParts) * 100, 1) : 0;

        return [
            'total_parts' => $totalParts,
            'total_received' => $totalReceived,
            'total_parts_received' => $totalReceived,
            'parts_pending' => $partsPending,
            'parts_in_store' => (int)$storeItems,
            'parts_in_qc' => (int)$qcItems,
            'qc_rejected' => $qcRejected,
            'parts_in_rework' => $reworkActive,
            'parts_in_paint' => $paintActive,
            'parts_in_assembly' => $assemblyActive,
            'assembly_completed' => $assemblyCompleted,
            'completion_pct' => $completionPct,
        ];
    }

    /**
     * Get ECN counts for hierarchy cards at any level.
     */
    public function getEcnCountsForHierarchy(?int $projectId = null, ?string $jigNo = null, ?string $unitNo = null, ?string $sideDisplay = null): int
    {
        if (!$projectId) {
            return (int)EcnRequirement::sum('required_qty');
        }

        $query = EcnRequirement::where('project_id', $projectId);

        if ($jigNo !== null) {
            $query->where('jig_no', $jigNo);
        }

        if ($unitNo !== null) {
            $query->where('unit_no', $unitNo);
        }

        if ($sideDisplay !== null) {
            $side = strtoupper(trim($sideDisplay));
            $query->where(function ($q) use ($side) {
                $q->where('side_display', $side)->orWhere('side', $side);
            });
        }

        return (int)$query->sum('required_qty');
    }

    /**
     * Bulk preloader for project ECN hierarchy counts to prevent N+1 queries.
     */
    public function preloadProjectEcnHierarchyMap(int $projectId): array
    {
        $reqs = EcnRequirement::where('project_id', $projectId)
            ->select('jig_no', 'unit_no', 'side_display', DB::raw('SUM(required_qty) as total_qty'))
            ->groupBy('jig_no', 'unit_no', 'side_display')
            ->get();

        $map = [
            'project_total' => 0,
            'jigs' => [],
            'units' => [],
            'sides' => [],
        ];

        foreach ($reqs as $r) {
            $qty = (int)$r->total_qty;
            $map['project_total'] += $qty;

            // Jig
            $map['jigs'][$r->jig_no] = ($map['jigs'][$r->jig_no] ?? 0) + $qty;

            // Unit (key: jig|unit)
            $unitKey = $r->jig_no . '|' . $r->unit_no;
            $map['units'][$unitKey] = ($map['units'][$unitKey] ?? 0) + $qty;

            // Side (key: jig|unit|side)
            $sideKey = $r->jig_no . '|' . $r->unit_no . '|' . $r->side_display;
            $map['sides'][$sideKey] = ($map['sides'][$sideKey] ?? 0) + $qty;
        }

        return $map;
    }

    /**
     * Build ECN hierarchy breakdown for ECN Reports page.
     */
    public function getEcnHierarchy(?int $projectId = null, array $filters = []): array
    {
        $projects = Project::orderBy('name')->get();
        $activeProjects = $projects->where('status', 'active')->values();
        $completedProjects = $projects->where('status', 'completed')->values();

        if (!$projectId) {
            return [
                'is_hierarchical' => false,
                'projects' => $projects,
                'active_projects' => $activeProjects,
                'completed_projects' => $completedProjects,
                'summary' => $this->calculateEcnDashboardSummary($filters),
            ];
        }

        $project = Project::find($projectId);
        if (!$project) {
            return [
                'is_hierarchical' => false,
                'projects' => $projects,
                'active_projects' => $activeProjects,
                'completed_projects' => $completedProjects,
                'summary' => $this->calculateEcnDashboardSummary($filters),
            ];
        }

        $query = EcnRequirement::where('project_id', $projectId)
            ->with(['importBatch', 'receiptItems']);

        if (!empty($filters['ecn_number'])) {
            $query->where('ecn_number', trim($filters['ecn_number']));
        }
        if (!empty($filters['jig_no'])) {
            $query->where('jig_no', trim($filters['jig_no']));
        }
        if (!empty($filters['unit_no'])) {
            $query->where('unit_no', trim($filters['unit_no']));
        }
        if (!empty($filters['side'])) {
            $side = strtoupper(trim($filters['side']));
            $query->where(function ($q) use ($side) {
                $q->where('side', $side)->orWhere('side_display', $side);
            });
        }

        $ecnReqs = $query->orderBy('ecn_number')->orderBy('jig_no')->orderBy('unit_no')->orderBy('part_no')->get();

        // Group into ECN -> Jig -> Unit -> Parts
        $ecnGroups = $ecnReqs->groupBy('ecn_number');
        $hierarchyNodes = [];

        foreach ($ecnGroups as $ecnNum => $reqsInEcn) {
            $jigGroups = $reqsInEcn->groupBy('jig_no');
            $jigNodes = [];

            foreach ($jigGroups as $jigNo => $reqsInJig) {
                $unitGroups = $reqsInJig->groupBy('unit_no');
                $unitNodes = [];

                foreach ($unitGroups as $unitNo => $reqsInUnit) {
                    $parts = $reqsInUnit->map(function ($req) {
                        return [
                            'id' => $req->id,
                            'is_ecn' => true,
                            'classification' => 'ECN',
                            'ecn_number' => $req->ecn_number,
                            'jig_no' => $req->jig_no,
                            'unit_no' => $req->unit_no,
                            'part_no' => $req->part_no,
                            'side' => $req->side,
                            'original_side' => $req->side,
                            'source_side' => $req->side,
                            'side_display' => $req->side,
                            'normalized_side' => $req->side_display,
                            'combined_identifier' => "{$req->ecn_number} | {$req->jig_no} | Unit {$req->unit_no} | Part {$req->part_no} | {$req->side}",
                            'required_qty' => $req->required_qty,
                            'received_qty' => $req->received_qty,
                            'current_state' => $req->current_state,
                            'status' => $req->current_state,
                        ];
                    })->values()->toArray();

                    $unitNodes[] = [
                        'unit_no' => $unitNo,
                        'total_required' => $reqsInUnit->sum('required_qty'),
                        'total_received' => $reqsInUnit->sum('received_qty'),
                        'parts_count' => count($parts),
                        'parts' => $parts,
                    ];
                }

                $jigNodes[] = [
                    'jig_no' => $jigNo,
                    'total_required' => $reqsInJig->sum('required_qty'),
                    'total_received' => $reqsInJig->sum('received_qty'),
                    'units' => $unitNodes,
                ];
            }

            $hierarchyNodes[] = [
                'ecn_number' => $ecnNum,
                'total_required' => $reqsInEcn->sum('required_qty'),
                'total_received' => $reqsInEcn->sum('received_qty'),
                'jigs' => $jigNodes,
            ];
        }

        return [
            'is_hierarchical' => true,
            'project' => $project,
            'summary' => $this->calculateEcnDashboardSummary(array_merge($filters, ['project_id' => $projectId])),
            'ecn_nodes' => $hierarchyNodes,
        ];
    }
}
