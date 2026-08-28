<?php

namespace Tests\Feature;

use App\Models\BomItem;
use App\Models\BomRequirement;
use App\Models\Project;
use App\Models\Receipt;
use App\Models\ReceiptItem;
use App\Models\User;
use App\Models\AssemblyRecord;
use App\Services\HierarchyService;
use App\Services\QuantityCalculationService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class Phase2ProjectDrilldownTest extends TestCase
{
    use DatabaseTransactions;
    protected HierarchyService $hierarchyService;
    protected QuantityCalculationService $quantityService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->quantityService = new QuantityCalculationService();
        $this->hierarchyService = new HierarchyService($this->quantityService);
    }

    protected function getAdminUser(): User
    {
        $role = Role::firstOrCreate(['name' => 'ADMIN', 'guard_name' => 'web']);

        $user = User::where('email', 'admin@sparetrack.internal')->first();
        if (!$user) {
            $user = User::create([
                'name' => 'Admin Test',
                'email' => 'admin@sparetrack.internal',
                'password' => bcrypt('password'),
            ]);
        }
        if (!$user->hasRole('ADMIN')) {
            $user->assignRole($role);
        }
        return $user;
    }

    public function test_project_hierarchy_api_returns_complete_5_level_tree()
    {
        $admin = $this->getAdminUser();

        $project = Project::create([
            'name' => 'Project Alpha Drilldown',
            'project_code' => 'PA-001-' . uniqid(),
            'status' => 'active',
        ]);

        $item1 = BomItem::create([
            'project_id' => $project->id,
            'jig_no' => 'JIG-01',
            'unit_no' => 'Unit 101',
            'item_no' => 'ITM-001',
            'part_name' => 'Bracket Assembly LH',
            'standard_part_no' => 'PART-BRK-01',
        ]);

        BomRequirement::create([
            'bom_item_id' => $item1->id,
            'side' => 'LH',
            'required_quantity' => 10,
        ]);

        $response = $this->actingAs($admin, 'sanctum')
            ->getJson("/api/v1/dashboard/project-hierarchy?project_id={$project->id}");

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'is_hierarchical',
            'department',
            'project',
            'canonical_summary' => [
                'required_qty',
                'received_qty',
                'pending_qty',
                'parts_in_store',
                'parts_in_qc',
                'qc_rejected',
                'parts_in_rework',
                'parts_in_paint',
                'parts_in_assembly',
                'assembly_completed',
                'completion_pct',
            ],
            'jigs' => [
                '*' => [
                    'jig_name',
                    'total_required',
                    'total_received',
                    'total_pending',
                    'completion_pct',
                    'is_complete',
                    'units' => [
                        '*' => [
                            'unit_no',
                            'total_required',
                            'total_received',
                            'total_pending',
                            'completion_pct',
                            'is_complete',
                            'sides' => [
                                'LH' => [
                                    'side',
                                    'total_required',
                                    'total_received',
                                    'pending_quantity',
                                    'completion_pct',
                                    'is_complete',
                                    'parts',
                                ],
                                'RH',
                            ],
                        ],
                    ],
                ],
            ],
            'active_projects',
            'completed_projects',
        ]);

        $data = $response->json();
        $this->assertTrue($data['is_hierarchical']);
        $this->assertCount(1, $data['jigs']);
        $this->assertEquals('JIG-01', $data['jigs'][0]['jig_name']);
        $this->assertEquals(10, $data['jigs'][0]['total_required']);
        $this->assertEquals('Unit 101', $data['jigs'][0]['units'][0]['unit_no']);
        $this->assertCount(1, $data['jigs'][0]['units'][0]['sides']['LH']['parts']);
        $this->assertEquals('PART-BRK-01', $data['jigs'][0]['units'][0]['sides']['LH']['parts'][0]['standard_part_no']);
    }

    public function test_bottom_up_green_completion_propagation()
    {
        $admin = $this->getAdminUser();

        $project = Project::create([
            'name' => 'Project Propagation Test',
            'project_code' => 'PP-100-' . uniqid(),
            'status' => 'active',
        ]);

        $item = BomItem::create([
            'project_id' => $project->id,
            'jig_no' => 'JIG-A',
            'unit_no' => 'Unit 1',
            'item_no' => 'ITM-A1',
            'standard_part_no' => 'PART-A1',
        ]);

        BomRequirement::create([
            'bom_item_id' => $item->id,
            'side' => 'LH',
            'required_quantity' => 5,
        ]);
        BomRequirement::create([
            'bom_item_id' => $item->id,
            'side' => 'RH',
            'required_quantity' => 5,
        ]);

        $receipt = Receipt::create([
            'project_id' => $project->id,
            'received_by' => $admin->id,
        ]);

        // Initially 0 received, 0 assembled
        $tree1 = $this->hierarchyService->getDepartmentHierarchy('manager', $project->id);
        $this->assertFalse($tree1['jigs'][0]['is_complete'], 'Jig must be incomplete initially');
        $this->assertFalse($tree1['jigs'][0]['units'][0]['is_complete'], 'Unit must be incomplete initially');
        $this->assertFalse($tree1['jigs'][0]['units'][0]['sides']['LH']['is_complete']);
        $this->assertFalse($tree1['jigs'][0]['units'][0]['sides']['RH']['is_complete']);

        // Step 1: Complete LH only
        ReceiptItem::create([
            'receipt_id' => $receipt->id,
            'bom_item_id' => $item->id,
            'side' => 'LH',
            'received_quantity' => 5,
            'status' => 'sent_to_qc',
        ]);
        AssemblyRecord::create([
            'bom_item_id' => $item->id,
            'side' => 'LH',
            'quantity' => 5,
            'assembled_by' => $admin->id,
            'status' => 'completed',
        ]);

        $tree2 = $this->hierarchyService->getDepartmentHierarchy('manager', $project->id);
        $this->assertTrue($tree2['jigs'][0]['units'][0]['sides']['LH']['is_complete'], 'LH side must be complete');
        $this->assertFalse($tree2['jigs'][0]['units'][0]['sides']['RH']['is_complete'], 'RH side must remain incomplete');
        $this->assertFalse($tree2['jigs'][0]['units'][0]['is_complete'], 'Unit must NOT be complete when RH is missing');
        $this->assertFalse($tree2['jigs'][0]['is_complete'], 'Jig must NOT be complete');

        // Step 2: Complete RH side as well
        ReceiptItem::create([
            'receipt_id' => $receipt->id,
            'bom_item_id' => $item->id,
            'side' => 'RH',
            'received_quantity' => 5,
            'status' => 'sent_to_qc',
        ]);
        AssemblyRecord::create([
            'bom_item_id' => $item->id,
            'side' => 'RH',
            'quantity' => 5,
            'assembled_by' => $admin->id,
            'status' => 'completed',
        ]);

        $tree3 = $this->hierarchyService->getDepartmentHierarchy('manager', $project->id);
        $this->assertTrue($tree3['jigs'][0]['units'][0]['sides']['LH']['is_complete']);
        $this->assertTrue($tree3['jigs'][0]['units'][0]['sides']['RH']['is_complete']);
        $this->assertTrue($tree3['jigs'][0]['units'][0]['is_complete'], 'Unit must be green/complete when both LH & RH are complete');
        $this->assertTrue($tree3['jigs'][0]['is_complete'], 'Jig must be green/complete when all its units are complete');
    }

    public function test_jig_ordering_incomplete_first_completed_at_bottom()
    {
        $admin = $this->getAdminUser();

        $project = Project::create(['name' => 'Sort Project', 'project_code' => 'SP-1-' . uniqid(), 'status' => 'active']);

        $receipt = Receipt::create([
            'project_id' => $project->id,
            'received_by' => $admin->id,
        ]);

        // Jig 1: Completed
        $item1 = BomItem::create([
            'project_id' => $project->id,
            'jig_no' => 'JIG-01-COMPLETED',
            'unit_no' => 'Unit 1',
            'standard_part_no' => 'PART-1',
        ]);
        BomRequirement::create([
            'bom_item_id' => $item1->id,
            'side' => 'COMMON',
            'required_quantity' => 2,
        ]);
        ReceiptItem::create([
            'receipt_id' => $receipt->id,
            'bom_item_id' => $item1->id,
            'side' => 'COMMON',
            'received_quantity' => 2,
            'status' => 'sent_to_qc',
        ]);
        AssemblyRecord::create([
            'bom_item_id' => $item1->id,
            'side' => 'COMMON',
            'quantity' => 2,
            'assembled_by' => $admin->id,
            'status' => 'completed',
        ]);

        // Jig 2: Incomplete
        $item2 = BomItem::create([
            'project_id' => $project->id,
            'jig_no' => 'JIG-02-INCOMPLETE',
            'unit_no' => 'Unit 1',
            'standard_part_no' => 'PART-2',
        ]);
        BomRequirement::create([
            'bom_item_id' => $item2->id,
            'side' => 'COMMON',
            'required_quantity' => 5,
        ]);

        $tree = $this->hierarchyService->getDepartmentHierarchy('manager', $project->id);
        $this->assertCount(2, $tree['jigs']);
        
        // Incomplete JIG-02 must be first, Completed JIG-01 must be second (at bottom)
        $this->assertEquals('JIG-02-INCOMPLETE', $tree['jigs'][0]['jig_name']);
        $this->assertFalse($tree['jigs'][0]['is_complete']);

        $this->assertEquals('JIG-01-COMPLETED', $tree['jigs'][1]['jig_name']);
        $this->assertTrue($tree['jigs'][1]['is_complete']);
    }

    public function test_completed_projects_remain_accessible()
    {
        $admin = $this->getAdminUser();

        $activeProject = Project::create(['name' => 'Active Project 1', 'project_code' => 'AP-1-' . uniqid(), 'status' => 'active']);
        $completedProject = Project::create(['name' => 'Completed Project 2', 'project_code' => 'CP-2-' . uniqid(), 'status' => 'completed']);

        $response = $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/dashboard/project-hierarchy');

        $response->assertStatus(200);
        $data = $response->json();

        $activeIds = collect($data['active_projects'])->pluck('id')->all();
        $completedIds = collect($data['completed_projects'])->pluck('id')->all();

        $this->assertContains($activeProject->id, $activeIds);
        $this->assertContains($completedProject->id, $completedIds);
    }
}
