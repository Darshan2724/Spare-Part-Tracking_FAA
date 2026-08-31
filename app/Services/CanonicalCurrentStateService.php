<?php

namespace App\Services;

use App\Models\Project;
use App\Models\BomItem;
use App\Models\ReceiptItem;
use App\Models\QcInspection;
use App\Models\ReworkRecord;
use App\Models\PaintRecord;
use App\Models\AssemblyRecord;
use App\Models\EcnRequirement;
use App\Models\EcnReceiptItem;
use App\Models\EcnWorkflowRecord;
use Illuminate\Support\Collection;

/**
 * CanonicalCurrentStateService
 * 
 * Authoritative single-source-of-truth service providing canonical workflow state
 * datasets for both Website and Mobile interfaces.
 * 
 * Rules:
 * 1. Does not alter existing underlying accounting formulas.
 * 2. Strict Regular vs ECN isolation: Regular parts remain REGULAR, ECN parts remain ECN.
 * 3. Store Mobile represents Pending Intake (Regular Pending + ECN Pending).
 * 4. QC Mobile Arrival matches Website Store KPI (parts_in_store resident balance).
 * 5. QC Mobile Inspection matches Website QC KPI (parts_in_qc resident balance).
 * 6. Rework, Paint, and Assembly Mobile queues match active department residency.
 */
class CanonicalCurrentStateService
{
    public function __construct(
        protected QuantityCalculationService $quantityService = new QuantityCalculationService(),
        protected EcnQuantityCalculationService $ecnQuantityService = new EcnQuantityCalculationService()
    ) {}

    /**
     * Get Store Pending Intake quantities (parts not yet received by the company).
     * 
     * @param int|Project $project
     * @param string|null $sideFilter
     * @param array $filters
     * @return array
     */
    public function getStorePendingIntake(int|Project $project, ?string $sideFilter = null, array $filters = []): array
    {
        $projId = $project instanceof Project ? $project->id : (int)$project;
        $regularMetrics = $this->quantityService->calculateProjectMetrics($projId, $sideFilter, $filters);
        
        $regularPending = (int)($regularMetrics['total_pending'] ?? 0);
        $ecnPending = (int)EcnRequirement::query()
            ->where('project_id', $projId)
            ->where('current_state', 'PENDING')
            ->when(!empty($sideFilter), fn($q) => $q->where('side_display', $sideFilter))
            ->sum('required_qty');

        return [
            'regular_pending' => $regularPending,
            'ecn_pending' => $ecnPending,
            'total_pending' => $regularPending, // Regular only on standard counters
            'combined_display_pending' => $regularPending + $ecnPending,
        ];
    }

    /**
     * Get QC Arrival quantities (parts in post-Store stage awaiting physical QC acceptance).
     * Strictly matches Website Store KPI (parts_in_store).
     * 
     * @param int|Project $project
     * @param string|null $sideFilter
     * @param array $filters
     * @return array
     */
    public function getQcArrivalQuantities(int|Project $project, ?string $sideFilter = null, array $filters = []): array
    {
        $projId = $project instanceof Project ? $project->id : (int)$project;
        $regularMetrics = $this->quantityService->calculateProjectMetrics($projId, $sideFilter, $filters);
        
        $regularArrival = (int)($regularMetrics['parts_in_store'] ?? 0);
        $ecnArrival = (int)EcnRequirement::query()
            ->where('project_id', $projId)
            ->whereIn('current_state', ['STORE', 'SENT_TO_QC'])
            ->when(!empty($sideFilter), fn($q) => $q->where('side_display', $sideFilter))
            ->sum('received_qty');

        return [
            'regular_arrival' => $regularArrival, // Exact Website Store KPI
            'ecn_arrival' => $ecnArrival,
            'website_store_kpi' => $regularArrival,
        ];
    }

    /**
     * Get QC Inspection quantities (parts currently undergoing QC inspection).
     * Strictly matches Website QC KPI (parts_in_qc).
     * 
     * @param int|Project $project
     * @param string|null $sideFilter
     * @param array $filters
     * @return array
     */
    public function getQcInspectionQuantities(int|Project $project, ?string $sideFilter = null, array $filters = []): array
    {
        $projId = $project instanceof Project ? $project->id : (int)$project;
        $regularMetrics = $this->quantityService->calculateProjectMetrics($projId, $sideFilter, $filters);
        
        $regularInspection = (int)($regularMetrics['parts_in_qc'] ?? 0);
        $ecnInspection = (int)EcnRequirement::query()
            ->where('project_id', $projId)
            ->where('current_state', 'QC')
            ->when(!empty($sideFilter), fn($q) => $q->where('side_display', $sideFilter))
            ->sum('received_qty');

        return [
            'regular_inspection' => $regularInspection, // Exact Website QC KPI
            'ecn_inspection' => $ecnInspection,
            'website_qc_kpi' => $regularInspection,
        ];
    }

    /**
     * Get Rework active quantities.
     */
    public function getReworkQuantities(int|Project $project, ?string $sideFilter = null, array $filters = []): array
    {
        $projId = $project instanceof Project ? $project->id : (int)$project;
        $regularMetrics = $this->quantityService->calculateProjectMetrics($projId, $sideFilter, $filters);

        $regularRework = (int)($regularMetrics['parts_in_rework'] ?? 0);
        $ecnRework = (int)EcnRequirement::query()
            ->where('project_id', $projId)
            ->where('current_state', 'REWORK')
            ->when(!empty($sideFilter), fn($q) => $q->where('side_display', $sideFilter))
            ->sum('received_qty');

        return [
            'regular_rework' => $regularRework,
            'ecn_rework' => $ecnRework,
            'website_rework_kpi' => $regularRework,
        ];
    }

    /**
     * Get Paint active quantities.
     */
    public function getPaintQuantities(int|Project $project, ?string $sideFilter = null, array $filters = []): array
    {
        $projId = $project instanceof Project ? $project->id : (int)$project;
        $regularMetrics = $this->quantityService->calculateProjectMetrics($projId, $sideFilter, $filters);

        $regularPaint = (int)($regularMetrics['parts_in_paint'] ?? 0);
        $ecnPaint = (int)EcnRequirement::query()
            ->where('project_id', $projId)
            ->where('current_state', 'PAINT')
            ->when(!empty($sideFilter), fn($q) => $q->where('side_display', $sideFilter))
            ->sum('received_qty');

        return [
            'regular_paint' => $regularPaint,
            'ecn_paint' => $ecnPaint,
            'website_paint_kpi' => $regularPaint,
        ];
    }

    /**
     * Get Assembly active quantities.
     */
    public function getAssemblyQuantities(int|Project $project, ?string $sideFilter = null, array $filters = []): array
    {
        $projId = $project instanceof Project ? $project->id : (int)$project;
        $regularMetrics = $this->quantityService->calculateProjectMetrics($projId, $sideFilter, $filters);

        $regularAssembly = (int)($regularMetrics['parts_in_assembly'] ?? 0);
        $ecnAssembly = (int)EcnRequirement::query()
            ->where('project_id', $projId)
            ->whereIn('current_state', ['ASSEMBLY', 'ASSEMBLY_COMPLETED'])
            ->when(!empty($sideFilter), fn($q) => $q->where('side_display', $sideFilter))
            ->sum('received_qty');

        return [
            'regular_assembly' => $regularAssembly,
            'ecn_assembly' => $ecnAssembly,
            'website_assembly_kpi' => $regularAssembly,
        ];
    }
}
