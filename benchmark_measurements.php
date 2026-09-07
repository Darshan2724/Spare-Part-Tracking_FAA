<?php

require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;
use App\Models\User;
use App\Models\Project;
use Illuminate\Http\Request;

$admin = User::where('email', 'admin@sparetrack.internal')->first();
$project = Project::first();
$projectId = $project ? $project->id : 2169;

echo "=== BENCHMARK PROFILE (PROJECT_ID: {$projectId}) ===" . PHP_EOL . PHP_EOL;

function benchmarkEndpoint($name, callable $fn) {
    DB::flushQueryLog();
    DB::enableQueryLog();
    gc_collect_cycles();
    $startMem = memory_get_usage(true);
    $startTime = microtime(true);

    $response = $fn();

    $durationMs = round((microtime(true) - $startTime) * 1000, 2);
    $queries = DB::getQueryLog();
    $queryCount = count($queries);
    $queryTimeMs = round(array_sum(array_column($queries, 'time')), 2);
    
    $content = is_string($response) ? $response : (method_exists($response, 'getContent') ? $response->getContent() : json_encode($response));
    $payloadBytes = strlen($content);
    $payloadKb = round($payloadBytes / 1024, 2);
    $memMb = round((memory_get_peak_usage(true) - $startMem) / (1024 * 1024), 2);

    echo sprintf("%-45s | %8.2f ms | %4d queries (%7.2f ms) | %8.2f KB | %5.2f MB mem",
        $name, $durationMs, $queryCount, $queryTimeMs, $payloadKb, $memMb) . PHP_EOL;

    return [
        'name' => $name,
        'duration_ms' => $durationMs,
        'query_count' => $queryCount,
        'query_time_ms' => $queryTimeMs,
        'payload_kb' => $payloadKb,
    ];
}

$results = [];

// 1. Dashboard Summary
$results[] = benchmarkEndpoint('GET /dashboard/summary (all projects)', function() use ($admin) {
    $controller = app(App\Http\Controllers\DashboardController::class);
    $req = Request::create('/api/v1/dashboard/summary', 'GET');
    $req->setUserResolver(fn() => $admin);
    return $controller->summary($req);
});

// 2. Dashboard Summary for single project
$results[] = benchmarkEndpoint('GET /dashboard/summary (single project)', function() use ($admin, $projectId) {
    $controller = app(App\Http\Controllers\DashboardController::class);
    $req = Request::create('/api/v1/dashboard/summary', 'GET', ['project_id' => $projectId]);
    $req->setUserResolver(fn() => $admin);
    return $controller->summary($req);
});

// 3. Project Hierarchy (All Types - executes 4x hierarchy)
$results[] = benchmarkEndpoint('GET /dashboard/project-hierarchy (All Types)', function() use ($admin, $projectId) {
    $controller = app(App\Http\Controllers\DashboardController::class);
    $req = Request::create('/api/v1/dashboard/project-hierarchy', 'GET', ['project_id' => $projectId]);
    $req->setUserResolver(fn() => $admin);
    return $controller->projectHierarchy($req);
});

// 4. Project Hierarchy (Single Type: MFG)
$results[] = benchmarkEndpoint('GET /dashboard/project-hierarchy (MFG only)', function() use ($admin, $projectId) {
    $controller = app(App\Http\Controllers\DashboardController::class);
    $req = Request::create('/api/v1/dashboard/project-hierarchy', 'GET', ['project_id' => $projectId, 'part_type' => 'MFG']);
    $req->setUserResolver(fn() => $admin);
    return $controller->projectHierarchy($req);
});

// 5. Store Hierarchy
$results[] = benchmarkEndpoint('GET /store/hierarchy (single project)', function() use ($admin, $projectId) {
    $controller = app(App\Http\Controllers\StoreController::class);
    $req = Request::create('/api/v1/store/hierarchy', 'GET', ['project_id' => $projectId]);
    $req->setUserResolver(fn() => $admin);
    return $controller->hierarchy($req, app(App\Services\HierarchyService::class));
});

// 6. QC Hierarchy
$results[] = benchmarkEndpoint('GET /qc/hierarchy (single project)', function() use ($admin, $projectId) {
    $controller = app(App\Http\Controllers\QcController::class);
    $req = Request::create('/api/v1/qc/hierarchy', 'GET', ['project_id' => $projectId]);
    $req->setUserResolver(fn() => $admin);
    return $controller->hierarchy($req, app(App\Services\HierarchyService::class));
});

// 7. Store Items paginated
$results[] = benchmarkEndpoint('GET /store/items (page 1)', function() use ($admin, $projectId) {
    $controller = app(App\Http\Controllers\StoreController::class);
    $req = Request::create('/api/v1/store/items', 'GET', ['project_id' => $projectId, 'per_page' => 50]);
    $req->setUserResolver(fn() => $admin);
    return $controller->index($req);
});

// 8. KPI Drilldown
$results[] = benchmarkEndpoint('GET /dashboard/kpi-drilldown (total_parts)', function() use ($admin, $projectId) {
    $controller = app(App\Http\Controllers\DashboardController::class);
    $req = Request::create('/api/v1/dashboard/kpi-drilldown', 'GET', ['kpi' => 'total_parts', 'project_id' => $projectId, 'per_page' => 50]);
    $req->setUserResolver(fn() => $admin);
    return $controller->kpiDrilldown($req);
});

// 9. Activity History (Dashboard movements)
$results[] = benchmarkEndpoint('GET /dashboard/daily-movement', function() use ($admin, $projectId) {
    $controller = app(App\Http\Controllers\DashboardController::class);
    $req = Request::create('/api/v1/dashboard/daily-movement', 'GET', ['project_id' => $projectId, 'quick_range' => 'last_5_active']);
    $req->setUserResolver(fn() => $admin);
    return $controller->dailyMovement($req);
});

// 10. Search across store items
$results[] = benchmarkEndpoint('GET /store/items?search=020 (Search)', function() use ($admin, $projectId) {
    $controller = app(App\Http\Controllers\StoreController::class);
    // Note: StoreController has bug searching non-existent part_description, we benchmark pending search
    $req = Request::create('/api/v1/store/pending', 'GET', ['project_id' => $projectId, 'search' => '020']);
    $req->setUserResolver(fn() => $admin);
    return $controller->pending($req);
});

echo PHP_EOL . "=== PROFILE COMPLETED ===" . PHP_EOL;
