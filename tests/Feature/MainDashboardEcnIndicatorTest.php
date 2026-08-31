<?php

namespace Tests\Feature;

use App\Models\BomItem;
use App\Models\EcnRequirement;
use App\Models\Project;
use App\Models\User;
use App\Services\HierarchyService;
use Tests\TestCase;

use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;

class MainDashboardEcnIndicatorTest extends TestCase
{
    protected User $manager;
    protected Project $testProject;

    protected function setUp(): void
    {
        parent::setUp();

        $role = Role::firstOrCreate(['name' => 'MANAGER', 'guard_name' => 'web']);
        $this->manager = User::firstOrCreate(
            ['email' => 'manager_test@sparetrack.internal'],
            [
                'name' => 'Test Manager',
                'password' => bcrypt('password'),
            ]
        );
        if (!$this->manager->hasRole('MANAGER')) {
            $this->manager->assignRole($role);
        }
        Sanctum::actingAs($this->manager);

        $this->testProject = Project::create([
            'name' => 'Main Dashboard ECN Indicator Test Project',
            'project_code' => 'TEST-DASH-ECN-' . uniqid(),
            'status' => 'active',
        ]);
    }

    public function test_main_dashboard_hierarchy_returns_scoped_ecn_indicators_and_pure_regular_parts()
    {
        // 1. Create Regular BOM items across 2 Jigs:
        // Jig A (JIG-100): Unit 01 (LH & RH), Unit 02 (LH & RH)
        // Jig B (JIG-200): Unit 03 (LH & RH)
        $jigAItem1 = BomItem::create([
            'project_id' => $this->testProject->id,
            'standard_part_no' => 'REG-A-01-L',
            'jig_no' => 'JIG-100',
            'unit_no' => '01',
        ]);
        \App\Models\BomRequirement::create([
            'bom_item_id' => $jigAItem1->id,
            'side' => 'LH',
            'required_quantity' => 10,
        ]);

        $jigAItem2 = BomItem::create([
            'project_id' => $this->testProject->id,
            'standard_part_no' => 'REG-A-01-R',
            'jig_no' => 'JIG-100',
            'unit_no' => '01',
        ]);
        \App\Models\BomRequirement::create([
            'bom_item_id' => $jigAItem2->id,
            'side' => 'RH',
            'required_quantity' => 5,
        ]);

        $jigAItem3 = BomItem::create([
            'project_id' => $this->testProject->id,
            'standard_part_no' => 'REG-A-02',
            'jig_no' => 'JIG-100',
            'unit_no' => '02',
        ]);
        \App\Models\BomRequirement::create([
            'bom_item_id' => $jigAItem3->id,
            'side' => 'LH',
            'required_quantity' => 8,
        ]);

        $jigBItem1 = BomItem::create([
            'project_id' => $this->testProject->id,
            'standard_part_no' => 'REG-B-03',
            'jig_no' => 'JIG-200',
            'unit_no' => '03',
        ]);
        \App\Models\BomRequirement::create([
            'bom_item_id' => $jigBItem1->id,
            'side' => 'RH',
            'required_quantity' => 12,
        ]);

        // 2. Create ECN items strictly in Jig A, Unit 01, LH side only!
        $ecnReq1 = EcnRequirement::create([
            'project_id' => $this->testProject->id,
            'ecn_number' => 'ECN-TEST-1',
            'jig_no' => 'JIG-100',
            'unit_no' => '01',
            'part_no' => 'ECN-PART-99',
            'side' => 'LA',
            'side_display' => 'LH',
            'required_qty' => 2,
            'received_qty' => 0,
            'current_state' => 'PENDING',
        ]);

        // 3. Request project hierarchy as Manager (Main Dashboard)
        $response = $this->actingAs($this->manager)
            ->getJson("/api/v1/dashboard/project-hierarchy?project_id={$this->testProject->id}");

        $response->assertStatus(200);
        $data = $response->json();

        $this->assertArrayHasKey('jigs', $data);
        $jigs = collect($data['jigs']);

        // Check Jig A (JIG-100) vs Jig B (JIG-200)
        $jigA = $jigs->firstWhere('jig_name', 'JIG-100');
        $jigB = $jigs->firstWhere('jig_name', 'JIG-200');

        $this->assertNotNull($jigA, 'Jig A must exist');
        $this->assertNotNull($jigB, 'Jig B must exist');

        // Jig A contains ECN -> ecn_present must be true
        $this->assertTrue((bool)$jigA['ecn_present'], 'Jig A must indicate ECN present');
        $this->assertGreaterThan(0, $jigA['ecn_count'], 'Jig A ecn_count must be > 0');

        // Jig B does NOT contain ECN -> ecn_present must be false
        $this->assertFalse((bool)$jigB['ecn_present'], 'Jig B must NOT indicate ECN present');
        $this->assertEquals(0, $jigB['ecn_count'], 'Jig B ecn_count must be 0');

        // Check Units in Jig A
        $unitsA = collect($jigA['units']);
        $unit01 = $unitsA->firstWhere('unit_no', 'Unit 01');
        $unit02 = $unitsA->firstWhere('unit_no', 'Unit 02');

        $this->assertNotNull($unit01, 'Unit 01 must exist');
        $this->assertNotNull($unit02, 'Unit 02 must exist');

        // Unit 01 contains ECN -> ecn_present must be true
        $this->assertTrue((bool)$unit01['ecn_present'], 'Unit 01 must indicate ECN present');
        $this->assertGreaterThan(0, $unit01['ecn_count'], 'Unit 01 ecn_count must be > 0');

        // Unit 02 does NOT contain ECN -> ecn_present must be false
        $this->assertFalse((bool)$unit02['ecn_present'], 'Unit 02 must NOT indicate ECN present');
        $this->assertEquals(0, $unit02['ecn_count'], 'Unit 02 ecn_count must be 0');

        // Check Side Breakdown in Unit 01: LH vs RH
        $lhSide = $unit01['sides']['LH'];
        $rhSide = $unit01['sides']['RH'];

        // LH contains ECN -> ecn_present must be true
        $this->assertTrue((bool)$lhSide['ecn_present'], 'Unit 01 LH side must indicate ECN present');
        $this->assertGreaterThan(0, $lhSide['ecn_count'], 'Unit 01 LH ecn_count must be > 0');

        // RH does NOT contain ECN -> ecn_present must be false
        $this->assertFalse((bool)$rhSide['ecn_present'], 'Unit 01 RH side must NOT indicate ECN present');
        $this->assertEquals(0, $rhSide['ecn_count'], 'Unit 01 RH ecn_count must be 0');

        // Check that Regular part listings in Main Dashboard DO NOT contain ECN parts!
        $unit01Parts = $unit01['parts'];
        foreach ($unit01Parts as $part) {
            $isEcn = is_array($part) ? ($part['is_ecn'] ?? false) : ($part->is_ecn ?? false);
            $this->assertFalse((bool)$isEcn, 'Main dashboard unit part list must contain ONLY regular BOM items');
        }

        $lhParts = $lhSide['parts'];
        foreach ($lhParts as $part) {
            $isEcn = is_array($part) ? ($part['is_ecn'] ?? false) : ($part->is_ecn ?? false);
            $this->assertFalse((bool)$isEcn, 'Main dashboard LH part list must contain ONLY regular BOM items');
        }

        // Verify required quantities are NOT combined
        $this->assertEquals(10, $lhSide['total_required'], 'LH required quantity must strictly match Regular BOM');
        $this->assertEquals(5, $rhSide['total_required'], 'RH required quantity must strictly match Regular BOM');
    }

    public function test_rh_only_ecn_indicates_ecn_on_rh_side_and_not_on_lh()
    {
        // Regular items
        $item1 = BomItem::create([
            'project_id' => $this->testProject->id,
            'standard_part_no' => 'REG-RH-TEST',
            'jig_no' => 'JIG-300',
            'unit_no' => '05',
        ]);
        \App\Models\BomRequirement::create([
            'bom_item_id' => $item1->id,
            'side' => 'RH',
            'required_quantity' => 4,
        ]);

        $item2 = BomItem::create([
            'project_id' => $this->testProject->id,
            'standard_part_no' => 'REG-LH-TEST',
            'jig_no' => 'JIG-300',
            'unit_no' => '05',
        ]);
        \App\Models\BomRequirement::create([
            'bom_item_id' => $item2->id,
            'side' => 'LH',
            'required_quantity' => 6,
        ]);

        // ECN on RH side (RA)
        EcnRequirement::create([
            'project_id' => $this->testProject->id,
            'ecn_number' => 'ECN-RH-ONLY',
            'jig_no' => 'JIG-300',
            'unit_no' => '05',
            'part_no' => 'ECN-RH-PART',
            'side' => 'RA',
            'side_display' => 'RH',
            'required_qty' => 1,
            'received_qty' => 0,
            'current_state' => 'PENDING',
        ]);

        $response = $this->getJson("/api/v1/dashboard/project-hierarchy?project_id={$this->testProject->id}");
        $response->assertStatus(200);
        $hierarchy = $response->json();

        $jigs = collect($hierarchy['jigs']);
        $jig = $jigs->firstWhere('jig_name', 'JIG-300');
        $this->assertNotNull($jig);
        $this->assertTrue((bool)$jig['ecn_present']);

        $units = collect($jig['units']);
        $unit = $units->firstWhere('unit_no', 'Unit 05');
        $this->assertNotNull($unit);
        $this->assertTrue((bool)$unit['ecn_present']);

        // RH must be true, LH must be false
        $this->assertTrue((bool)$unit['sides']['RH']['ecn_present']);
        $this->assertFalse((bool)$unit['sides']['LH']['ecn_present']);
        $this->assertGreaterThan(0, $unit['sides']['RH']['ecn_count']);
        $this->assertEquals(0, $unit['sides']['LH']['ecn_count']);
    }

    public function test_assembled_completed_ecn_label_vanishes_from_dashboard()
    {
        $item = BomItem::create([
            'project_id' => $this->testProject->id,
            'standard_part_no' => 'REG-VANISH-TEST',
            'jig_no' => 'JIG-VANISH',
            'unit_no' => '09',
        ]);
        \App\Models\BomRequirement::create([
            'bom_item_id' => $item->id,
            'side' => 'LH',
            'required_quantity' => 2,
        ]);

        $ecnReq = EcnRequirement::create([
            'project_id' => $this->testProject->id,
            'ecn_number' => 'ECN-VANISH-1',
            'jig_no' => 'JIG-VANISH',
            'unit_no' => '09',
            'part_no' => 'ECN-VANISH-PART',
            'side' => 'LA',
            'side_display' => 'LH',
            'required_qty' => 1,
            'received_qty' => 1,
            'current_state' => 'ASSEMBLY',
        ]);

        // When current_state is ASSEMBLY, ECN is still active -> ecn_present is true
        $res1 = $this->getJson("/api/v1/dashboard/project-hierarchy?project_id={$this->testProject->id}");
        $res1->assertStatus(200);
        $jig1 = collect($res1->json()['jigs'])->firstWhere('jig_name', 'JIG-VANISH');
        $unit1 = collect($jig1['units'])->firstWhere('unit_no', 'Unit 09');
        $this->assertTrue((bool)$jig1['ecn_present']);
        $this->assertTrue((bool)$unit1['ecn_present']);
        $this->assertTrue((bool)$unit1['sides']['LH']['ecn_present']);

        // Now mark ECN requirement as ASSEMBLY_COMPLETED
        $ecnReq->current_state = 'ASSEMBLY_COMPLETED';
        $ecnReq->save();

        // ECN label must vanish from Jig, Unit, and LH side cards
        $res2 = $this->getJson("/api/v1/dashboard/project-hierarchy?project_id={$this->testProject->id}");
        $res2->assertStatus(200);
        $jig2 = collect($res2->json()['jigs'])->firstWhere('jig_name', 'JIG-VANISH');
        $unit2 = collect($jig2['units'])->firstWhere('unit_no', 'Unit 09');
        $this->assertFalse((bool)$jig2['ecn_present'], 'Jig ECN label must vanish when ECN is assembled completed');
        $this->assertFalse((bool)$unit2['ecn_present'], 'Unit ECN label must vanish when ECN is assembled completed');
        $this->assertFalse((bool)$unit2['sides']['LH']['ecn_present'], 'LH ECN label must vanish when ECN is assembled completed');
        $this->assertEquals(0, $jig2['ecn_count']);
        $this->assertEquals(0, $unit2['ecn_count']);
        $this->assertEquals(0, $unit2['sides']['LH']['ecn_count']);
    }
}
