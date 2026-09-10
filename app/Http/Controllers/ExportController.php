<?php

namespace App\Http\Controllers;

use App\Services\ExportService;
use Illuminate\Http\Request;

class ExportController extends Controller
{
    protected ExportService $exportService;

    public function __construct(ExportService $exportService)
    {
        $this->exportService = $exportService;
    }

    /**
     * Filtered Export Endpoint specifically for Parts Movement Detail View
     */
    public function exportMovement(Request $request)
    {
        $request->user()?->hasAnyRole(['ADMIN', 'MANAGER', 'STORE', 'QC', 'REWORK', 'PAINT', 'ASSEMBLY', 'PURCHASE']) ?: abort(403);

        $format = strtolower($request->input('format', 'excel'));
        $data = $this->exportService->exportMovementData($request);

        if ($format === 'pdf') {
            return $this->exportService->generatePdf($data);
        }

        return $this->exportService->generateExcel($data);
    }

    /**
     * Filtered Export Endpoint for Dashboard KPI Drill-Down
     */
    public function exportKpiDrilldown(Request $request)
    {
        $request->user()?->hasAnyRole(['ADMIN', 'MANAGER', 'STORE', 'QC', 'REWORK', 'PAINT', 'ASSEMBLY', 'PURCHASE']) ?: abort(403);

        $data = $this->exportService->exportKpiDrilldownData($request);
        return $this->exportService->generateExcel($data);
    }

    /**
     * Export project-level Jig material status matching authoritative reference format.
     */
    public function exportProjectJigs(Request $request)
    {
        $request->user()?->hasAnyRole(['ADMIN', 'MANAGER', 'STORE', 'QC', 'REWORK', 'PAINT', 'ASSEMBLY', 'PURCHASE']) ?: abort(403);

        $request->validate([
            'project_id' => 'required|exists:projects,id',
            'side' => 'nullable|string',
            'part_type' => 'nullable|string',
        ]);

        $project = \App\Models\Project::findOrFail($request->input('project_id'));
        $filters = $request->only(['side', 'part_type']);

        return $this->exportService->exportProjectJigs($project, $filters);
    }
}
