<?php

namespace App\Services;

use App\Models\Supplier;
use App\Models\SupplierAssignment;
use Illuminate\Support\Facades\DB;

/**
 * Supplier Load KPI Service
 *
 * Calculates and ranks supplier workload from actual supplier_assignments data.
 *
 * ====================================================================
 * FORMULA DOCUMENTATION (Used Everywhere)
 * ====================================================================
 *
 * Supplier Load = COUNT of active supplier_assignments for supplier S
 *
 * Units Assigned = COUNT(DISTINCT (project_id, jig_no, unit_no))
 *                  for supplier S WHERE status = 'active'
 *
 * Category Assignments = COUNT(*) of active supplier_assignments for S
 *
 * Load % = (Supplier S Active Assignments / Total Active Assignments) × 100
 *
 * Relative Status:
 *   - High Load:   load_pct > average_load + (stddev * 0.5)
 *   - Low Load:    load_pct < average_load - (stddev * 0.5)
 *   - Medium Load: everything in between
 *
 * ====================================================================
 */
class SupplierLoadService
{
    /**
     * Get full supplier load ranking with filters.
     *
     * @return array {suppliers: [...], total_assignments, highest_load, lowest_load, average_load}
     */
    public function getSupplierLoad(array $filters = []): array
    {
        // Build the main aggregation query using PostgreSQL
        $query = DB::table('supplier_assignments as sa')
            ->join('suppliers as s', 'sa.supplier_id', '=', 's.id')
            ->select([
                'sa.supplier_id',
                's.name as supplier_name',
                's.code as supplier_code',
                's.is_active as supplier_is_active',
                DB::raw("COUNT(*) as total_assignments"),
                DB::raw("COUNT(DISTINCT (sa.project_id, sa.jig_no, sa.unit_no)) as units_assigned"),
                DB::raw("COUNT(DISTINCT sa.project_id) as projects_count"),
                DB::raw("SUM(CASE WHEN sa.category = 'BASE' THEN 1 ELSE 0 END) as base_count"),
                DB::raw("SUM(CASE WHEN sa.category = 'WELDMENT' THEN 1 ELSE 0 END) as weldment_count"),
                DB::raw("SUM(CASE WHEN sa.category = 'CHILD_PART' THEN 1 ELSE 0 END) as child_part_count"),
            ])
            ->where('sa.status', 'active')
            ->whereNull('s.deleted_at');

        // Apply filters
        if (!empty($filters['project_id'])) {
            $query->where('sa.project_id', $filters['project_id']);
        }
        if (!empty($filters['jig_no'])) {
            $query->where('sa.jig_no', $filters['jig_no']);
        }
        if (!empty($filters['unit_no'])) {
            $query->where('sa.unit_no', $filters['unit_no']);
        }
        if (!empty($filters['category'])) {
            $query->where('sa.category', $filters['category']);
        }
        if (!empty($filters['supplier_id'])) {
            $query->where('sa.supplier_id', $filters['supplier_id']);
        }
        if (!empty($filters['date_from'])) {
            $query->whereDate('sa.assignment_date', '>=', $filters['date_from']);
        }
        if (!empty($filters['date_to'])) {
            $query->whereDate('sa.assignment_date', '<=', $filters['date_to']);
        }
        if (!empty($filters['active_only'])) {
            $query->where('s.is_active', true);
        }

        $query->groupBy('sa.supplier_id', 's.name', 's.code', 's.is_active');

        $results = $query->orderByDesc('total_assignments')->get();

        // Calculate total across all suppliers for load percentage
        $totalAssignments = $results->sum('total_assignments');

        // Calculate statistics for relative status
        $loadValues = $results->pluck('total_assignments')->toArray();
        $count = count($loadValues);
        $averageLoad = $count > 0 ? array_sum($loadValues) / $count : 0;

        // Standard deviation calculation
        $variance = 0;
        if ($count > 1) {
            foreach ($loadValues as $v) {
                $variance += pow($v - $averageLoad, 2);
            }
            $variance /= $count;
        }
        $stddev = sqrt($variance);

        // Thresholds for relative status
        $highThreshold = $averageLoad + ($stddev * 0.5);
        $lowThreshold = max(0, $averageLoad - ($stddev * 0.5));

        $suppliers = [];
        $highestLoad = null;
        $lowestLoad = null;

        foreach ($results as $idx => $row) {
            $loadPct = $totalAssignments > 0
                ? round(($row->total_assignments / $totalAssignments) * 100, 1)
                : 0;

            // Determine relative status
            if ($row->total_assignments > $highThreshold && $count > 1) {
                $relativeStatus = 'High Load';
            } elseif ($row->total_assignments < $lowThreshold && $count > 1) {
                $relativeStatus = 'Low Load';
            } else {
                $relativeStatus = 'Medium Load';
            }

            $supplierData = [
                'rank' => $idx + 1,
                'supplier_id' => $row->supplier_id,
                'supplier_name' => $row->supplier_name,
                'supplier_code' => $row->supplier_code,
                'supplier_is_active' => (bool) $row->supplier_is_active,
                'units_assigned' => (int) $row->units_assigned,
                'projects_count' => (int) $row->projects_count,
                'base_count' => (int) $row->base_count,
                'weldment_count' => (int) $row->weldment_count,
                'child_part_count' => (int) $row->child_part_count,
                'total_assignments' => (int) $row->total_assignments,
                'load_pct' => $loadPct,
                'relative_status' => $relativeStatus,
            ];

            $suppliers[] = $supplierData;

            if ($highestLoad === null || $row->total_assignments > $highestLoad['total_assignments']) {
                $highestLoad = $supplierData;
            }
            if ($lowestLoad === null || $row->total_assignments < $lowestLoad['total_assignments']) {
                $lowestLoad = $supplierData;
            }
        }

        return [
            'suppliers' => $suppliers,
            'total_assignments' => (int) $totalAssignments,
            'supplier_count' => $count,
            'highest_load' => $highestLoad,
            'lowest_load' => $lowestLoad,
            'average_load' => round($averageLoad, 1),
            'average_load_pct' => $count > 0 ? round(100 / $count, 1) : 0,
        ];
    }

    /**
     * Get compact supplier load summary for dropdown hints.
     * Returns a map of supplier_id => load_status for display in allocation dropdowns.
     *
     * @return array [{ supplier_id, name, load_status, total_assignments }]
     */
    public function getSupplierLoadSummary(): array
    {
        $loadData = $this->getSupplierLoad();

        $summary = [];
        foreach ($loadData['suppliers'] as $s) {
            $summary[$s['supplier_id']] = [
                'supplier_id' => $s['supplier_id'],
                'load_status' => $s['relative_status'],
                'total_assignments' => $s['total_assignments'],
                'load_pct' => $s['load_pct'],
            ];
        }

        return $summary;
    }
}
