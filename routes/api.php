<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AssemblyController;
use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\BomImportController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\PaintController;
use App\Http\Controllers\PurchaseQueueController;
use App\Http\Controllers\QcController;
use App\Http\Controllers\ReworkController;
use App\Http\Controllers\StoreController;
use App\Http\Controllers\SupplierController;
use App\Http\Controllers\SupplierAllocationController;
use App\Http\Controllers\SupplierAnalyticsController;
use App\Http\Controllers\HealthController;
use App\Http\Controllers\ExportController;

use App\Http\Controllers\Admin\SystemLogController;
use App\Http\Controllers\EcnImportController;
use App\Http\Controllers\EcnDashboardController;
use App\Http\Controllers\EcnWorkflowController;
use App\Http\Middleware\CaptureSystemLogsMiddleware;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Industrial Spare Parts Tracking REST API v1
| Shared by Web Vue 3 SPA and React Native Expo Mobile Application.
|
*/

// Top-level Health Check Alias (/api/health)
Route::get('/health', [HealthController::class, 'check']);

Route::prefix('v1')->middleware([CaptureSystemLogsMiddleware::class])->group(function () {
    // Public Health Check Endpoint (/api/v1/health)
    Route::get('/health', [HealthController::class, 'check']);

    // Public Authentication Routes
    Route::post('/auth/login', [AuthController::class, 'login'])->middleware('throttle:60,1');

    // Authenticated API Routes (Sanctum)
    Route::middleware('auth:sanctum')->group(function () {
        Route::post('/auth/logout', [AuthController::class, 'logout']);
        Route::get('/auth/me', [AuthController::class, 'me']);

        // Parts Movement Detail Excel and PDF Export
        Route::post('/export/movement', [ExportController::class, 'exportMovement']);
        Route::get('/export/movement', [ExportController::class, 'exportMovement']);
        Route::post('/export/drilldown', [ExportController::class, 'exportKpiDrilldown']);
        Route::get('/export/drilldown', [ExportController::class, 'exportKpiDrilldown']);

        // Admin System Logs & Diagnostics (ADMIN ONLY)
        Route::prefix('admin')->group(function () {
            Route::get('/logs', [SystemLogController::class, 'index']);
            Route::get('/logs/dashboard', [SystemLogController::class, 'dashboard']);
            Route::get('/logs/{id}', [SystemLogController::class, 'show']);
            Route::patch('/logs/{id}/status', [SystemLogController::class, 'updateStatus']);
        });
        
        // Dashboard Summary Metrics & KPI Drill-Down
        Route::get('/dashboard/summary', [DashboardController::class, 'summary']);
        Route::get('/dashboard/project-hierarchy', [DashboardController::class, 'projectHierarchy']);
        Route::get('/dashboard/kpi-drilldown', [DashboardController::class, 'kpiDrilldown']);
        Route::get('/dashboard/kpi-drilldown/export', [ExportController::class, 'exportKpiDrilldown']);
        Route::post('/dashboard/kpi-drilldown/export', [ExportController::class, 'exportKpiDrilldown']);
        Route::get('/dashboard/bottleneck', [DashboardController::class, 'bottleneck']);
        Route::get('/dashboard/daily-movement', [DashboardController::class, 'dailyMovement']);
        Route::get('/dashboard/pipeline-status', [DashboardController::class, 'pipelineStatus']);
        Route::get('/dashboard/priority-map', [DashboardController::class, 'priorityMap']);
        Route::get('/dashboard/analytics', [DashboardController::class, 'managementAnalytics']);
        Route::get('/dashboard/jig-suppliers', [DashboardController::class, 'jigSuppliers']);

        // Suppliers CRUD & Dropdown List & Excel Import
        Route::get('/suppliers/active-list', [SupplierController::class, 'activeList']);
        Route::get('/suppliers/imports', [SupplierController::class, 'listImports']);
        Route::delete('/suppliers/import/{id}', [SupplierController::class, 'deleteImport']);
        Route::post('/suppliers/import/preview', [SupplierController::class, 'importPreview']);
        Route::post('/suppliers/import/commit', [SupplierController::class, 'commitImport']);
        Route::apiResource('suppliers', SupplierController::class);

        // Supplier Allocation (Purchase Section)
        Route::prefix('supplier-allocation')->group(function () {
            Route::get('/hierarchy', [SupplierAllocationController::class, 'hierarchy']);
            Route::get('/assignments', [SupplierAllocationController::class, 'getAssignments']);
            Route::post('/assign', [SupplierAllocationController::class, 'saveAssignment']);
            Route::post('/bulk-assign', [SupplierAllocationController::class, 'bulkAssign']);
            Route::post('/multi-unit-assign', [SupplierAllocationController::class, 'multiUnitAssign']);
            Route::delete('/assignments/{id}', [SupplierAllocationController::class, 'removeAssignment']);
            Route::get('/overview', [SupplierAllocationController::class, 'overview']);
        });

        // Supplier Management & Analytics
        Route::prefix('supplier-analytics')->group(function () {
            Route::get('/kpis', [SupplierAnalyticsController::class, 'kpis']);
            Route::get('/ranking', [SupplierAnalyticsController::class, 'ranking']);
            Route::get('/load', [SupplierAnalyticsController::class, 'load']);
            Route::get('/rework', [SupplierAnalyticsController::class, 'rework']);
            Route::get('/allocation', [SupplierAnalyticsController::class, 'allocation']);
            Route::get('/history', [SupplierAnalyticsController::class, 'history']);
            Route::get('/suppliers/{id}', [SupplierAnalyticsController::class, 'supplierDetail']);
        });

        // BOM Import
        Route::prefix('bom')->group(function () {
            Route::post('/preview', [BomImportController::class, 'preview']);
            Route::post('/import', [BomImportController::class, 'import']);
            Route::get('/history', [BomImportController::class, 'history']);
            Route::get('/history/{id}/impact', [BomImportController::class, 'impactPreview']);
            Route::delete('/history/{id}', [BomImportController::class, 'destroy']);
        });

        // Store Operations
        Route::prefix('store')->group(function () {
            Route::get('/items', [StoreController::class, 'index']);
            Route::get('/pending', [StoreController::class, 'pending']);
            Route::get('/hierarchy', [StoreController::class, 'hierarchy']);
            Route::get('/history', [StoreController::class, 'history']);
            Route::get('/returned', [StoreController::class, 'returnedItems']);
            Route::post('/items/{id}/process-returned', [StoreController::class, 'processReturnedItem']);
            Route::post('/receipts', [StoreController::class, 'store']);
            Route::post('/bulk-receive', [StoreController::class, 'bulkReceive']);
            Route::post('/items/{id}/send-to-qc', [StoreController::class, 'sendToQc']);
            Route::post('/items/{id}/revert', [StoreController::class, 'revert']);
        });

        // Quality Control Operations
        Route::prefix('qc')->group(function () {
            Route::get('/queue', [QcController::class, 'queue']);
            Route::get('/hierarchy', [QcController::class, 'hierarchy']);
            Route::get('/history', [QcController::class, 'history']);
            Route::post('/receive', [QcController::class, 'confirmReceived']);
            Route::post('/bulk-receive', [QcController::class, 'bulkReceive']);
            Route::post('/reject-arrival', [QcController::class, 'rejectArrival']);
            Route::post('/inspect', [QcController::class, 'inspect']);
            Route::post('/bulk-inspect', [QcController::class, 'bulkInspect']);
        });

        // Rework Department Operations
        Route::prefix('rework')->group(function () {
            Route::get('/items', [ReworkController::class, 'index']);
            Route::get('/hierarchy', [ReworkController::class, 'hierarchy']);
            Route::post('/items', [ReworkController::class, 'store']);
            Route::post('/items/{id}/start', [ReworkController::class, 'start']);
            Route::post('/items/{id}/complete', [ReworkController::class, 'complete']);
            Route::post('/complete', [ReworkController::class, 'complete']);
            Route::post('/bulk-action', [ReworkController::class, 'bulkAction']);
        });

        // Purchase Queue Operations
        Route::prefix('purchase')->group(function () {
            Route::get('/items', [PurchaseQueueController::class, 'index']);
            Route::get('/queue', [PurchaseQueueController::class, 'index']);
            Route::patch('/items/{id}/status', [PurchaseQueueController::class, 'updateStatus']);
            Route::patch('/queue/{id}', [PurchaseQueueController::class, 'updateStatus']);
            Route::patch('/queue/{id}/status', [PurchaseQueueController::class, 'updateStatus']);
            Route::get('/export', [PurchaseQueueController::class, 'export']);
            Route::post('/export', [PurchaseQueueController::class, 'export']);
        });

        // Paint Department Operations
        Route::prefix('paint')->group(function () {
            Route::get('/items', [PaintController::class, 'index']);
            Route::get('/queue', [PaintController::class, 'queue']);
            Route::get('/hierarchy', [PaintController::class, 'hierarchy']);
            Route::post('/items', [PaintController::class, 'store']);
            Route::post('/bulk-complete', [PaintController::class, 'bulkComplete']);
        });

        // Assembly Department Operations
        Route::prefix('assembly')->group(function () {
            Route::get('/items', [AssemblyController::class, 'index']);
            Route::get('/queue', [AssemblyController::class, 'queue']);
            Route::get('/hierarchy', [AssemblyController::class, 'hierarchy']);
            Route::post('/items', [AssemblyController::class, 'store']);
            Route::post('/bulk-complete', [AssemblyController::class, 'bulkComplete']);
        });

        // Strict Lineage-Based Workflow Revert Operations
        Route::prefix('workflow')->group(function () {
            Route::get('/revert-options', [\App\Http\Controllers\WorkflowRevertController::class, 'getRevertOptions']);
            Route::get('/revert-items', [\App\Http\Controllers\WorkflowRevertController::class, 'getRevertItems']);
            Route::post('/revert', [\App\Http\Controllers\WorkflowRevertController::class, 'revert']);
            Route::post('/bulk-revert', [\App\Http\Controllers\WorkflowRevertController::class, 'bulkRevert']);
        });

        // ECN Management & Workflow (Strictly Isolated Classification)
        Route::prefix('ecn')->group(function () {
            // Import
            Route::post('/preview', [EcnImportController::class, 'preview']);
            Route::post('/import', [EcnImportController::class, 'import']);
            Route::get('/history', [EcnImportController::class, 'history']);

            // Dashboard & Reports
            Route::get('/summary', [EcnDashboardController::class, 'summary']);
            Route::get('/dashboard/summary', [EcnDashboardController::class, 'summary']);
            Route::get('/drilldown', [EcnDashboardController::class, 'drilldown']);
            Route::get('/dashboard/drilldown', [EcnDashboardController::class, 'drilldown']);
            Route::get('/hierarchy', [EcnDashboardController::class, 'hierarchy']);

            // Workflow Transitions
            Route::post('/store/receive', [EcnWorkflowController::class, 'storeReceive']);
            Route::post('/store/send-to-qc', [EcnWorkflowController::class, 'sendToQc']);
            Route::post('/qc/receive', [EcnWorkflowController::class, 'qcReceive']);
            Route::post('/qc/inspect', [EcnWorkflowController::class, 'qcInspect']);
            Route::post('/rework/complete', [EcnWorkflowController::class, 'reworkComplete']);
            Route::post('/paint/complete', [EcnWorkflowController::class, 'paintComplete']);
            Route::post('/assembly/complete', [EcnWorkflowController::class, 'assemblyComplete']);
            Route::post('/revert', [EcnWorkflowController::class, 'revert']);
            Route::get('/revert-options', [EcnWorkflowController::class, 'revertOptions']);
            Route::post('/mixed-bulk-intake', [EcnWorkflowController::class, 'mixedBulkIntake']);
            Route::post('/mixed-bulk-revert', [EcnWorkflowController::class, 'mixedBulkRevert']);
        });
    });
});
