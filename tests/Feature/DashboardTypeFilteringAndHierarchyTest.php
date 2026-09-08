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

    public function test_cross_project_and_type_switching_isolation_and_part_visibility()
    {
        $admin = $this->getAdminUser();

        // Project A: Contains all 3 types (MFG, BOP, STD)
        $projectA = Project::create([
            'name' => 'Project A Multi-Type',
            'project_code' => 'TEST-PROJ-A-' . uniqid(),
            'status' => 'active',
        ]);

        $mfgA = BomItem::create([
            'project_id' => $projectA->id,
            'jig_no' => 'JIG-A-MFG',
            'unit_no' => 'Unit 1',
            'item_no' => 'ITM-A-M1',
            'part_name' => 'MFG Part A',
            'standard_part_no' => 'PART-A-MFG-01',
            'part_type' => 'MFG',
        ]);
        BomRequirement::create(['bom_item_id' => $mfgA->id, 'side' => 'LH', 'required_quantity' => 12]);

        $bopA = BomItem::create([
            'project_id' => $projectA->id,
            'jig_no' => 'JIG-A-BOP',
            'unit_no' => 'Unit 2',
            'item_no' => 'ITM-A-B1',
            'part_name' => 'BOP Part A',
            'standard_part_no' => 'PART-A-BOP-01',
            'part_type' => 'BOP',
        ]);
        BomRequirement::create(['bom_item_id' => $bopA->id, 'side' => 'COMMON', 'required_quantity' => 8]);

        $stdA = BomItem::create([
            'project_id' => $projectA->id,
            'jig_no' => 'JIG-A-STD',
            'unit_no' => 'Unit 3',
            'item_no' => 'ITM-A-S1',
            'part_name' => 'STD Part A',
            'standard_part_no' => 'PART-A-STD-01',
            'part_type' => 'STD',
        ]);
        BomRequirement::create(['bom_item_id' => $stdA->id, 'side' => 'COMMON', 'required_quantity' => 20]);

        // Project B: Single-Type ONLY (MFG only, 0 BOP, 0 STD)
        $projectB = Project::create([
            'name' => 'Project B Single-Type MFG',
            'project_code' => 'TEST-PROJ-B-' . uniqid(),
            'status' => 'active',
        ]);
        $mfgB = BomItem::create([
            'project_id' => $projectB->id,
            'jig_no' => 'JIG-B-MFG',
            'unit_no' => 'Unit 1',
            'item_no' => 'ITM-B-M1',
            'part_name' => 'MFG Part B',
            'standard_part_no' => 'PART-B-MFG-01',
            'part_type' => 'MFG',
        ]);
        BomRequirement::create(['bom_item_id' => $mfgB->id, 'side' => 'RH', 'required_quantity' => 14]);

        // 1. Verify Project A in ALL mode contains parts for all 3 types
        $resA_All = $this->actingAs($admin, 'sanctum')->getJson('/api/v1/dashboard/project-hierarchy?project_id=' . $projectA->id);
        $resA_All->assertStatus(200);
        $dataA_All = $resA_All->json();
        $this->assertNotEmpty($dataA_All['mfg_section']['jigs'][0]['units'][0]['sides']['LH']['parts']);
        $this->assertNotEmpty($dataA_All['bop_section']['jigs'][0]['units'][0]['sides']['COMMON']['parts']);
        $this->assertNotEmpty($dataA_All['std_section']['jigs'][0]['units'][0]['sides']['COMMON']['parts']);

        // 2. Verify Project A in MFG mode preserves exact source part PART-A-MFG-01 with zero BOP/STD cross-contamination
        $resA_Mfg = $this->actingAs($admin, 'sanctum')->getJson('/api/v1/dashboard/project-hierarchy?project_id=' . $projectA->id . '&part_type=MFG');
        $resA_Mfg->assertStatus(200);
        $dataA_Mfg = $resA_Mfg->json();
        $this->assertCount(1, $dataA_Mfg['mfg_section']['jigs']);
        $this->assertCount(0, $dataA_Mfg['bop_section']['jigs']);
        $this->assertCount(0, $dataA_Mfg['std_section']['jigs']);
        $partMfg = $dataA_Mfg['mfg_section']['jigs'][0]['units'][0]['sides']['LH']['parts'][0];
        $this->assertEquals('PART-A-MFG-01', $partMfg['standard_part_no']);
        $this->assertEquals('MFG', $partMfg['part_type']);
        $this->assertEquals(12, $partMfg['required_qty']);

        // 3. Verify Project A in BOP mode preserves exact source part PART-A-BOP-01 with zero MFG/STD cross-contamination
        $resA_Bop = $this->actingAs($admin, 'sanctum')->getJson('/api/v1/dashboard/project-hierarchy?project_id=' . $projectA->id . '&part_type=BOP');
        $resA_Bop->assertStatus(200);
        $dataA_Bop = $resA_Bop->json();
        $this->assertCount(0, $dataA_Bop['mfg_section']['jigs']);
        $this->assertCount(1, $dataA_Bop['bop_section']['jigs']);
        $this->assertCount(0, $dataA_Bop['std_section']['jigs']);
        $partBop = $dataA_Bop['bop_section']['jigs'][0]['units'][0]['sides']['COMMON']['parts'][0];
        $this->assertEquals('PART-A-BOP-01', $partBop['standard_part_no']);
        $this->assertEquals('BOP', $partBop['part_type']);
        $this->assertEquals(8, $partBop['required_qty']);

        // 4. Verify Project A in STD mode preserves exact source part PART-A-STD-01 with zero MFG/BOP cross-contamination
        $resA_Std = $this->actingAs($admin, 'sanctum')->getJson('/api/v1/dashboard/project-hierarchy?project_id=' . $projectA->id . '&part_type=STD');
        $resA_Std->assertStatus(200);
        $dataA_Std = $resA_Std->json();
        $this->assertCount(0, $dataA_Std['mfg_section']['jigs']);
        $this->assertCount(0, $dataA_Std['bop_section']['jigs']);
        $this->assertCount(1, $dataA_Std['std_section']['jigs']);
        $partStd = $dataA_Std['std_section']['jigs'][0]['units'][0]['sides']['COMMON']['parts'][0];
        $this->assertEquals('PART-A-STD-01', $partStd['standard_part_no']);
        $this->assertEquals('STD', $partStd['part_type']);
        $this->assertEquals(20, $partStd['required_qty']);

        // 5. Switching to Project B with part_type=BOP produces safe 0 jigs without 500 error
        $resB_Bop = $this->actingAs($admin, 'sanctum')->getJson('/api/v1/dashboard/project-hierarchy?project_id=' . $projectB->id . '&part_type=BOP');
        $resB_Bop->assertStatus(200);
        $dataB_Bop = $resB_Bop->json();
        $this->assertCount(0, $dataB_Bop['jigs']);
        $this->assertCount(0, $dataB_Bop['bop_section']['jigs']);

        // 6. Switching to Project B with part_type=MFG correctly renders Project B parts
        $resB_Mfg = $this->actingAs($admin, 'sanctum')->getJson('/api/v1/dashboard/project-hierarchy?project_id=' . $projectB->id . '&part_type=MFG');
        $resB_Mfg->assertStatus(200);
        $dataB_Mfg = $resB_Mfg->json();
        $this->assertCount(1, $dataB_Mfg['mfg_section']['jigs']);
        $partB_Mfg = $dataB_Mfg['mfg_section']['jigs'][0]['units'][0]['sides']['RH']['parts'][0];
        $this->assertEquals('PART-B-MFG-01', $partB_Mfg['standard_part_no']);
        $this->assertEquals(14, $partB_Mfg['required_qty']);
    }

    public function test_individual_type_view_ui_contract_and_expansion_invariants()
    {
        $dashboardVuePath = resource_path('js/views/Dashboard.vue');
        $this->assertFileExists($dashboardVuePath);

        $vueContent = file_get_contents($dashboardVuePath);

        // Invariant 1: Sticky section header must only render in ALL mode, never in individual MFG/BOP/STD views
        $this->assertStringContainsString('v-if="activeHierarchyBomType === \'ALL\'"', $vueContent);
        $this->assertStringContainsString('hierarchy-panel-header-sticky', $vueContent);

        // Invariant 2: Switching BOM type via setBomViewType resets expanded jigs & units
        $this->assertMatchesRegularExpression('/setBomViewType\s*=\s*\(type\)\s*=>\s*\{.*?expandedJigs\.value\s*=\s*\{\};.*?expandedUnits\.value\s*=\s*\{\};.*?fetchData/s', $vueContent);

        // Invariant 3: Switching BOM type via setHierarchyBomType resets expanded jigs & units
        $this->assertMatchesRegularExpression('/setHierarchyBomType\s*=\s*\(type\)\s*=>\s*\{.*?expandedJigs\.value\s*=\s*\{\};.*?expandedUnits\.value\s*=\s*\{\};.*?fetchProjectHierarchy/s', $vueContent);

        // Invariant 4: applyHierarchy must NOT auto-expand jigs or units
        preg_match('/applyHierarchy\s*=\s*\(data\)\s*=>\s*\{(.*?)\};/s', $vueContent, $matches);
        $applyHierarchyBody = $matches[1] ?? '';
        $this->assertNotEmpty($applyHierarchyBody);
        $this->assertStringNotContainsString('expandedJigs.value', $applyHierarchyBody);
        $this->assertStringNotContainsString('expandedUnits.value', $applyHierarchyBody);

        // Invariant 5: Expand All & Collapse All handlers exist and handle both jigs & units
        $this->assertStringContainsString('expandedJigs.value[`${sec.key}_${j.jig_name}`] = true;', $vueContent);
        $this->assertStringContainsString('expandedUnits.value[`${sec.key}_${j.jig_name}_${u.unit_no}`] = true;', $vueContent);
        $this->assertMatchesRegularExpression('/collapseAllJigs\s*=\s*\(\)\s*=>\s*\{.*?expandedJigs\.value\s*=\s*\{\};.*?expandedUnits\.value\s*=\s*\{\};/s', $vueContent);
    }

    public function test_all_types_hierarchy_unifies_shared_jigs_and_units_into_single_nodes()
    {
        $admin = $this->getAdminUser();

        $project = Project::create([
            'name' => 'Project Shared Jig Multi-Type',
            'project_code' => 'TEST-UNIFIED-' . uniqid(),
            'status' => 'active',
        ]);

        // Same Jig JIG-COMMON-01 and Unit Unit 1 across all 3 BOM types
        $mfg = BomItem::create([
            'project_id' => $project->id,
            'jig_no' => 'JIG-COMMON-01',
            'unit_no' => 'Unit 1',
            'item_no' => 'ITM-M-01',
            'part_name' => 'Manufacturing Clamp',
            'standard_part_no' => 'PART-UNI-MFG-01',
            'part_type' => 'MFG',
        ]);
        BomRequirement::create(['bom_item_id' => $mfg->id, 'side' => 'LH', 'required_quantity' => 10]);

        $bop = BomItem::create([
            'project_id' => $project->id,
            'jig_no' => 'JIG-COMMON-01',
            'unit_no' => 'Unit 1',
            'item_no' => 'ITM-B-01',
            'part_name' => 'Bought Out Sensor',
            'standard_part_no' => 'PART-UNI-BOP-01',
            'part_type' => 'BOP',
        ]);
        BomRequirement::create(['bom_item_id' => $bop->id, 'side' => 'LH', 'required_quantity' => 5]);

        $std = BomItem::create([
            'project_id' => $project->id,
            'jig_no' => 'JIG-COMMON-01',
            'unit_no' => 'Unit 1',
            'item_no' => 'ITM-S-01',
            'part_name' => 'Standard Fastener',
            'standard_part_no' => 'PART-UNI-STD-01',
            'part_type' => 'STD',
        ]);
        BomRequirement::create(['bom_item_id' => $std->id, 'side' => 'LH', 'required_quantity' => 20]);

        // Fetch hierarchy with All Types
        $res = $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/dashboard/project-hierarchy?project_id=' . $project->id);

        $res->assertStatus(200);
        $data = $res->json();

        // 1. Unified 'jigs' array must contain JIG-COMMON-01 exactly once
        $this->assertArrayHasKey('jigs', $data);
        $jigs = $data['jigs'];
        $jigOccurrences = array_filter($jigs, fn($j) => $j['jig_name'] === 'JIG-COMMON-01');
        $this->assertCount(1, $jigOccurrences, 'Jig JIG-COMMON-01 must appear exactly once in unified hierarchy');

        $unifiedJig = array_values($jigOccurrences)[0];
        $this->assertEquals(35, $unifiedJig['total_required']); // 10 + 5 + 20

        // 2. Units inside JIG-COMMON-01 must contain Unit 01 (normalized) exactly once
        $this->assertArrayHasKey('units', $unifiedJig);
        $unitOccurrences = array_filter($unifiedJig['units'], fn($u) => in_array($u['unit_no'], ['Unit 1', 'Unit 01']));
        $this->assertCount(1, $unitOccurrences, 'Unit 1 / Unit 01 must appear exactly once inside JIG-COMMON-01');

        $unifiedUnit = array_values($unitOccurrences)[0];
        $this->assertArrayHasKey('sides', $unifiedUnit);
        $lhParts = $unifiedUnit['sides']['LH']['parts'] ?? [];
        $this->assertCount(3, $lhParts, 'Unit 1 LH side must contain all 3 parts (MFG, BOP, STD)');

        $partTypes = array_map(fn($p) => $p['part_type'], $lhParts);
        $this->assertContains('MFG', $partTypes);
        $this->assertContains('BOP', $partTypes);
        $this->assertContains('STD', $partTypes);

        // 3. API compatibility: mfg_section, bop_section, std_section keys still present
        $this->assertArrayHasKey('mfg_section', $data);
        $this->assertArrayHasKey('bop_section', $data);
        $this->assertArrayHasKey('std_section', $data);
        $this->assertCount(1, $data['mfg_section']['jigs']);
        $this->assertCount(1, $data['bop_section']['jigs']);
        $this->assertCount(1, $data['std_section']['jigs']);
    }

    public function test_all_types_dashboard_ui_tree_and_part_columns_structure()
    {
        $dashboardVuePath = resource_path('js/views/Dashboard.vue');
        $this->assertFileExists($dashboardVuePath);

        $vueContent = file_get_contents($dashboardVuePath);

        // 1. Unified hierarchy container is rendered when activeHierarchyBomType is 'ALL'
        $this->assertStringContainsString("v-else-if=\"activeHierarchyBomType === 'ALL'\"", $vueContent);
        $this->assertStringContainsString('unified-hierarchy-container', $vueContent);

        // 2. Unified hierarchy iterates directly over hierarchyData.jigs (one Jig per node)
        $this->assertStringContainsString('v-for="jig in hierarchyData.jigs"', $vueContent);

        // 3. Unified hierarchy contains 3 side-by-side columns: MFG, BOP, STD
        $this->assertStringContainsString('all-types-parts-columns', $vueContent);
        $this->assertStringContainsString('COLUMN 1: MFG PARTS', $vueContent);
        $this->assertStringContainsString('COLUMN 2: BOP PARTS', $vueContent);
        $this->assertStringContainsString('COLUMN 3: STD PARTS', $vueContent);

        // 4. Empty states for each BOM type column
        $this->assertStringContainsString('No MFG Parts', $vueContent);
        $this->assertStringContainsString('No BOP Parts', $vueContent);
        $this->assertStringContainsString('No STD Parts', $vueContent);

        // 5. getUnitPartsByType helper is defined in script
        $this->assertMatchesRegularExpression('/getUnitPartsByType\s*=\s*\(unit,\s*unitKey,\s*type\)\s*=>/s', $vueContent);

        // 6. Expand all jigs supports ALL mode
        $this->assertStringContainsString("if (activeHierarchyBomType.value === 'ALL')", $vueContent);
        $this->assertStringContainsString('expandedJigs.value[`ALL_${j.jig_name}`] = true;', $vueContent);
        $this->assertStringContainsString('expandedUnits.value[`ALL_${j.jig_name}_${u.unit_no}`] = true;', $vueContent);

        // 7. Clear black structural borders are applied to bom-type-column
        $this->assertStringContainsString('border: 1.5px solid #0f172a !important;', $vueContent);
    }

    public function test_mfg_parts_remain_visible_across_all_downstream_workflow_states_and_unit_normalization()
    {
        $admin = $this->getAdminUser();
        $project = Project::create([
            'name' => 'Downstream Workflow Test Project',
            'project_code' => 'TEST-PROJ-DOWNSTREAM',
            'description' => 'Test MFG part visibility in Paint, Assembly, Completed',
            'status' => 'active',
        ]);

        // Create MFG parts in unit '04' in various states
        $states = [
            ['part_no' => 'PART-PENDING', 'state' => 'pending'],
            ['part_no' => 'PART-STORE', 'state' => 'store'],
            ['part_no' => 'PART-QC', 'state' => 'qc'],
            ['part_no' => 'PART-REWORK', 'state' => 'rework'],
            ['part_no' => 'PART-PAINT', 'state' => 'paint'],
            ['part_no' => 'PART-ASSEMBLY', 'state' => 'assembly'],
            ['part_no' => 'PART-COMPLETED', 'state' => 'completed'],
        ];

        $receiptBatch = \App\Models\Receipt::create([
            'project_id' => $project->id,
            'received_by' => $admin->id,
            'receipt_number' => 'REC-TEST-DOWNSTREAM',
        ]);

        foreach ($states as $s) {
            $mfgItem = BomItem::create([
                'project_id' => $project->id,
                'jig_no' => 'JIG-TEST-01',
                'unit_no' => '04', // Raw 2-digit zero padded
                'standard_part_no' => $s['part_no'],
                'part_type' => 'MFG',
            ]);
            BomRequirement::create(['bom_item_id' => $mfgItem->id, 'side' => 'COMMON', 'required_quantity' => 1]);

            if ($s['state'] !== 'pending') {
                $recItem = \App\Models\ReceiptItem::create([
                    'receipt_id' => $receiptBatch->id,
                    'bom_item_id' => $mfgItem->id,
                    'side' => 'COMMON',
                    'received_quantity' => 1,
                    'status' => match ($s['state']) {
                        'store' => 'received',
                        'qc' => 'qc_received',
                        default => 'qc_approved',
                    },
                ]);

                if ($s['state'] === 'rework') {
                    $qc = \App\Models\QcInspection::create([
                        'bom_item_id' => $mfgItem->id,
                        'receipt_item_id' => $recItem->id,
                        'side' => 'COMMON',
                        'result' => 'rework',
                        'inspected_quantity' => 1,
                        'approved_quantity' => 0,
                        'rework_quantity' => 1,
                        'rejected_quantity' => 0,
                        'inspected_by' => $admin->id,
                    ]);
                    \App\Models\ReworkRecord::create([
                        'bom_item_id' => $mfgItem->id,
                        'qc_inspection_id' => $qc->id,
                        'side' => 'COMMON',
                        'quantity' => 1,
                        'status' => 'pending',
                    ]);
                } elseif (in_array($s['state'], ['paint', 'assembly', 'completed'])) {
                    $qc = \App\Models\QcInspection::create([
                        'bom_item_id' => $mfgItem->id,
                        'receipt_item_id' => $recItem->id,
                        'side' => 'COMMON',
                        'result' => 'approved',
                        'inspected_quantity' => 1,
                        'approved_quantity' => 1,
                        'rework_quantity' => 0,
                        'rejected_quantity' => 0,
                        'destination' => 'PAINT',
                        'inspected_by' => $admin->id,
                    ]);

                    if ($s['state'] === 'paint') {
                        \App\Models\PaintRecord::create([
                            'bom_item_id' => $mfgItem->id,
                            'qc_inspection_id' => $qc->id,
                            'side' => 'COMMON',
                            'quantity' => 1,
                            'status' => 'in_progress',
                        ]);
                    } elseif ($s['state'] === 'assembly') {
                        $paint = \App\Models\PaintRecord::create([
                            'bom_item_id' => $mfgItem->id,
                            'qc_inspection_id' => $qc->id,
                            'side' => 'COMMON',
                            'quantity' => 1,
                            'status' => 'completed',
                        ]);
                        \App\Models\AssemblyRecord::create([
                            'bom_item_id' => $mfgItem->id,
                            'paint_record_id' => $paint->id,
                            'qc_inspection_id' => $qc->id,
                            'side' => 'COMMON',
                            'quantity' => 1,
                            'status' => 'in_progress',
                        ]);
                    } elseif ($s['state'] === 'completed') {
                        $paint = \App\Models\PaintRecord::create([
                            'bom_item_id' => $mfgItem->id,
                            'qc_inspection_id' => $qc->id,
                            'side' => 'COMMON',
                            'quantity' => 1,
                            'status' => 'assembled',
                        ]);
                        \App\Models\AssemblyRecord::create([
                            'bom_item_id' => $mfgItem->id,
                            'paint_record_id' => $paint->id,
                            'qc_inspection_id' => $qc->id,
                            'side' => 'COMMON',
                            'quantity' => 1,
                            'status' => 'completed',
                        ]);
                    }
                }
            }
        }

        // Create BOP & STD items in raw unit '4' (single-digit) in same Jig
        $bopItem = BomItem::create([
            'project_id' => $project->id,
            'jig_no' => 'JIG-TEST-01',
            'unit_no' => '4', // Single digit to test unit normalization
            'standard_part_no' => 'BOP-SENSOR-01',
            'part_type' => 'BOP',
        ]);
        BomRequirement::create(['bom_item_id' => $bopItem->id, 'side' => 'COMMON', 'required_quantity' => 2]);

        $stdItem = BomItem::create([
            'project_id' => $project->id,
            'jig_no' => 'JIG-TEST-01',
            'unit_no' => '4', // Single digit
            'standard_part_no' => 'STD-BOLT-01',
            'part_type' => 'STD',
        ]);
        BomRequirement::create(['bom_item_id' => $stdItem->id, 'side' => 'COMMON', 'required_quantity' => 10]);

        // Request project hierarchy
        $res = $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/dashboard/project-hierarchy?project_id=' . $project->id);

        $res->assertStatus(200);
        $data = $res->json();

        // 1. Jig JIG-TEST-01 must have exactly 1 unit node ('Unit 04')
        $jig = $data['jigs'][0];
        $this->assertEquals('JIG-TEST-01', $jig['jig_name']);
        $this->assertCount(1, $jig['units'], 'Units with raw 04 and 4 must normalize into a single Unit 04');
        $this->assertEquals('Unit 04', $jig['units'][0]['unit_no']);

        // 2. Unit 04 parts must contain ALL 7 MFG parts + 1 BOP + 1 STD = 9 parts total
        $unit = $jig['units'][0];
        $commonParts = $unit['sides']['COMMON']['parts'];
        $this->assertCount(9, $commonParts);

        // 3. Verify status badges for each MFG part
        $partsByNo = collect($commonParts)->keyBy('standard_part_no');
        $this->assertEquals('Pending', $partsByNo['PART-PENDING']['status_badge']);
        $this->assertEquals('QC', $partsByNo['PART-STORE']['status_badge']); // Store intake is in QC Arrival queue
        $this->assertEquals('QC', $partsByNo['PART-QC']['status_badge']);
        $this->assertEquals('Rework', $partsByNo['PART-REWORK']['status_badge']);
        $this->assertEquals('Paint', $partsByNo['PART-PAINT']['status_badge']);
        $this->assertEquals('Assembly', $partsByNo['PART-ASSEMBLY']['status_badge']);
        $this->assertEquals('Completed', $partsByNo['PART-COMPLETED']['status_badge']);

        // 4. Verify part_type partitioning: 7 MFG, 1 BOP, 1 STD
        $mfgParts = array_filter($commonParts, fn($p) => $p['part_type'] === 'MFG');
        $bopParts = array_filter($commonParts, fn($p) => $p['part_type'] === 'BOP');
        $stdParts = array_filter($commonParts, fn($p) => $p['part_type'] === 'STD');
        $this->assertCount(7, $mfgParts);
        $this->assertCount(1, $bopParts);
        $this->assertCount(1, $stdParts);
    }

    public function test_jig_card_status_metrics_and_frontend_table_column_structure()
    {
        $admin = $this->getAdminUser();

        $project = Project::create([
            'name' => 'Jig Status Metrics Test Project',
            'project_code' => 'JIG-STAT-' . uniqid(),
            'status' => 'active',
        ]);

        $item = BomItem::create([
            'project_id' => $project->id,
            'jig_no' => 'JIG-ST-01',
            'unit_no' => 'Unit 01',
            'item_no' => 'ITEM-REF-101',
            'supplier_name_raw' => 'Vendor Alpha',
            'standard_part_no' => 'MFG-ST-01',
            'part_type' => 'MFG',
        ]);
        BomRequirement::create([
            'bom_item_id' => $item->id,
            'side' => 'COMMON',
            'required_quantity' => 20,
        ]);

        $res = $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/dashboard/project-hierarchy?project_id=' . $project->id);

        $res->assertStatus(200);
        $data = $res->json();
        $this->assertNotEmpty($data['jigs']);
        $jig = $data['jigs'][0];

        // 1. Authoritative Jig metrics available
        $this->assertArrayHasKey('metrics', $jig);
        $this->assertArrayHasKey('parts_in_store', $jig['metrics']);
        $this->assertArrayHasKey('qc_pending_arrival', $jig['metrics']);
        $this->assertArrayHasKey('qc_pending_inspection', $jig['metrics']);
        $this->assertArrayHasKey('rework_pending', $jig['metrics']);
        $this->assertArrayHasKey('assembly_completed', $jig['metrics']);

        // 2. Underlying Item No and Supplier data remain intact
        $this->assertEquals('ITEM-REF-101', $item->fresh()->item_no);
        $this->assertEquals('Vendor Alpha', $item->fresh()->supplier_name_raw);

        // 3. Frontend Dashboard.vue template checks
        $vueFile = resource_path('js/views/Dashboard.vue');
        $this->assertFileExists($vueFile);
        $vueContent = file_get_contents($vueFile);

        // Jig card status badges present
        $this->assertStringContainsString('title="Parts in Store Bay"', $vueContent);
        $this->assertStringContainsString('title="Parts in QC (Arrival & Inspection)"', $vueContent);
        $this->assertStringContainsString('title="Parts in Rework Queue"', $vueContent);
        $this->assertStringContainsString('fa-warehouse', $vueContent);
        $this->assertStringContainsString('fa-clipboard-check', $vueContent);
        $this->assertStringContainsString('fa-tools', $vueContent);

        // MFG/BOP/STD tables do not contain ITEM NO / SUPPLIER in table headers
        $this->assertStringNotContainsString('<th style="color: #fff; background-color: #0f172a; text-align: left; padding: 3px 5px;">ITEM NO</th>', $vueContent);
        $this->assertStringNotContainsString('<th style="color: #fff; background-color: #0f172a; text-align: left; padding: 3px 5px;">SUPPLIER</th>', $vueContent);
    }
}


