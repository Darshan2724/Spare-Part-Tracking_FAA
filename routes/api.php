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

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Industrial Spare Parts Tracking REST API v1
| Shared by Web Vue 3 SPA and React Native Expo Mobile Application.
|
*/

Route::prefix('v1')->group(function () {
    // Public Authentication Routes
    Route::post('/auth/login', [AuthController::class, 'login'])->middleware('throttle:5,1');

    // Authenticated API Routes (Sanctum)
    Route::middleware('auth:sanctum')->group(function () {
        Route::post('/auth/logout', [AuthController::class, 'logout']);
        Route::get('/auth/me', [AuthController::class, 'me']);

        // Dashboard Summary Metrics
        Route::get('/dashboard/summary', [DashboardController::class, 'summary']);
        Route::get('/dashboard/bottleneck', [DashboardController::class, 'bottleneck']);
        Route::get('/dashboard/daily-movement', [DashboardController::class, 'dailyMovement']);
        Route::get('/dashboard/pipeline-status', [DashboardController::class, 'pipelineStatus']);
        Route::get('/dashboard/priority-map', [DashboardController::class, 'priorityMap']);

        // Suppliers CRUD
        Route::apiResource('suppliers', SupplierController::class);

        // BOM Import
        Route::prefix('bom')->group(function () {
            Route::post('/preview', [BomImportController::class, 'preview']);
            Route::post('/import', [BomImportController::class, 'import']);
            Route::get('/history', [BomImportController::class, 'history']);
        });

        // Store Operations
        Route::prefix('store')->group(function () {
            Route::get('/items', [StoreController::class, 'index']);
            Route::get('/hierarchy', [StoreController::class, 'hierarchy']);
            Route::get('/history', [StoreController::class, 'history']);
            Route::post('/receipts', [StoreController::class, 'store']);
            Route::post('/items/{id}/send-to-qc', [StoreController::class, 'sendToQc']);
            Route::post('/items/{id}/revert', [StoreController::class, 'revert']);
        });

        // Quality Control Operations
        Route::prefix('qc')->group(function () {
            Route::get('/queue', [QcController::class, 'queue']);
            Route::get('/history', [QcController::class, 'history']);
            Route::post('/receive', [QcController::class, 'confirmReceived']);
            Route::post('/reject-arrival', [QcController::class, 'rejectArrival']);
            Route::post('/inspect', [QcController::class, 'inspect']);
        });

        // Rework Department Operations
        Route::prefix('rework')->group(function () {
            Route::get('/items', [ReworkController::class, 'index']);
            Route::post('/items', [ReworkController::class, 'store']);
            Route::post('/items/{id}/start', [ReworkController::class, 'start']);
            Route::post('/items/{id}/complete', [ReworkController::class, 'complete']);
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
            Route::post('/items', [PaintController::class, 'store']);
        });

        // Assembly Department Operations
        Route::prefix('assembly')->group(function () {
            Route::get('/items', [AssemblyController::class, 'index']);
            Route::get('/queue', [AssemblyController::class, 'queue']);
            Route::post('/items', [AssemblyController::class, 'store']);
        });
    });
});
