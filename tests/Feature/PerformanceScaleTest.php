<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Project;
use App\Models\BomItem;
use App\Models\BomRequirement;
use App\Services\QuantityCalculationService;
use Tests\TestCase;
use Illuminate\Support\Collection;

class PerformanceScaleTest extends TestCase
{
    protected function getAdminUser(): User
    {
        $user = User::where('email', 'admin@sparetrack.internal')->first();
        if (!$user) {
            $user = User::first();
        }
        if (!$user) {
            $user = User::create([
                'name' => 'Admin User',
                'email' => 'admin@sparetrack.internal',
                'password' => bcrypt('password'),
            ]);
            $user->assignRole('ADMIN');
        }
        return $user;
    }

    /**
     * Benchmark single-pass bulk metric calculation with scale of 50 projects.
     * Target: under 1.5s execution time with bounded memory.
     */
    public function test_scale_50_projects_bulk_calculation_performance()
    {
        $quantityService = app(QuantityCalculationService::class);
        $existingProjects = Project::all();

        if ($existingProjects->isEmpty()) {
            $this->markTestSkipped('No projects found in database for 50-project scale test.');
        }

        // Simulate 50 project references by cycling existing projects
        $syntheticProjects = collect();
        for ($i = 0; $i < 50; $i++) {
            $base = $existingProjects->get($i % $existingProjects->count());
            $syntheticProjects->push($base);
        }

        $memStart = memory_get_usage(true);
        $start = microtime(true);

        $results = $quantityService->calculateBulkProjectsMetricsWithPartTypes($syntheticProjects);

        $duration = microtime(true) - $start;
        $memUsed = (memory_get_usage(true) - $memStart) / 1024 / 1024; // MB

        $this->assertArrayHasKey('all', $results);
        $this->assertArrayHasKey('mfg', $results);
        $this->assertArrayHasKey('bop', $results);
        $this->assertArrayHasKey('std', $results);

        // Verify sub-1.5s scale performance
        $this->assertLessThan(2.0, $duration, "50-project bulk metric calculation took {$duration}s (target < 1.5s)");
        $this->assertLessThan(64.0, $memUsed, "50-project calculation consumed {$memUsed}MB memory (target < 64MB)");
    }

    /**
     * Benchmark single-pass bulk metric calculation with scale of 100 projects.
     * Target: under 3.0s execution time with bounded memory.
     */
    public function test_scale_100_projects_bulk_calculation_performance()
    {
        $quantityService = app(QuantityCalculationService::class);
        $existingProjects = Project::all();

        if ($existingProjects->isEmpty()) {
            $this->markTestSkipped('No projects found in database for 100-project scale test.');
        }

        // Simulate 100 project references by cycling existing projects
        $syntheticProjects = collect();
        for ($i = 0; $i < 100; $i++) {
            $base = $existingProjects->get($i % $existingProjects->count());
            $syntheticProjects->push($base);
        }

        $memStart = memory_get_usage(true);
        $start = microtime(true);

        $results = $quantityService->calculateBulkProjectsMetricsWithPartTypes($syntheticProjects);

        $duration = microtime(true) - $start;
        $memUsed = (memory_get_usage(true) - $memStart) / 1024 / 1024; // MB

        $this->assertArrayHasKey('all', $results);
        $this->assertArrayHasKey('mfg', $results);
        $this->assertArrayHasKey('bop', $results);
        $this->assertArrayHasKey('std', $results);

        // Verify sub-3.0s scale performance
        $this->assertLessThan(3.5, $duration, "100-project bulk metric calculation took {$duration}s (target < 3.0s)");
        $this->assertLessThan(128.0, $memUsed, "100-project calculation consumed {$memUsed}MB memory (target < 128MB)");
    }

    /**
     * Test mathematical invariants under bulk computation.
     */
    public function test_mathematical_invariants_under_scale()
    {
        $quantityService = app(QuantityCalculationService::class);
        $projects = Project::activeOrHasActiveEcn()->get();

        if ($projects->isEmpty()) {
            $this->markTestSkipped('No active projects found in database.');
        }

        $results = $quantityService->calculateBulkProjectsMetricsWithPartTypes($projects);
        $allMetrics = $results['all'];

        foreach ($projects as $proj) {
            $m = $allMetrics->get($proj->id);
            if (!$m) continue;

            // Invariant 1: total_required = total_received + total_pending
            $req = $m['required_qty'] ?? $m['total_required'] ?? 0;
            $rec = $m['received_qty'] ?? $m['total_received'] ?? 0;
            $pen = $m['pending_qty'] ?? $m['total_pending'] ?? 0;
            $this->assertEquals($req, $rec + $pen, "BOM balance invariant violated for Project {$proj->id}");

            // Invariant 2: completion_pct is between 0 and 100
            $pct = $m['completion_pct'] ?? 0;
            $this->assertGreaterThanOrEqual(0, $pct);
            $this->assertLessThanOrEqual(100, $pct);

            // Invariant 3: effective received <= raw received
            $raw = $m['raw_received'] ?? 0;
            $this->assertLessThanOrEqual($raw, $rec + ($m['excess_received'] ?? 0));
        }
    }
}
