<?php

namespace Tests\Feature;

use App\Models\AssemblyRecord;
use App\Models\BomItem;
use App\Models\BomRequirement;
use App\Models\Project;
use App\Models\Receipt;
use App\Models\ReceiptItem;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class PerformanceOptimizationRegressionTest extends TestCase
{
    use DatabaseTransactions;

    protected User $admin;
    protected Project $project;
    protected BomItem $mfgItem;
    protected BomItem $bopItem;
    protected BomItem $stdItem;

    protected function setUp(): void
    {
        parent::setUp();

        $role = Role::firstOrCreate(['name' => 'ADMIN', 'guard_name' => 'web']);
        $this->admin = User::create([
            'name' => 'Admin Perf User',
            'email' => 'admin_perf_' . uniqid() . '@example.com',
            'password' => bcrypt('secret'),
        ]);
        $this->admin->assignRole($role);

        $this->project = Project::create([
            'name' => 'Perf Test Project ' . uniqid(),
            'project_code' => 'PERF-' . uniqid(),
            'status' => 'active',
        ]);

        $this->mfgItem = BomItem::create([
            'project_id' => $this->project->id,
            'standard_part_no' => 'MFG-PERF-' . uniqid(),
            'part_type' => 'MFG',
            'jig_no' => 'JIG-PERF-01',
            'unit_no' => 'Unit 1',
            'remarks' => 'Perf bracket remarks',
        ]);
        BomRequirement::create([
            'bom_item_id' => $this->mfgItem->id,
            'side' => 'LH',
            'required_quantity' => 10,
        ]);

        $this->bopItem = BomItem::create([
            'project_id' => $this->project->id,
            'standard_part_no' => 'BOP-PERF-' . uniqid(),
            'part_type' => 'BOP',
            'jig_no' => 'JIG-PERF-02',
            'unit_no' => 'Unit 2',
        ]);
        BomRequirement::create([
            'bom_item_id' => $this->bopItem->id,
            'side' => 'COMMON',
            'required_quantity' => 5,
        ]);

        $this->stdItem = BomItem::create([
            'project_id' => $this->project->id,
            'standard_part_no' => 'STD-PERF-' . uniqid(),
            'part_type' => 'STD',
            'jig_no' => 'JIG-PERF-03',
            'unit_no' => 'Unit 3',
        ]);
        BomRequirement::create([
            'bom_item_id' => $this->stdItem->id,
            'side' => 'COMMON',
            'required_quantity' => 12,
        ]);
    }

    public function test_search_on_store_items_and_hierarchy_does_not_crash_on_missing_column()
    {
        // 1. Search on store items
        $response = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/v1/store/items?project_id=' . $this->project->id . '&search=Perf');

        $response->assertStatus(200);

        // 2. Search on store hierarchy
        $hierarchyResponse = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/v1/store/hierarchy?project_id=' . $this->project->id . '&search=bracket');

        $hierarchyResponse->assertStatus(200);
        $this->assertArrayHasKey('jigs', $hierarchyResponse->json());
    }

    public function test_project_hierarchy_query_count_is_minimized_via_single_pass_partition()
    {
        DB::flushQueryLog();
        DB::enableQueryLog();

        $response = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/v1/dashboard/project-hierarchy?project_id=' . $this->project->id);

        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $response->assertStatus(200);
        $data = $response->json();

        $this->assertArrayHasKey('mfg_section', $data);
        $this->assertArrayHasKey('bop_section', $data);
        $this->assertArrayHasKey('std_section', $data);

        // Verify query count is strictly under 35 (down from 150+ in multi-pass)
        $this->assertLessThan(35, count($queries), "Query count for project hierarchy should be strictly bounded");

        // Verify sections contain expected partitioned parts
        $this->assertCount(1, $data['mfg_section']['jigs']);
        $this->assertEquals('JIG-PERF-01', $data['mfg_section']['jigs'][0]['jig_name']);

        $this->assertCount(1, $data['bop_section']['jigs']);
        $this->assertEquals('JIG-PERF-02', $data['bop_section']['jigs'][0]['jig_name']);

        $this->assertCount(1, $data['std_section']['jigs']);
        $this->assertEquals('JIG-PERF-03', $data['std_section']['jigs'][0]['jig_name']);
    }

    public function test_dashboard_summary_scoped_to_single_project_executes_efficiently()
    {
        $response = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/v1/dashboard/summary?project_id=' . $this->project->id);

        $response->assertStatus(200);
        $data = $response->json();

        $this->assertArrayHasKey('summary', $data);
        $this->assertArrayHasKey('mfg', $data['summary']);
        $this->assertArrayHasKey('bop', $data['summary']);
        $this->assertArrayHasKey('std', $data['summary']);
    }
}
