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
use App\Http\Controllers\HealthController;
use App\Http\Controllers\ExportController;

use App\Http\Controllers\Admin\SystemLogController;
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

        // Dashboard Complete Excel and PDF Export
        Route::post('/export/dashboard', [ExportController::class, 'exportDashboard']);
        Route::get('/export/dashboard', [ExportController::class, 'exportDashboard']);

        // Supplier Registry Excel and PDF Export
        Route::post('/export/suppliers', [ExportController::class, 'exportSuppliers']);
        Route::get('/export/suppliers', [ExportController::class, 'exportSuppliers']);

        // Admin System Logs & Diagnostics (ADMIN ONLY)
        Route::prefix('admin')->group(function () {
            Route::get('/logs', [SystemLogController::class, 'index']);
            Route::get('/logs/dashboard', [SystemLogController::class, 'dashboard']);
            Route::get('/logs/{id}', [SystemLogController::class, 'show']);
            Route::patch('/logs/{id}/status', [SystemLogController::class, 'updateStatus']);
        });

        // Dashboard Summary Metrics
        Route::get('/dashboard/summary', [DashboardController::class, 'summary']);
        Route::get('/dashboard/project-hierarchy', [DashboardController::class, 'projectHierarchy']);
        Route::get('/dashboard/bottleneck', [DashboardController::class, 'bottleneck']);
        Route::get('/dashboard/daily-movement', [DashboardController::class, 'dailyMovement']);
        Route::get('/dashboard/pipeline-status', [DashboardController::class, 'pipelineStatus']);
        Route::get('/dashboard/priority-map', [DashboardController::class, 'priorityMap']);
        Route::get('/dashboard/analytics', [DashboardController::class, 'managementAnalytics']);

        // Suppliers CRUD
        Route::apiResource('suppliers', SupplierController::class);

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
            Route::post('/bulk-action', [ReworkController::class, 'bulkAction']);
        });

        // Purchase Queue Operations
        Route::prefix('purchase')->group(function () {
            Route::get('/items', [PurchaseQueueController::class, 'index']);
            Route::patch('/items/{id}/status', [PurchaseQueueController::class, 'updateStatus']);
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
    });
});
