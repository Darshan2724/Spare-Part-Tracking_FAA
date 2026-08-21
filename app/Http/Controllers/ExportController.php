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
     * Export Endpoint for Complete Manufacturing Dashboard
     */
    public function exportDashboard(Request $request)
    {
        $request->user()?->hasAnyRole(['ADMIN', 'MANAGER', 'STORE', 'QC', 'REWORK', 'PAINT', 'ASSEMBLY', 'PURCHASE']) ?: abort(403);

        $format = strtolower($request->input('format', 'excel'));
        $data = $this->exportService->exportDashboardData($request);

        if ($format === 'pdf') {
            return $this->exportService->generateDashboardPdf($data);
        }

        return $this->exportService->generateDashboardExcel($data);
    }
}
