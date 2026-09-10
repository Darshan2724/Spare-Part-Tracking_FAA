<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Project;
use App\Services\QuantityCalculationService;
use Tests\TestCase;

class PerformanceBenchmarkTest extends TestCase
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
     * Test that single-pass calculateBulkProjectsMetricsWithPartTypes
     * produces mathematically identical numbers to separate calls.
     */
    public function test_single_pass_metrics_match_individual_metrics()
    {
        $quantityService = app(QuantityCalculationService::class);
        $projects = Project::activeOrHasActiveEcn()->get();

        if ($projects->isEmpty()) {
            $projects = Project::take(3)->get();
        }

        if ($projects->isEmpty()) {
            $this->markTestSkipped('No projects found in database for metrics benchmark.');
        }

        // Single pass
        $multi = $quantityService->calculateBulkProjectsMetricsWithPartTypes($projects);
        $bulkAll = $multi['all'];
        $bulkMfg = $multi['mfg'];
        $bulkBop = $multi['bop'];
        $bulkStd = $multi['std'];

        // Separate calls
        $legacyAll = $quantityService->calculateBulkProjectsMetrics($projects);
        $legacyMfg = $quantityService->calculateBulkProjectsMetrics($projects, null, ['part_type' => 'MFG']);
        $legacyBop = $quantityService->calculateBulkProjectsMetrics($projects, null, ['part_type' => 'BOP']);
        $legacyStd = $quantityService->calculateBulkProjectsMetrics($projects, null, ['part_type' => 'STD']);

        foreach ($projects as $proj) {
            $pAll = $bulkAll->get($proj->id);
            $lAll = $legacyAll->get($proj->id);
            $this->assertEquals($lAll['required_qty'], $pAll['required_qty'], "Required qty mismatch for Project {$proj->id}");
            $this->assertEquals($lAll['received_qty'], $pAll['received_qty'], "Received qty mismatch for Project {$proj->id}");
            $this->assertEquals($lAll['pending_qty'], $pAll['pending_qty'], "Pending qty mismatch for Project {$proj->id}");

            $pMfg = $bulkMfg->get($proj->id);
            $lMfg = $legacyMfg->get($proj->id);
            $this->assertEquals($lMfg['required_qty'], $pMfg['required_qty'], "MFG Required qty mismatch for Project {$proj->id}");

            $pBop = $bulkBop->get($proj->id);
            $lBop = $legacyBop->get($proj->id);
            $this->assertEquals($lBop['required_qty'], $pBop['required_qty'], "BOP Required qty mismatch for Project {$proj->id}");

            $pStd = $bulkStd->get($proj->id);
            $lStd = $legacyStd->get($proj->id);
            $this->assertEquals($lStd['required_qty'], $pStd['required_qty'], "STD Required qty mismatch for Project {$proj->id}");
        }
    }

    /**
     * Test that dashboard summary endpoint returns 200 with all canonical keys.
     */
    public function test_dashboard_summary_returns_optimized_canonical_structure()
    {
        $user = $this->getAdminUser();
        $this->actingAs($user, 'sanctum');

        $start = microtime(true);
        $response = $this->getJson('/api/v1/dashboard/summary');
        $duration = microtime(true) - $start;

        $response->assertStatus(200)
            ->assertJsonStructure([
                'summary' => [
                    'mfg',
                    'bop',
                    'std',
                    'ecn_total',
                ],
                'status_distribution',
                'delayed_parts',
                'quality_trend',
                'recent_events',
                'projects_progress',
                'active_projects',
                'completed_projects',
                'top_projects',
                'health_distribution',
                'supplier_performance',
                'today_throughput',
                'stagnant_parts',
                'defect_pareto',
            ]);

        $this->assertLessThan(15.0, $duration, "Dashboard summary took too long: {$duration}s");
    }
}
