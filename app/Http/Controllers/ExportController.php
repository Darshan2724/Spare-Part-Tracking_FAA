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

    /**
     * Export Endpoint for Supplier Management Directory
     */
    public function exportSuppliers(Request $request)
    {
        $request->user()?->hasAnyRole(['ADMIN', 'MANAGER', 'STORE', 'PURCHASE']) ?: abort(403);

        $format = strtolower($request->input('format', 'excel'));
        $data = $this->exportService->exportSuppliersData($request);

        if ($format === 'pdf') {
            return $this->exportService->generateSuppliersPdf($data);
        }

        return $this->exportService->generateSuppliersExcel($data);
    }

    /**
     * Export Endpoint for Individual Dashboard Data Blocks
     */
    public function exportBlock(Request $request)
    {
        $request->user()?->hasAnyRole(['ADMIN', 'MANAGER', 'STORE', 'QC', 'REWORK', 'PAINT', 'ASSEMBLY', 'PURCHASE']) ?: abort(403);

        $block = $request->input('block');
        if (!$block) {
            return response()->json(['message' => 'Block parameter is required.'], 422);
        }

        // Reuse the exact canonical data logic powering block details in the Dashboard
        $dashboardController = app(\App\Http\Controllers\DashboardController::class);
        $blockDataResponse = $dashboardController->blockDetails($request);
        $blockData = $blockDataResponse->getData(true);

        return $this->exportService->generateBlockExcel($blockData, $request);
    }

    /**
     * Export Endpoint for Complete Manufacturing & Parts Report Section
     */
    public function exportReport(Request $request)
    {
        $request->user()?->hasAnyRole(['ADMIN', 'MANAGER', 'STORE', 'QC', 'REWORK', 'PAINT', 'ASSEMBLY', 'PURCHASE']) ?: abort(403);

        $data = $this->exportService->exportReportData($request);

        return $this->exportService->generateReportExcel($data);
    }
}
