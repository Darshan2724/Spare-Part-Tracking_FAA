<?php

namespace Tests\Feature;

use App\Models\AssemblyRecord;
use App\Models\BomItem;
use App\Models\BomRequirement;
use App\Models\Project;
use App\Models\Receipt;
use App\Models\ReceiptItem;
use App\Models\User;
use App\Services\QuantityCalculationService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class DashboardTypeFilteringAndHierarchyTest extends TestCase
{
    use DatabaseTransactions;

    protected QuantityCalculationService $quantityService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->quantityService = new QuantityCalculationService();
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

    public function test_all_types_aggregates_mfg_bop_std_for_top_projects_and_health()
    {
        $admin = $this->getAdminUser();

        // Create a unique test project
        $project = Project::create([
            'name' => 'Test Aggregate Multi-Type Project',
            'project_code' => 'TEST-AGG-' . uniqid(),
            'status' => 'active',
        ]);

        // 1. MFG item: Required 10, Received 10, Assembled 8
        $mfgItem = BomItem::create([
            'project_id' => $project->id,
            'jig_no' => 'JIG-MFG-01',
            'unit_no' => 'Unit 1',
            'item_no' => 'ITM-MFG-1',
            'part_name' => 'Fabricated Bracket',
            'standard_part_no' => 'MFG-BRK-01',
            'part_type' => 'MFG',
        ]);
        BomRequirement::create([
            'bom_item_id' => $mfgItem->id,
            'side' => 'LH',
            'required_quantity' => 10,
        ]);
        $receiptMfg = Receipt::create([
            'project_id' => $project->id,
            'delivery_note_number' => 'DC-MFG-' . uniqid(),
            'received_by' => $admin->id,
        ]);
        ReceiptItem::create([
            'receipt_id' => $receiptMfg->id,
            'bom_item_id' => $mfgItem->id,
            'side' => 'LH',
            'received_quantity' => 10,
            'status' => 'received',
        ]);
        AssemblyRecord::create([
            'bom_item_id' => $mfgItem->id,
            'side' => 'LH',
            'quantity' => 8,
            'status' => 'completed',
            'assembled_by' => $admin->id,
            'created_at' => now(),
        ]);

        // 2. BOP item: Required 20, Received 20, Assembled 10
        $bopItem = BomItem::create([
            'project_id' => $project->id,
            'jig_no' => 'JIG-BOP-01',
            'unit_no' => 'Unit 2',
            'item_no' => 'ITM-BOP-1',
            'part_name' => 'Cylinder BOP',
            'standard_part_no' => 'BOP-CYL-01',
            'part_type' => 'BOP',
        ]);
        BomRequirement::create([
            'bom_item_id' => $bopItem->id,
            'side' => 'COMMON',
            'required_quantity' => 20,
        ]);
        $receiptBop = Receipt::create([
            'project_id' => $project->id,
            'delivery_note_number' => 'DC-BOP-' . uniqid(),
            'received_by' => $admin->id,
        ]);
        ReceiptItem::create([
            'receipt_id' => $receiptBop->id,
            'bom_item_id' => $bopItem->id,
            'side' => 'COMMON',
            'received_quantity' => 20,
            'status' => 'received',
        ]);
        AssemblyRecord::create([
            'bom_item_id' => $bopItem->id,
            'side' => 'COMMON',
            'quantity' => 10,
            'status' => 'completed',
            'assembled_by' => $admin->id,
            'created_at' => now(),
        ]);

        // 3. STD item: Required 30, Received 30, Assembled 6
        $stdItem = BomItem::create([
            'project_id' => $project->id,
            'jig_no' => 'JIG-STD-01',
            'unit_no' => 'Unit 3',
            'item_no' => 'ITM-STD-1',
            'part_name' => 'Bolt M8 STD',
            'standard_part_no' => 'STD-BLT-01',
            'part_type' => 'STD',
        ]);
        BomRequirement::create([
            'bom_item_id' => $stdItem->id,
            'side' => 'COMMON',
            'required_quantity' => 30,
        ]);
        $receiptStd = Receipt::create([
            'project_id' => $project->id,
            'delivery_note_number' => 'DC-STD-' . uniqid(),
            'received_by' => $admin->id,
        ]);
        ReceiptItem::create([
            'receipt_id' => $receiptStd->id,
            'bom_item_id' => $stdItem->id,
            'side' => 'COMMON',
            'received_quantity' => 30,
            'status' => 'received',
        ]);
        AssemblyRecord::create([
            'bom_item_id' => $stdItem->id,
            'side' => 'COMMON',
            'quantity' => 6,
            'status' => 'completed',
            'assembled_by' => $admin->id,
            'created_at' => now(),
        ]);

        // Total Required = 10 + 20 + 30 = 60
        // Total Assembled = 8 + 10 + 6 = 24
        // Expected Combined Completion % = (24 / 60) * 100 = 40.0%

        // A. Test API with All Types (no part_type or empty)
        $resAll = $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/dashboard/summary');

        $resAll->assertStatus(200);
        $topProjects = $resAll->json('top_projects');
        $this->assertIsArray($topProjects);

        // Find our test project in top projects list
        $found = null;
        foreach ($topProjects['projects'] ?? [] as $tp) {
            if ($tp['id'] == $project->id) {
                $found = $tp;
                break;
            }
        }
        $this->assertNotNull($found, 'Project should be present in top projects list');
        $this->assertEquals(60, $found['required_qty']);
        $this->assertEquals(40.0, $found['weighted_completion']);

        // B. Test API with part_type = MFG
        // Required = 10, Assembled = 8 -> 80.0%
        $resMfg = $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/dashboard/summary?part_type=MFG');
        $resMfg->assertStatus(200);
        $topMfg = $resMfg->json('top_projects');
        $foundMfg = null;
        foreach ($topMfg['projects'] ?? [] as $tp) {
            if ($tp['id'] == $project->id) {
                $foundMfg = $tp;
                break;
            }
        }
        $this->assertNotNull($foundMfg);
        $this->assertEquals(10, $foundMfg['required_qty']);
        $this->assertEquals(80.0, $foundMfg['weighted_completion']);

        // C. Test API with part_type = BOP
        // Required = 20, Assembled = 10 -> 50.0%
        $resBop = $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/dashboard/summary?part_type=BOP');
        $resBop->assertStatus(200);
        $topBop = $resBop->json('top_projects');
        $foundBop = null;
        foreach ($topBop['projects'] ?? [] as $tp) {
            if ($tp['id'] == $project->id) {
                $foundBop = $tp;
                break;
            }
        }
        $this->assertNotNull($foundBop);
        $this->assertEquals(20, $foundBop['required_qty']);
        $this->assertEquals(50.0, $foundBop['weighted_completion']);

        // D. Test API with part_type = STD
        // Required = 30, Assembled = 6 -> 20.0%
        $resStd = $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/dashboard/summary?part_type=STD');
        $resStd->assertStatus(200);
        $topStd = $resStd->json('top_projects');
        $foundStd = null;
        foreach ($topStd['projects'] ?? [] as $tp) {
            if ($tp['id'] == $project->id) {
                $foundStd = $tp;
                break;
            }
        }
        $this->assertNotNull($foundStd);
        $this->assertEquals(30, $foundStd['required_qty']);
        $this->assertEquals(20.0, $foundStd['weighted_completion']);
    }

    public function test_zero_required_projects_safe_no_division_by_zero()
    {
        $admin = $this->getAdminUser();

        $emptyProject = Project::create([
            'name' => 'Zero Required Project',
            'project_code' => 'TEST-ZERO-' . uniqid(),
            'status' => 'active',
        ]);

        $res = $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/dashboard/summary');

        $res->assertStatus(200);
        $health = $res->json('health_distribution');
        $this->assertIsArray($health);
        $this->assertArrayHasKey('counts', $health);
        $this->assertArrayHasKey('percentages', $health);
    }

    public function test_project_hierarchy_endpoint_returns_three_compact_sections_under_all_types()
    {
        $admin = $this->getAdminUser();

        $project = Project::create([
            'name' => 'Project With Three Types',
            'project_code' => 'TEST-3TYPE-' . uniqid(),
            'status' => 'active',
        ]);

        // Create MFG item
        $mfg = BomItem::create([
            'project_id' => $project->id,
            'jig_no' => 'JIG-M1',
            'unit_no' => 'Unit 1',
            'item_no' => 'ITM-M1',
            'part_name' => 'MFG Part',
            'standard_part_no' => 'PART-M1',
            'part_type' => 'MFG',
        ]);
        BomRequirement::create([
            'bom_item_id' => $mfg->id,
            'side' => 'LH',
            'required_quantity' => 5,
        ]);

        // Create BOP item
        $bop = BomItem::create([
            'project_id' => $project->id,
            'jig_no' => 'JIG-B1',
            'unit_no' => 'Unit 2',
            'item_no' => 'ITM-B1',
            'part_name' => 'BOP Part',
            'standard_part_no' => 'PART-B1',
            'part_type' => 'BOP',
        ]);
        BomRequirement::create([
            'bom_item_id' => $bop->id,
            'side' => 'COMMON',
            'required_quantity' => 15,
        ]);

        // Create STD item
        $std = BomItem::create([
            'project_id' => $project->id,
            'jig_no' => 'JIG-S1',
            'unit_no' => 'Unit 3',
            'item_no' => 'ITM-S1',
            'part_name' => 'STD Part',
            'standard_part_no' => 'PART-S1',
            'part_type' => 'STD',
        ]);
        BomRequirement::create([
            'bom_item_id' => $std->id,
            'side' => 'COMMON',
            'required_quantity' => 25,
        ]);

        // 1. Fetch under All Types (no part_type parameter)
        $resAll = $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/dashboard/project-hierarchy?project_id=' . $project->id);

        $resAll->assertStatus(200);
        $dataAll = $resAll->json();

        $this->assertArrayHasKey('mfg_section', $dataAll, 'Hierarchy must contain mfg_section under All Types');
        $this->assertArrayHasKey('bop_section', $dataAll, 'Hierarchy must contain bop_section under All Types');
        $this->assertArrayHasKey('std_section', $dataAll, 'Hierarchy must contain std_section under All Types');

        $this->assertCount(1, $dataAll['mfg_section']['jigs']);
        $this->assertEquals('JIG-M1', $dataAll['mfg_section']['jigs'][0]['jig_name']);
        $mfgPartsAll = $dataAll['mfg_section']['jigs'][0]['units'][0]['sides']['LH']['parts'] ?? [];
        $this->assertNotEmpty($mfgPartsAll, 'MFG unit must contain parts under All Types');
        $this->assertEquals('PART-M1', $mfgPartsAll[0]['standard_part_no']);
        $this->assertEquals('MFG', $mfgPartsAll[0]['part_type']);
        $this->assertEquals(5, $mfgPartsAll[0]['required_qty']);

        $this->assertCount(1, $dataAll['bop_section']['jigs']);
        $this->assertEquals('JIG-B1', $dataAll['bop_section']['jigs'][0]['jig_name']);
        $bopPartsAll = $dataAll['bop_section']['jigs'][0]['units'][0]['sides']['COMMON']['parts'] ?? [];
        $this->assertNotEmpty($bopPartsAll, 'BOP unit must contain parts under All Types');
        $this->assertEquals('PART-B1', $bopPartsAll[0]['standard_part_no']);
        $this->assertEquals('BOP', $bopPartsAll[0]['part_type']);
        $this->assertEquals(15, $bopPartsAll[0]['required_qty']);

        $this->assertCount(1, $dataAll['std_section']['jigs']);
        $this->assertEquals('JIG-S1', $dataAll['std_section']['jigs'][0]['jig_name']);
        $stdPartsAll = $dataAll['std_section']['jigs'][0]['units'][0]['sides']['COMMON']['parts'] ?? [];
        $this->assertNotEmpty($stdPartsAll, 'STD unit must contain parts under All Types');
        $this->assertEquals('PART-S1', $stdPartsAll[0]['standard_part_no']);
        $this->assertEquals('STD', $stdPartsAll[0]['part_type']);
        $this->assertEquals(25, $stdPartsAll[0]['required_qty']);

        // 2. Fetch under Single Type: MFG
        $resMfg = $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/dashboard/project-hierarchy?project_id=' . $project->id . '&part_type=MFG');

        $resMfg->assertStatus(200);
        $dataMfg = $resMfg->json();
        $this->assertArrayHasKey('jigs', $dataMfg);
        $this->assertCount(1, $dataMfg['jigs']);
        $this->assertEquals('JIG-M1', $dataMfg['jigs'][0]['jig_name']);
        $this->assertArrayHasKey('mfg_section', $dataMfg);
        $this->assertArrayHasKey('bop_section', $dataMfg);
        $this->assertArrayHasKey('std_section', $dataMfg);
        $this->assertCount(1, $dataMfg['mfg_section']['jigs']);
        $this->assertCount(0, $dataMfg['bop_section']['jigs']);
        $this->assertCount(0, $dataMfg['std_section']['jigs']);
        $mfgParts = $dataMfg['mfg_section']['jigs'][0]['units'][0]['sides']['LH']['parts'] ?? [];
        $this->assertNotEmpty($mfgParts, 'MFG unit must contain parts under MFG view');
        $this->assertEquals('PART-M1', $mfgParts[0]['standard_part_no']);
        $this->assertEquals('MFG', $mfgParts[0]['part_type']);

        // 3. Fetch under Single Type: BOP
        $resBop = $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/dashboard/project-hierarchy?project_id=' . $project->id . '&part_type=BOP');

        $resBop->assertStatus(200);
        $dataBop = $resBop->json();
        $this->assertArrayHasKey('jigs', $dataBop);
        $this->assertCount(1, $dataBop['jigs']);
        $this->assertEquals('JIG-B1', $dataBop['jigs'][0]['jig_name']);
        $this->assertArrayHasKey('mfg_section', $dataBop);
        $this->assertArrayHasKey('bop_section', $dataBop);
        $this->assertArrayHasKey('std_section', $dataBop);
        $this->assertCount(0, $dataBop['mfg_section']['jigs']);
        $this->assertCount(1, $dataBop['bop_section']['jigs']);
        $this->assertCount(0, $dataBop['std_section']['jigs']);
        $bopParts = $dataBop['bop_section']['jigs'][0]['units'][0]['sides']['COMMON']['parts'] ?? [];
        $this->assertNotEmpty($bopParts, 'BOP unit must contain parts under BOP view');
        $this->assertEquals('PART-B1', $bopParts[0]['standard_part_no']);
        $this->assertEquals('BOP', $bopParts[0]['part_type']);

        // 4. Fetch under Single Type: STD
        $resStd = $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/dashboard/project-hierarchy?project_id=' . $project->id . '&part_type=STD');

        $resStd->assertStatus(200);
        $dataStd = $resStd->json();
        $this->assertArrayHasKey('jigs', $dataStd);
        $this->assertCount(1, $dataStd['jigs']);
        $this->assertEquals('JIG-S1', $dataStd['jigs'][0]['jig_name']);
        $this->assertArrayHasKey('mfg_section', $dataStd);
        $this->assertArrayHasKey('bop_section', $dataStd);
        $this->assertArrayHasKey('std_section', $dataStd);
        $this->assertCount(0, $dataStd['mfg_section']['jigs']);
        $this->assertCount(0, $dataStd['bop_section']['jigs']);
        $this->assertCount(1, $dataStd['std_section']['jigs']);
        $stdParts = $dataStd['std_section']['jigs'][0]['units'][0]['sides']['COMMON']['parts'] ?? [];
        $this->assertNotEmpty($stdParts, 'STD unit must contain parts under STD view');
        $this->assertEquals('PART-S1', $stdParts[0]['standard_part_no']);
        $this->assertEquals('STD', $stdParts[0]['part_type']);
    }

    public function test_single_type_empty_hierarchy_returns_empty_jigs_array_and_section_key()
    {
        $admin = $this->getAdminUser();

        // Create project with ONLY MFG items (0 BOP, 0 STD)
        $project = Project::create([
            'name' => 'Test Single Type Empty Project',
            'project_code' => 'TEST-EMPTY-' . uniqid(),
            'status' => 'active',
        ]);

        $mfg = BomItem::create([
            'project_id' => $project->id,
            'jig_no' => 'JIG-M1',
            'unit_no' => 'Unit 1',
            'item_no' => 'ITM-M1',
            'part_name' => 'MFG Part',
            'standard_part_no' => 'PART-M1',
            'part_type' => 'MFG',
        ]);
        BomRequirement::create([
            'bom_item_id' => $mfg->id,
            'side' => 'COMMON',
            'required_quantity' => 5,
        ]);

        // Fetching BOP when project has 0 BOP items must return 200 with jigs=[] and bop_section
        $resBop = $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/dashboard/project-hierarchy?project_id=' . $project->id . '&part_type=BOP');

        $resBop->assertStatus(200);
        $dataBop = $resBop->json();
        $this->assertArrayHasKey('jigs', $dataBop);
        $this->assertIsArray($dataBop['jigs']);
        $this->assertCount(0, $dataBop['jigs']);
        $this->assertArrayHasKey('bop_section', $dataBop);
        $this->assertCount(0, $dataBop['bop_section']['jigs']);

        // Fetching STD when project has 0 STD items must return 200 with jigs=[] and std_section
        $resStd = $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/dashboard/project-hierarchy?project_id=' . $project->id . '&part_type=STD');

        $resStd->assertStatus(200);
        $dataStd = $resStd->json();
        $this->assertArrayHasKey('jigs', $dataStd);
        $this->assertIsArray($dataStd['jigs']);
        $this->assertCount(0, $dataStd['jigs']);
        $this->assertArrayHasKey('std_section', $dataStd);
        $this->assertCount(0, $dataStd['std_section']['jigs']);
    }
}
