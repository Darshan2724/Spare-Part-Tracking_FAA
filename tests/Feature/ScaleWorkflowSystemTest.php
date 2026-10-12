<?php

namespace Tests\Feature;

use App\Models\BomItem;
use App\Models\BomRequirement;
use App\Models\EcnRequirement;
use App\Models\Project;
use App\Models\User;
use App\Services\QuantityCalculationService;
use Database\Seeders\ScaleTestDatasetSeeder;
use Tests\TestCase;

class ScaleWorkflowSystemTest extends TestCase
{
    protected User $adminUser;
    protected QuantityCalculationService $quantityService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->quantityService = app(QuantityCalculationService::class);

        // Ensure database has scale test dataset seeded
        if (Project::count() !== 8 || BomItem::count() !== ScaleTestDatasetSeeder::TARGET_TOTAL_PARTS) {
            $seeder = new ScaleTestDatasetSeeder();
            $seeder->run();
        }

        $this->adminUser = User::firstOrCreate(
            ['email' => 'admin@sparetrack.internal'],
            ['name' => 'System Admin', 'password' => bcrypt('password123'), 'is_active' => true]
        );
        $role = \Spatie\Permission\Models\Role::firstOrCreate(['name' => 'ADMIN', 'guard_name' => 'web']);
        if (!$this->adminUser->hasRole('ADMIN')) {
            $this->adminUser->assignRole($role);
        }
    }

    protected function tearDown(): void
    {
        parent::tearDown();
    }

    /**
     * Requirement: 7-8 projects in with all BOP, STD, MFG, and ECN.
     */
    public function test_system_contains_seven_to_eight_projects_with_bop_std_mfg_and_ecn(): void
    {
        $projectCount = Project::where('status', 'active')->count();
        $this->assertGreaterThanOrEqual(7, $projectCount, 'Expected at least 7 projects');
        $this->assertLessThanOrEqual(8, $projectCount, 'Expected at most 8 projects');
        $this->assertEquals(8, $projectCount, 'Expected exactly 8 active projects');

        $projects = Project::where('status', 'active')->orderBy('project_code')->get();

        foreach ($projects as $project) {
            $bopCount = $project->bomItems()->where('part_type', 'BOP')->count();
            $stdCount = $project->bomItems()->where('part_type', 'STD')->count();
            $mfgCount = $project->bomItems()->where('part_type', 'MFG')->count();
            $ecnCount = $project->ecnRequirements()->count();

            $this->assertGreaterThan(0, $bopCount, "Project {$project->project_code} must contain BOP parts.");
            $this->assertGreaterThan(0, $stdCount, "Project {$project->project_code} must contain STD parts.");
            $this->assertGreaterThan(0, $mfgCount, "Project {$project->project_code} must contain MFG parts.");
            $this->assertGreaterThan(0, $ecnCount, "Project {$project->project_code} must contain ECN requirements.");
        }
    }

    /**
     * Requirement: Total parts must equal 15,343.
     */
    public function test_total_parts_equals_fifteen_thousand_three_hundred_forty_three(): void
    {
        $totalBomItems = BomItem::count();
        $this->assertEquals(ScaleTestDatasetSeeder::TARGET_TOTAL_PARTS, $totalBomItems, 'BomItem count must be 15,343');

        $totalRequiredQuantity = (int) BomRequirement::sum('required_quantity');
        $this->assertEquals(ScaleTestDatasetSeeder::TARGET_TOTAL_PARTS, $totalRequiredQuantity, 'BomRequirement sum must be 15,343');

        $summary = $this->quantityService->calculateDashboardSummary();
        $this->assertEquals(ScaleTestDatasetSeeder::TARGET_TOTAL_PARTS, $summary['total_parts'], 'Dashboard total_parts must be 15,343');
        $this->assertEquals(ScaleTestDatasetSeeder::TARGET_TOTAL_PARTS, $summary['total_parts_received'], 'Dashboard total_parts_received must be 15,343');
    }

    /**
     * Requirement: Parts unevenly distributed across Store, QC, Rework, Assembly.
     */
    public function test_parts_are_unevenly_distributed_across_store_qc_rework_assembly(): void
    {
        $summary = $this->quantityService->calculateDashboardSummary();

        $store = $summary['parts_in_store'];
        $qc = $summary['parts_in_qc'];
        $rework = $summary['parts_in_rework'];
        $assembly = $summary['parts_in_assembly'];

        // Assert exact targets
        $this->assertEquals(ScaleTestDatasetSeeder::TARGET_STORE, $store, 'Parts in Store must be 5,420');
        $this->assertEquals(ScaleTestDatasetSeeder::TARGET_QC, $qc, 'Parts in QC must be 4,115');
        $this->assertEquals(ScaleTestDatasetSeeder::TARGET_REWORK, $rework, 'Parts in Rework must be 1,673');
        $this->assertEquals(ScaleTestDatasetSeeder::TARGET_ASSEMBLY, $assembly, 'Parts in Assembly must be 4,135');

        // Assert uneven distribution (all 4 values must be strictly distinct)
        $this->assertNotEquals($store, $qc, 'Store and QC must have uneven quantities');
        $this->assertNotEquals($store, $rework, 'Store and Rework must have uneven quantities');
        $this->assertNotEquals($store, $assembly, 'Store and Assembly must have uneven quantities');
        $this->assertNotEquals($qc, $rework, 'QC and Rework must have uneven quantities');
        $this->assertNotEquals($qc, $assembly, 'QC and Assembly must have uneven quantities');
        $this->assertNotEquals($rework, $assembly, 'Rework and Assembly must have uneven quantities');

        // Assert zero-sum conservation law
        $this->assertEquals(
            ScaleTestDatasetSeeder::TARGET_TOTAL_PARTS,
            $store + $qc + $rework + $assembly,
            'The sum of parts in Store, QC, Rework, and Assembly must equal 15,343'
        );
    }

    /**
     * Test Dashboard API summary returns 200 with matching metrics.
     */
    public function test_dashboard_api_summary_returns_exact_dataset_metrics(): void
    {
        $response = $this->actingAs($this->adminUser, 'sanctum')
            ->getJson('/api/v1/dashboard/summary');

        $response->assertStatus(200);

        $summary = $response->json('summary');
        $this->assertNotNull($summary);

        $this->assertEquals(8, $summary['active_projects']);
        $this->assertEquals(ScaleTestDatasetSeeder::TARGET_TOTAL_PARTS, $summary['total_parts']);
        $this->assertEquals(ScaleTestDatasetSeeder::TARGET_STORE, $summary['parts_in_store']);
        $this->assertEquals(ScaleTestDatasetSeeder::TARGET_QC, $summary['parts_in_qc']);
        $this->assertEquals(ScaleTestDatasetSeeder::TARGET_REWORK, $summary['parts_in_rework']);
        $this->assertEquals(ScaleTestDatasetSeeder::TARGET_ASSEMBLY, $summary['parts_in_assembly']);

        // Check part type breakdowns
        $this->assertArrayHasKey('mfg', $summary);
        $this->assertArrayHasKey('bop', $summary);
        $this->assertArrayHasKey('std', $summary);

        $mfgParts = $summary['mfg']['total_parts'];
        $bopParts = $summary['bop']['total_parts'];
        $stdParts = $summary['std']['total_parts'];

        $this->assertGreaterThan(0, $mfgParts);
        $this->assertGreaterThan(0, $bopParts);
        $this->assertGreaterThan(0, $stdParts);
        $this->assertEquals(ScaleTestDatasetSeeder::TARGET_TOTAL_PARTS, $mfgParts + $bopParts + $stdParts);

        // Check ECN metrics
        $this->assertGreaterThan(0, $summary['ecn_total_parts']);
    }

    /**
     * Test all 5 KPI drilldown endpoints match authoritative quantities.
     */
    public function test_kpi_drilldown_endpoints_match_authoritative_quantities(): void
    {
        $kpiExpectations = [
            'total_parts' => ScaleTestDatasetSeeder::TARGET_TOTAL_PARTS,
            'store'       => ScaleTestDatasetSeeder::TARGET_STORE,
            'qc'          => ScaleTestDatasetSeeder::TARGET_QC,
            'rework'      => ScaleTestDatasetSeeder::TARGET_REWORK,
            'assembly'    => ScaleTestDatasetSeeder::TARGET_ASSEMBLY,
        ];

        foreach ($kpiExpectations as $kpi => $expectedQty) {
            $response = $this->actingAs($this->adminUser, 'sanctum')
                ->getJson("/api/v1/dashboard/kpi-drilldown?kpi={$kpi}&per_page=50");

            $response->assertStatus(200);
            $actualQty = (int) $response->json('total_quantity');
            $this->assertEquals(
                $expectedQty,
                $actualQty,
                "Drilldown for KPI '{$kpi}' expected quantity {$expectedQty}, received {$actualQty}"
            );
        }
    }

    /**
     * Test ECN coverage across Store, QC, Rework, and Assembly across projects.
     */
    public function test_ecn_requirements_distributed_across_workflow_states(): void
    {
        $states = ['STORE', 'QC', 'REWORK', 'ASSEMBLY', 'PENDING'];

        foreach ($states as $state) {
            $count = EcnRequirement::where('current_state', $state)->count();
            $this->assertGreaterThan(0, $count, "Expected active ECN requirements in state '{$state}'.");
        }

        $allProjects = Project::where('status', 'active')->get();
        foreach ($allProjects as $project) {
            $this->assertTrue(
                $project->is_ecn_active,
                "Project {$project->project_code} must be marked as effectively active for ECN."
            );
        }
    }
}
