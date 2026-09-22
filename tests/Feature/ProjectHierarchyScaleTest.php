<?php

namespace Tests\Feature;

use App\Models\BomItem;
use App\Models\BomRequirement;
use App\Models\Project;
use App\Models\Receipt;
use App\Models\ReceiptItem;
use App\Models\AssemblyRecord;
use App\Models\User;
use App\Services\HierarchyService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ProjectHierarchyScaleTest extends TestCase
{
    use DatabaseTransactions;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $role = Role::firstOrCreate(['name' => 'ADMIN', 'guard_name' => 'web']);
        $this->admin = User::create([
            'name' => 'Admin Scale User',
            'email' => 'admin_scale_' . uniqid() . '@example.com',
            'password' => bcrypt('secret'),
        ]);
        $this->admin->assignRole($role);
    }

    /**
     * Test that a project with 6,254 parts (FA-285 production scale) generates
     * hierarchy successfully without exhausting the 128MB memory limit and with 0 parts dropped.
     */
    public function test_fa285_scale_hierarchy_runs_within_memory_limit_and_drops_zero_parts(): void
    {
        $project = Project::create([
            'name' => 'FA-285 Massive Project ' . uniqid(),
            'project_code' => 'FA-285-' . uniqid(),
            'status' => 'active',
        ]);

        $jigNames = ['JIG-01', 'JIG-02', 'JIG-03', 'JIG-04', 'JIG-05'];
        $units = ['Unit 01', 'Unit 02', 'Unit 03', 'Unit 04'];
        $sides = ['LH', 'RH', 'COMMON'];

        $targetParts = 6254;
        $bomItemsData = [];
        $now = now();

        for ($i = 1; $i <= $targetParts; $i++) {
            $jig = $jigNames[($i - 1) % count($jigNames)];
            $unit = $units[(int)(($i - 1) / count($jigNames)) % count($units)];
            $bomItemsData[] = [
                'id' => 800000 + $i,
                'project_id' => $project->id,
                'jig_no' => $jig,
                'unit_no' => $unit,
                'standard_part_no' => 'PART-285-' . sprintf('%05d', $i),
                'part_type' => 'MFG',
                'item_no' => 'ITEM-' . $i,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }
        foreach (array_chunk($bomItemsData, 1000) as $chunk) {
            BomItem::insert($chunk);
        }
        unset($bomItemsData);

        $reqData = [];
        for ($i = 1; $i <= $targetParts; $i++) {
            $side = $sides[($i - 1) % count($sides)];
            $reqData[] = [
                'bom_item_id' => 800000 + $i,
                'side' => $side,
                'required_quantity' => 2,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }
        foreach (array_chunk($reqData, 1000) as $chunk) {
            BomRequirement::insert($chunk);
        }
        unset($reqData);

        // Add 200 operational receipts and assembly records
        $receipt = Receipt::create(['project_id' => $project->id, 'received_by' => $this->admin->id]);
        $recData = [];
        for ($i = 1; $i <= 200; $i++) {
            $side = $sides[($i - 1) % count($sides)];
            $recData[] = [
                'receipt_id' => $receipt->id,
                'bom_item_id' => 800000 + $i,
                'side' => $side,
                'received_quantity' => 2,
                'status' => 'received',
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }
        ReceiptItem::insert($recData);
        unset($recData);

        $asmData = [];
        for ($i = 1; $i <= 100; $i++) {
            $side = $sides[($i - 1) % count($sides)];
            $asmData[] = [
                'bom_item_id' => 800000 + $i,
                'side' => $side,
                'quantity' => 2,
                'status' => 'completed',
                'assembled_by' => $this->admin->id,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }
        AssemblyRecord::insert($asmData);
        unset($asmData);

        gc_collect_cycles();

        $t0 = microtime(true);
        $service = new HierarchyService();
        $tree = $service->getDepartmentHierarchy('manager', $project->id);
        $elapsed = microtime(true) - $t0;

        $this->assertTrue($tree['is_hierarchical'], 'Hierarchy should be hierarchical');
        $this->assertEquals(5, $tree['total_jigs'], 'Should identify exactly 5 Jigs');
        $this->assertCount(5, $tree['jigs'], 'Jigs array should contain 5 items');
        $this->assertTrue($tree['has_mfg'], 'Should flag has_mfg as true');
        $this->assertFalse($tree['has_bop'], 'Should flag has_bop as false');
        $this->assertFalse($tree['has_std'], 'Should flag has_std as false');

        // Count all parts across all units and all sides
        $totalPartsFound = 0;
        foreach ($tree['jigs'] as $jig) {
            foreach ($jig['units'] as $unit) {
                foreach ($unit['sides'] as $side) {
                    $totalPartsFound += count($side['parts']);
                }
            }
        }

        $this->assertEquals($targetParts, $totalPartsFound, 'Zero parts should be dropped from the hierarchy tree');
        $this->assertLessThan(2.0, $elapsed, 'Hierarchy computation should complete under 2.0 seconds');

        // Verify API endpoint delivers valid JSON response
        $response = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/v1/dashboard/project-hierarchy?project_id=' . $project->id);

        $response->assertStatus(200);
        $data = $response->json();

        $this->assertArrayHasKey('mfg_section', $data);
        $this->assertArrayHasKey('bop_section', $data);
        $this->assertArrayHasKey('std_section', $data);

        $this->assertCount(5, $data['jigs']);
        $this->assertCount(5, $data['mfg_section']['jigs']);
        $this->assertCount(0, $data['bop_section']['jigs']);
        $this->assertCount(0, $data['std_section']['jigs']);

        // Verify MFG part type filter mode
        $mfgResponse = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/v1/dashboard/project-hierarchy?project_id=' . $project->id . '&part_type=MFG');
        $mfgResponse->assertStatus(200);
        $mfgData = $mfgResponse->json();
        $this->assertCount(5, $mfgData['mfg_section']['jigs']);
        $this->assertCount(0, $mfgData['bop_section']['jigs']);
    }

    /**
     * Test that side-specific units properly retain common parts so none are dropped.
     */
    public function test_side_specific_units_retain_common_parts(): void
    {
        $project = Project::create([
            'name' => 'Side Unit Mixed Parts Project ' . uniqid(),
            'project_code' => 'SIDE-' . uniqid(),
            'status' => 'active',
        ]);

        // Item 1: LH part
        $itemLh = BomItem::create([
            'project_id' => $project->id,
            'jig_no' => 'JIG-01',
            'unit_no' => 'Unit 01',
            'standard_part_no' => 'PART-LH-1',
            'part_type' => 'MFG',
        ]);
        BomRequirement::create([
            'bom_item_id' => $itemLh->id,
            'side' => 'LH',
            'required_quantity' => 2,
        ]);

        // Item 2: RH part
        $itemRh = BomItem::create([
            'project_id' => $project->id,
            'jig_no' => 'JIG-01',
            'unit_no' => 'Unit 01',
            'standard_part_no' => 'PART-RH-1',
            'part_type' => 'MFG',
        ]);
        BomRequirement::create([
            'bom_item_id' => $itemRh->id,
            'side' => 'RH',
            'required_quantity' => 2,
        ]);

        // Item 3: Common part inside the SAME unit
        $itemCom = BomItem::create([
            'project_id' => $project->id,
            'jig_no' => 'JIG-01',
            'unit_no' => 'Unit 01',
            'standard_part_no' => 'PART-COM-1',
            'part_type' => 'MFG',
        ]);
        BomRequirement::create([
            'bom_item_id' => $itemCom->id,
            'side' => 'COMMON',
            'required_quantity' => 4,
        ]);

        $service = new HierarchyService();
        $tree = $service->getDepartmentHierarchy('manager', $project->id);

        $unit = $tree['jigs'][0]['units'][0];
        $this->assertTrue($unit['has_lh']);
        $this->assertTrue($unit['has_rh']);
        $this->assertTrue($unit['has_common'], 'Unit should flag has_common when common parts exist');
        $this->assertArrayHasKey('COMMON', $unit['sides'], 'Unit sides must contain COMMON key');

        $this->assertCount(1, $unit['sides']['LH']['parts']);
        $this->assertCount(1, $unit['sides']['RH']['parts']);
        $this->assertCount(1, $unit['sides']['COMMON']['parts']);
        $this->assertEquals('PART-COM-1', $unit['sides']['COMMON']['parts'][0]['standard_part_no']);
    }
}
