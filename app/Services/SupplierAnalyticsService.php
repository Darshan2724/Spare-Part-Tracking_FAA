<?php

namespace App\Services;

use App\Models\Supplier;
use App\Models\SupplierAssignment;
use App\Models\SupplierAssignmentHistory;
use App\Models\Project;
use App\Models\BomItem;
use App\Models\ReworkRecord;
use App\Models\ReceiptItem;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class SupplierAnalyticsService
{
    /**
     * Get aggregate KPIs for Supplier Management Overview.
     */
    public function getKpis(array $filters = []): array
    {
        $assignmentQuery = SupplierAssignment::query()->where('status', 'active');
        $this->applyAssignmentFilters($assignmentQuery, $filters);

        // Active assignments stats
        $totalActiveAssignments = (clone $assignmentQuery)->count();
        $suppliersInUse = (clone $assignmentQuery)->distinct('supplier_id')->count('supplier_id');
        $projectsWithAlloc = (clone $assignmentQuery)->distinct('project_id')->count('project_id');

        $jigsWithAlloc = (clone $assignmentQuery)
            ->selectRaw('COUNT(DISTINCT (project_id, jig_no)) as cnt')
            ->value('cnt') ?? 0;

        $unitsWithAlloc = (clone $assignmentQuery)
            ->selectRaw('COUNT(DISTINCT (project_id, jig_no, unit_no)) as cnt')
            ->value('cnt') ?? 0;

        // Supplier Master stats
        $supplierQuery = Supplier::query();
        if (!empty($filters['supplier_id'])) {
            $supplierQuery->where('id', $filters['supplier_id']);
        }
        $totalSuppliers = (clone $supplierQuery)->count();
        $activeSuppliers = (clone $supplierQuery)->where('is_active', true)->count();

        // Suppliers with Rework (join through BOM items)
        $reworkSuppliersQuery = DB::table('rework_records as rr')
            ->join('bom_items as bi', 'rr.bom_item_id', '=', 'bi.id')
            ->whereNotNull('bi.supplier_id');

        if (!empty($filters['project_id'])) {
            $reworkSuppliersQuery->where('bi.project_id', $filters['project_id']);
        }
        if (!empty($filters['supplier_id'])) {
            $reworkSuppliersQuery->where('bi.supplier_id', $filters['supplier_id']);
        }
        if (!empty($filters['date_from'])) {
            $reworkSuppliersQuery->whereDate('rr.created_at', '>=', $filters['date_from']);
        }
        if (!empty($filters['date_to'])) {
            $reworkSuppliersQuery->whereDate('rr.created_at', '<=', $filters['date_to']);
        }
        $suppliersWithRework = $reworkSuppliersQuery->distinct('bi.supplier_id')->count('bi.supplier_id');

        return [
            'total_suppliers' => (int) $totalSuppliers,
            'active_suppliers' => (int) $activeSuppliers,
            'suppliers_in_use' => (int) $suppliersInUse,
            'projects_with_allocation' => (int) $projectsWithAlloc,
            'jigs_with_allocation' => (int) $jigsWithAlloc,
            'units_with_allocation' => (int) $unitsWithAlloc,
            'total_active_assignments' => (int) $totalActiveAssignments,
            'suppliers_with_rework' => (int) $suppliersWithRework,
        ];
    }

    /**
     * Get supplier performance rankings with transparent mathematical metrics.
     */
    public function getRankings(array $filters = [], string $sortBy = 'usage'): array
    {
        $suppliers = Supplier::withTrashed()->get();

        // 1. Get active assignments metrics per supplier
        $assignmentStatsQuery = DB::table('supplier_assignments')
            ->select('supplier_id')
            ->selectRaw("COUNT(*) as total_assignments")
            ->selectRaw("COUNT(DISTINCT project_id) as projects_count")
            ->selectRaw("COUNT(DISTINCT (project_id, jig_no)) as jigs_count")
            ->selectRaw("COUNT(DISTINCT (project_id, jig_no, unit_no)) as units_count")
            ->selectRaw("SUM(CASE WHEN category = 'BASE' THEN 1 ELSE 0 END) as base_count")
            ->selectRaw("SUM(CASE WHEN category = 'WELDMENT' THEN 1 ELSE 0 END) as weldment_count")
            ->selectRaw("SUM(CASE WHEN category = 'CHILD_PART' THEN 1 ELSE 0 END) as child_part_count")
            ->where('status', 'active');

        if (!empty($filters['project_id'])) {
            $assignmentStatsQuery->where('project_id', $filters['project_id']);
        }
        if (!empty($filters['date_from'])) {
            $assignmentStatsQuery->whereDate('assignment_date', '>=', $filters['date_from']);
        }
        if (!empty($filters['date_to'])) {
            $assignmentStatsQuery->whereDate('assignment_date', '<=', $filters['date_to']);
        }
        $assignmentStats = $assignmentStatsQuery->groupBy('supplier_id')->get()->keyBy('supplier_id');

        // 2. Get BOM-level receipts and rework metrics per supplier
        $receiptStatsQuery = DB::table('receipt_items as ri')
            ->join('bom_items as bi', 'ri.bom_item_id', '=', 'bi.id')
            ->select('bi.supplier_id')
            ->selectRaw("COUNT(DISTINCT ri.id) as total_receipts")
            ->selectRaw("SUM(ri.received_quantity) as total_received_qty")
            ->whereNotNull('bi.supplier_id')
            ->whereIn('ri.status', ['received', 'sent_to_qc', 'qc_received', 'returned_to_store']);

        if (!empty($filters['project_id'])) {
            $receiptStatsQuery->where('bi.project_id', $filters['project_id']);
        }
        $receiptStats = $receiptStatsQuery->groupBy('bi.supplier_id')->get()->keyBy('supplier_id');

        $reworkStatsQuery = DB::table('rework_records as rr')
            ->join('bom_items as bi', 'rr.bom_item_id', '=', 'bi.id')
            ->select('bi.supplier_id')
            ->selectRaw("COUNT(DISTINCT rr.id) as total_reworks")
            ->selectRaw("SUM(rr.quantity) as total_rework_qty")
            ->whereNotNull('bi.supplier_id');

        if (!empty($filters['project_id'])) {
            $reworkStatsQuery->where('bi.project_id', $filters['project_id']);
        }
        if (!empty($filters['date_from'])) {
            $reworkStatsQuery->whereDate('rr.created_at', '>=', $filters['date_from']);
        }
        if (!empty($filters['date_to'])) {
            $reworkStatsQuery->whereDate('rr.created_at', '<=', $filters['date_to']);
        }
        $reworkStats = $reworkStatsQuery->groupBy('bi.supplier_id')->get()->keyBy('supplier_id');

        // Build composite supplier ranking list
        $rankingList = [];
        foreach ($suppliers as $s) {
            if (!empty($filters['supplier_id']) && $s->id != $filters['supplier_id']) {
                continue;
            }
            if (isset($filters['active_only']) && $filters['active_only'] && !$s->is_active) {
                continue;
            }

            $aStat = $assignmentStats->get($s->id);
            $rStat = $receiptStats->get($s->id);
            $rwStat = $reworkStats->get($s->id);

            $totalAssignments = $aStat ? (int) $aStat->total_assignments : 0;
            $projectsCount = $aStat ? (int) $aStat->projects_count : 0;
            $jigsCount = $aStat ? (int) $aStat->jigs_count : 0;
            $unitsCount = $aStat ? (int) $aStat->units_count : 0;
            $baseCount = $aStat ? (int) $aStat->base_count : 0;
            $weldmentCount = $aStat ? (int) $aStat->weldment_count : 0;
            $childPartCount = $aStat ? (int) $aStat->child_part_count : 0;

            $totalReceipts = $rStat ? (int) $rStat->total_receipts : 0;
            $totalReceivedQty = $rStat ? (int) $rStat->total_received_qty : 0;
            $totalReworks = $rwStat ? (int) $rwStat->total_reworks : 0;
            $totalReworkQty = $rwStat ? (int) $rwStat->total_rework_qty : 0;

            // Rework rate calculation: based on rework events / total receipt events (or 0)
            $reworkRate = $totalReceipts > 0 ? round(($totalReworks / $totalReceipts) * 100, 1) : 0.0;

            $rankingList[] = [
                'supplier_id' => $s->id,
                'supplier_code' => $s->code,
                'supplier_name' => $s->name,
                'is_active' => (bool) $s->is_active,
                'is_test_data' => (bool) $s->is_test_data,
                'city' => $s->city,
                'state' => $s->state,
                'total_assignments' => $totalAssignments,
                'projects_count' => $projectsCount,
                'jigs_count' => $jigsCount,
                'units_count' => $unitsCount,
                'base_count' => $baseCount,
                'weldment_count' => $weldmentCount,
                'child_part_count' => $childPartCount,
                'total_receipts' => $totalReceipts,
                'total_received_qty' => $totalReceivedQty,
                'total_reworks' => $totalReworks,
                'total_rework_qty' => $totalReworkQty,
                'rework_rate' => $reworkRate,
            ];
        }

        // Sort ranking
        usort($rankingList, function ($a, $b) use ($sortBy) {
            switch ($sortBy) {
                case 'lowest_rework':
                    if ($a['total_receipts'] === 0 && $b['total_receipts'] > 0) return 1;
                    if ($b['total_receipts'] === 0 && $a['total_receipts'] > 0) return -1;
                    return $a['rework_rate'] <=> $b['rework_rate'];
                case 'highest_rework':
                    return $b['rework_rate'] <=> $a['rework_rate'];
                case 'best_overall':
                    // Best overall: High assignments, verified receipts, lowest rework rate
                    $scoreA = ($a['total_assignments'] * 2) + ($a['total_receipts']) - ($a['total_reworks'] * 3);
                    $scoreB = ($b['total_assignments'] * 2) + ($b['total_receipts']) - ($b['total_reworks'] * 3);
                    return $scoreB <=> $scoreA;
                case 'usage':
                default:
                    return $b['total_assignments'] <=> $a['total_assignments'];
            }
        });

        // Add rank number
        foreach ($rankingList as $idx => &$item) {
            $item['rank'] = $idx + 1;
        }

        return $rankingList;
    }

    /**
     * Get Rework Quality Analysis per Supplier.
     */
    public function getReworkAnalysis(array $filters = []): array
    {
        $query = DB::table('rework_records as rr')
            ->join('bom_items as bi', 'rr.bom_item_id', '=', 'bi.id')
            ->leftJoin('suppliers as s', 'bi.supplier_id', '=', 's.id')
            ->leftJoin('projects as p', 'bi.project_id', '=', 'p.id')
            ->select(
                's.id as supplier_id',
                DB::raw("COALESCE(s.name, bi.supplier_name_raw, 'Unknown Supplier') as supplier_name"),
                's.code as supplier_code',
                DB::raw("COUNT(rr.id) as rework_count"),
                DB::raw("SUM(rr.quantity) as total_rework_qty"),
                DB::raw("COUNT(DISTINCT bi.project_id) as affected_projects_count"),
                DB::raw("COUNT(DISTINCT bi.id) as affected_parts_count")
            )
            ->groupBy('s.id', 's.name', 'bi.supplier_name_raw', 's.code');

        if (!empty($filters['project_id'])) {
            $query->where('bi.project_id', $filters['project_id']);
        }
        if (!empty($filters['supplier_id'])) {
            $query->where('bi.supplier_id', $filters['supplier_id']);
        }
        if (!empty($filters['date_from'])) {
            $query->whereDate('rr.created_at', '>=', $filters['date_from']);
        }
        if (!empty($filters['date_to'])) {
            $query->whereDate('rr.created_at', '<=', $filters['date_to']);
        }

        $items = $query->orderByDesc('rework_count')->get();

        // Also get recent rework events for detail table
        $eventsQuery = DB::table('rework_records as rr')
            ->join('bom_items as bi', 'rr.bom_item_id', '=', 'bi.id')
            ->leftJoin('suppliers as s', 'bi.supplier_id', '=', 's.id')
            ->leftJoin('projects as p', 'bi.project_id', '=', 'p.id')
            ->leftJoin('users as u', 'rr.assigned_to', '=', 'u.id')
            ->select(
                'rr.id',
                'rr.quantity as rework_quantity',
                'rr.rework_description as defect_type',
                'rr.completion_notes as notes',
                'rr.status',
                'rr.created_at',
                'bi.standard_part_no',
                'bi.jig_no',
                'bi.unit_no',
                'p.name as project_name',
                'p.project_code',
                DB::raw("COALESCE(s.name, bi.supplier_name_raw, 'Unknown') as supplier_name"),
                'u.name as logged_by'
            )
            ->orderByDesc('rr.created_at')
            ->limit(50);

        if (!empty($filters['project_id'])) {
            $eventsQuery->where('bi.project_id', $filters['project_id']);
        }
        if (!empty($filters['supplier_id'])) {
            $eventsQuery->where('bi.supplier_id', $filters['supplier_id']);
        }

        return [
            'summary' => $items,
            'recent_events' => $eventsQuery->get(),
        ];
    }

    /**
     * Get Allocation Breakdown with drill-down capability.
     */
    public function getAllocationBreakdown(array $filters = []): array
    {
        $query = DB::table('supplier_assignments as sa')
            ->join('suppliers as s', 'sa.supplier_id', '=', 's.id')
            ->join('projects as p', 'sa.project_id', '=', 'p.id')
            ->select(
                's.id as supplier_id',
                's.name as supplier_name',
                's.code as supplier_code',
                's.is_active',
                DB::raw("COUNT(*) as total_active_assignments"),
                DB::raw("COUNT(DISTINCT sa.project_id) as total_projects"),
                DB::raw("COUNT(DISTINCT (sa.project_id, sa.jig_no)) as total_jigs"),
                DB::raw("COUNT(DISTINCT (sa.project_id, sa.jig_no, sa.unit_no)) as total_units"),
                DB::raw("SUM(CASE WHEN sa.category = 'BASE' THEN 1 ELSE 0 END) as base_assignments"),
                DB::raw("SUM(CASE WHEN sa.category = 'WELDMENT' THEN 1 ELSE 0 END) as weldment_assignments"),
                DB::raw("SUM(CASE WHEN sa.category = 'CHILD_PART' THEN 1 ELSE 0 END) as child_part_assignments")
            )
            ->where('sa.status', 'active')
            ->groupBy('s.id', 's.name', 's.code', 's.is_active');

        if (!empty($filters['project_id'])) {
            $query->where('sa.project_id', $filters['project_id']);
        }
        if (!empty($filters['supplier_id'])) {
            $query->where('sa.supplier_id', $filters['supplier_id']);
        }
        if (!empty($filters['category'])) {
            $query->where('sa.category', $filters['category']);
        }

        return $query->orderByDesc('total_active_assignments')->get()->toArray();
    }

    /**
     * Get paginated assignment audit history.
     */
    public function getHistory(array $filters = [], int $page = 1, int $perPage = 25)
    {
        $query = SupplierAssignmentHistory::query()
            ->with(['project', 'previousSupplier', 'newSupplier', 'user'])
            ->orderByDesc('created_at');

        if (!empty($filters['project_id'])) {
            $query->where('project_id', $filters['project_id']);
        }
        if (!empty($filters['supplier_id'])) {
            $sId = $filters['supplier_id'];
            $query->where(function ($q) use ($sId) {
                $q->where('previous_supplier_id', $sId)
                  ->orWhere('new_supplier_id', $sId);
            });
        }
        if (!empty($filters['category'])) {
            $query->where('category', $filters['category']);
        }
        if (!empty($filters['action'])) {
            $query->where('action', $filters['action']);
        }
        if (!empty($filters['date_from'])) {
            $query->whereDate('created_at', '>=', $filters['date_from']);
        }
        if (!empty($filters['date_to'])) {
            $query->whereDate('created_at', '<=', $filters['date_to']);
        }

        return $query->paginate($perPage, ['*'], 'page', $page);
    }

    /**
     * Get supplier assignments for a specific Jig on the Main Dashboard.
     */
    public function getJigSuppliers(int $projectId, string $jigNo): array
    {
        $assignments = SupplierAssignment::with('supplier')
            ->where('project_id', $projectId)
            ->where('jig_no', $jigNo)
            ->where('status', 'active')
            ->orderBy('unit_no')
            ->orderBy('category')
            ->get();

        $project = Project::find($projectId, ['id', 'name', 'project_code']);

        $summary = [];
        $uniqueSuppliers = [];

        foreach ($assignments as $a) {
            $sName = $a->supplier?->name ?? 'Unknown';
            $uniqueSuppliers[$a->supplier_id] = [
                'supplier_id' => $a->supplier_id,
                'supplier_name' => $sName,
                'supplier_code' => $a->supplier?->code,
            ];

            $summary[] = [
                'id' => $a->id,
                'unit_no' => $a->unit_no,
                'category' => $a->category,
                'supplier_id' => $a->supplier_id,
                'supplier_name' => $sName,
                'assignment_date' => $a->assignment_date?->format('Y-m-d'),
                'created_at' => $a->created_at?->format('Y-m-d H:i'),
            ];
        }

        return [
            'project' => $project,
            'jig_no' => $jigNo,
            'unique_suppliers' => array_values($uniqueSuppliers),
            'assignments' => $summary,
            'total_units_assigned' => count(array_unique(array_column($summary, 'unit_no'))),
            'total_categories_assigned' => count($summary),
        ];
    }

    private function applyAssignmentFilters($query, array $filters): void
    {
        if (!empty($filters['project_id'])) {
            $query->where('project_id', $filters['project_id']);
        }
        if (!empty($filters['supplier_id'])) {
            $query->where('supplier_id', $filters['supplier_id']);
        }
        if (!empty($filters['category'])) {
            $query->where('category', $filters['category']);
        }
        if (!empty($filters['date_from'])) {
            $query->whereDate('assignment_date', '>=', $filters['date_from']);
        }
        if (!empty($filters['date_to'])) {
            $query->whereDate('assignment_date', '<=', $filters['date_to']);
        }
    }
}
