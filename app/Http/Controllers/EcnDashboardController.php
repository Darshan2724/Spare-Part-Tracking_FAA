<?php

namespace App\Http\Controllers;

use App\Services\EcnQuantityCalculationService;
use App\Services\KpiDrilldownService;
use Illuminate\Http\Request;

class EcnDashboardController extends Controller
{
    public function __construct(
        protected EcnQuantityCalculationService $ecnQuantityService = new EcnQuantityCalculationService(),
        protected KpiDrilldownService $kpiDrilldownService = new KpiDrilldownService()
    ) {}

    public function summary(Request $request)
    {
        $request->user()?->hasAnyRole(['ADMIN', 'MANAGER', 'STORE', 'QC', 'REWORK', 'PAINT', 'ASSEMBLY', 'PURCHASE']) ?: abort(403);

        $filters = [
            'project_id' => $request->query('project_id'),
            'ecn_number' => $request->query('ecn_number'),
            'jig_no' => $request->query('jig_no'),
            'unit_no' => $request->query('unit_no'),
            'side' => $request->query('side'),
            'date_from' => $request->query('date_from'),
            'date_to' => $request->query('date_to'),
        ];

        $summary = $this->ecnQuantityService->calculateEcnDashboardSummary($filters);
        return response()->json([
            'summary' => $summary,
            'filters' => $filters,
        ]);
    }

    public function drilldown(Request $request)
    {
        $request->user()?->hasAnyRole(['ADMIN', 'MANAGER', 'STORE', 'QC', 'REWORK', 'PAINT', 'ASSEMBLY', 'PURCHASE']) ?: abort(403);

        $kpiKey = $request->query('kpi', 'total_parts');
        $filters = [
            'project_id' => $request->query('project_id'),
            'side' => $request->query('side'),
            'substate' => $request->query('substate', 'all'),
            'search' => $request->query('search'),
            'date_from' => $request->query('date_from'),
            'date_to' => $request->query('date_to'),
            'is_ecn' => true,
        ];
        $page = (int)$request->query('page', 1);
        $perPage = (int)$request->query('per_page', 50);

        $data = $this->kpiDrilldownService->getDrilldownData($kpiKey, $filters, $page, $perPage);
        unset($data['all_data']);

        return response()->json($data);
    }

    public function hierarchy(Request $request)
    {
        $request->user()?->hasAnyRole(['ADMIN', 'MANAGER', 'STORE', 'QC', 'REWORK', 'PAINT', 'ASSEMBLY', 'PURCHASE']) ?: abort(403);

        $projectId = $request->query('project_id') ? (int)$request->query('project_id') : null;
        $filters = [
            'ecn_number' => $request->query('ecn_number'),
            'jig_no' => $request->query('jig_no'),
            'unit_no' => $request->query('unit_no'),
            'side' => $request->query('side'),
        ];

        $hierarchy = $this->ecnQuantityService->getEcnHierarchy($projectId, $filters);
        return response()->json($hierarchy);
    }
}
