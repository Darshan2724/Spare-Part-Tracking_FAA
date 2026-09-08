<?php

namespace Tests\Feature;

use App\Models\AssemblyRecord;
use App\Models\BomItem;
use App\Models\BomRequirement;
use App\Models\PaintRecord;
use App\Models\Project;
use App\Models\QcInspection;
use App\Models\ReceiptItem;
use App\Models\ReworkRecord;
use App\Models\Supplier;
use App\Models\User;
use App\Services\StdIntakeService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class StdIntakeAndWorkflowTest extends TestCase
{
    use DatabaseTransactions;

    protected User $user;
    protected Supplier $supplier;
    protected Project $project;
    protected BomItem $stdItem1;
    protected BomItem $stdItem2;
    protected string $stdPartNo1;

    protected function setUp(): void
    {
        parent::setUp();

        $role = Role::firstOrCreate(['name' => 'ADMIN', 'guard_name' => 'web']);
        $this->user = User::create([
            'name' => 'STD Test Admin',
            'email' => 'std_admin_' . uniqid() . '@example.com',
            'password' => bcrypt('secret'),
        ]);
        $this->user->assignRole($role);
        Sanctum::actingAs($this->user);

        $this->supplier = Supplier::create([
            'name' => 'STD Supplier ' . uniqid(),
            'code' => 'STD-SUP-' . uniqid(),
            'is_active' => true,
        ]);

        $this->project = Project::create([
            'name' => 'STD Project ' . uniqid(),
            'project_code' => 'STD-PRJ-' . uniqid(),
            'status' => 'active',
        ]);

        $this->stdPartNo1 = 'HEX-BOLT-M8X30-' . uniqid();

        $this->stdItem1 = BomItem::create([
            'project_id' => $this->project->id,
            'standard_part_no' => $this->stdPartNo1,
            'part_type' => 'STD',
            'supplier_id' => $this->supplier->id,
            'jig_no' => 'JIG-01',
            'unit_no' => 'Unit 1',
            'size' => 'M8x30 Gr8.8',
        ]);
        BomRequirement::create([
            'bom_item_id' => $this->stdItem1->id,
            'side' => 'COMMON',
            'required_quantity' => 20,
        ]);

        $this->stdItem2 = BomItem::create([
            'project_id' => $this->project->id,
            'standard_part_no' => $this->stdPartNo1,
            'part_type' => 'STD',
            'supplier_id' => $this->supplier->id,
            'jig_no' => 'JIG-02',
            'unit_no' => 'Unit 1',
            'size' => 'M8x30 Gr8.8',
        ]);
        BomRequirement::create([
            'bom_item_id' => $this->stdItem2->id,
            'side' => 'COMMON',
            'required_quantity' => 30,
        ]);
    }

    /**
     * Test 1: Aggregated STD Parts endpoint consolidates across jigs/units.
     */
    public function test_aggregated_std_parts_endpoint_returns_data(): void
    {
        $response = $this->getJson('/api/v1/std/parts');
        $response->assertStatus(200);
        $response->assertJson(['success' => true]);

        $parts = collect($response->json('data.parts'));
        $part = $parts->firstWhere('standard_part_no', $this->stdPartNo1);

        $this->assertNotNull($part);
        $this->assertEquals(50, $part['total_required']); // 20 + 30
        $this->assertEquals(0, $part['total_received']);
        $this->assertEquals(50, $part['total_pending']);
    }

    /**
     * Test 2: Standard Hardware full lifecycle transitions through Store -> QC -> Rework/Paint/Assembly -> Completed.
     */
    public function test_std_full_workflow_transitions(): void
    {
        // 1. Pending -> Store (30 pcs)
        $this->postJson('/api/v1/std/transition', [
            'standard_part_no' => $this->stdPartNo1,
            'from_state' => 'pending',
            'to_state' => 'store',
            'quantity' => 30,
            'project_id' => $this->project->id,
        ])->assertStatus(200);

        // 2. Store -> QC (30 pcs)
        $this->postJson('/api/v1/std/transition', [
            'standard_part_no' => $this->stdPartNo1,
            'from_state' => 'store',
            'to_state' => 'qc',
            'quantity' => 30,
            'project_id' => $this->project->id,
        ])->assertStatus(200);

        // 3. QC -> Direct Assembly (10 pcs)
        $this->postJson('/api/v1/std/transition', [
            'standard_part_no' => $this->stdPartNo1,
            'from_state' => 'qc',
            'to_state' => 'assembly',
            'quantity' => 10,
            'project_id' => $this->project->id,
        ])->assertStatus(200);

        // 4. QC -> Paint (10 pcs)
        $this->postJson('/api/v1/std/transition', [
            'standard_part_no' => $this->stdPartNo1,
            'from_state' => 'qc',
            'to_state' => 'paint',
            'quantity' => 10,
            'project_id' => $this->project->id,
        ])->assertStatus(200);

        // 5. QC -> Rework (10 pcs)
        $this->postJson('/api/v1/std/transition', [
            'standard_part_no' => $this->stdPartNo1,
            'from_state' => 'qc',
            'to_state' => 'rework',
            'quantity' => 10,
            'project_id' => $this->project->id,
        ])->assertStatus(200);

        // Verify Rework is active
        $listRes1 = $this->getJson('/api/v1/std/parts?project_id=' . $this->project->id);
        $part1 = collect($listRes1->json('data.parts'))->firstWhere('standard_part_no', $this->stdPartNo1);
        $this->assertEquals(10, $part1['parts_in_rework']);
        $this->assertEquals(10, $part1['parts_in_paint']);
        $this->assertEquals(10, $part1['parts_in_assembly']);

        // 6. Complete Rework: Rework -> QC (10 pcs)
        $this->postJson('/api/v1/std/transition', [
            'standard_part_no' => $this->stdPartNo1,
            'from_state' => 'rework',
            'to_state' => 'qc',
            'quantity' => 10,
            'project_id' => $this->project->id,
        ])->assertStatus(200);

        // 7. QC -> Direct Assembly (10 pcs reworked)
        $this->postJson('/api/v1/std/transition', [
            'standard_part_no' => $this->stdPartNo1,
            'from_state' => 'qc',
            'to_state' => 'assembly',
            'quantity' => 10,
            'project_id' => $this->project->id,
        ])->assertStatus(200);

        // 8. Paint -> Assembly (10 pcs)
        $this->postJson('/api/v1/std/transition', [
            'standard_part_no' => $this->stdPartNo1,
            'from_state' => 'paint',
            'to_state' => 'assembly',
            'quantity' => 10,
            'project_id' => $this->project->id,
        ])->assertStatus(200);

        // 9. Assembly -> Completed (25 pcs)
        $this->postJson('/api/v1/std/transition', [
            'standard_part_no' => $this->stdPartNo1,
            'from_state' => 'assembly',
            'to_state' => 'completed',
            'quantity' => 25,
            'project_id' => $this->project->id,
        ])->assertStatus(200);

        // Verify final state
        $listRes2 = $this->getJson('/api/v1/std/parts?project_id=' . $this->project->id);
        $part2 = collect($listRes2->json('data.parts'))->firstWhere('standard_part_no', $this->stdPartNo1);

        $this->assertEquals(30, $part2['total_received']);
        $this->assertEquals(20, $part2['total_pending']); // 50 - 30
        $this->assertEquals(0, $part2['parts_in_store']);
        $this->assertEquals(0, $part2['parts_in_qc']);
        $this->assertEquals(0, $part2['parts_in_rework']);
        $this->assertEquals(0, $part2['parts_in_paint']);
        $this->assertEquals(5, $part2['parts_in_assembly']); // (10 direct + 10 reworked direct + 10 painted) - 25 = 5
        $this->assertEquals(25, $part2['assembly_completed']);
    }

    /**
     * Test 3: STD part list order is 100% deterministic and stable across workflow state changes.
     */
    public function test_std_part_list_order_is_deterministic_and_stable(): void
    {
        $partA = 'AAA-FASTENER-' . uniqid();
        $partM = 'MMM-HARDWARE-' . uniqid();
        $partZ = 'ZZZ-SPACER-' . uniqid();

        foreach ([$partZ, $partA, $partM] as $pNo) {
            $item = BomItem::create([
                'project_id' => $this->project->id,
                'standard_part_no' => $pNo,
                'part_type' => 'STD',
                'supplier_id' => $this->supplier->id,
                'jig_no' => 'JIG-STABLE-STD',
                'unit_no' => 'Unit 1',
            ]);
            BomRequirement::create([
                'bom_item_id' => $item->id,
                'side' => 'COMMON',
                'required_quantity' => 15,
            ]);
        }

        // 1. Initial list: naturally sorted [AAA, MMM, ZZZ]
        $res1 = $this->getJson('/api/v1/std/parts?project_id=' . $this->project->id);
        $parts1 = collect($res1->json('data.parts'))->whereIn('standard_part_no', [$partA, $partM, $partZ])->values();

        $this->assertEquals($partA, $parts1[0]['standard_part_no']);
        $this->assertEquals($partM, $parts1[1]['standard_part_no']);
        $this->assertEquals($partZ, $parts1[2]['standard_part_no']);

        // 2. Receive 100% of AAA (pending becomes 0) -> order must NOT shift
        $this->postJson('/api/v1/std/transition', [
            'standard_part_no' => $partA,
            'from_state' => 'pending',
            'to_state' => 'store',
            'quantity' => 15,
            'project_id' => $this->project->id,
        ])->assertStatus(200);

        $res2 = $this->getJson('/api/v1/std/parts?project_id=' . $this->project->id);
        $parts2 = collect($res2->json('data.parts'))->whereIn('standard_part_no', [$partA, $partM, $partZ])->values();

        $this->assertEquals($partA, $parts2[0]['standard_part_no'], 'STD Row position shifted after receive!');
        $this->assertEquals(0, $parts2[0]['total_pending']);
        $this->assertEquals(15, $parts2[0]['parts_in_store']);
        $this->assertEquals($partM, $parts2[1]['standard_part_no']);
        $this->assertEquals($partZ, $parts2[2]['standard_part_no']);

        // 3. Move AAA through Store -> QC -> Assembly -> Completed -> order must remain exact
        $this->postJson('/api/v1/std/transition', [
            'standard_part_no' => $partA,
            'from_state' => 'store',
            'to_state' => 'qc',
            'quantity' => 15,
            'project_id' => $this->project->id,
        ])->assertStatus(200);

        $this->postJson('/api/v1/std/transition', [
            'standard_part_no' => $partA,
            'from_state' => 'qc',
            'to_state' => 'assembly',
            'quantity' => 15,
            'project_id' => $this->project->id,
        ])->assertStatus(200);

        $this->postJson('/api/v1/std/transition', [
            'standard_part_no' => $partA,
            'from_state' => 'assembly',
            'to_state' => 'completed',
            'quantity' => 15,
            'project_id' => $this->project->id,
        ])->assertStatus(200);

        $res3 = $this->getJson('/api/v1/std/parts?project_id=' . $this->project->id);
        $parts3 = collect($res3->json('data.parts'))->whereIn('standard_part_no', [$partA, $partM, $partZ])->values();

        $this->assertEquals($partA, $parts3[0]['standard_part_no'], 'Completed STD row shifted position!');
        $this->assertEquals(15, $parts3[0]['assembly_completed']);
        $this->assertEquals($partM, $parts3[1]['standard_part_no']);
        $this->assertEquals($partZ, $parts3[2]['standard_part_no']);
    }

    /**
     * Test 4: STD rejects invalid transitions (e.g. Store directly to Assembly, Pending to QC).
     */
    public function test_std_strictly_rejects_invalid_transitions(): void
    {
        // PENDING -> QC should be rejected
        $this->postJson('/api/v1/std/transition', [
            'standard_part_no' => $this->stdPartNo1,
            'from_state' => 'pending',
            'to_state' => 'qc',
            'quantity' => 5,
        ])->assertStatus(422);

        // Receive into store
        $this->postJson('/api/v1/std/transition', [
            'standard_part_no' => $this->stdPartNo1,
            'from_state' => 'pending',
            'to_state' => 'store',
            'quantity' => 10,
        ])->assertStatus(200);

        // STORE -> ASSEMBLY should be rejected (STD must go through QC)
        $this->postJson('/api/v1/std/transition', [
            'standard_part_no' => $this->stdPartNo1,
            'from_state' => 'store',
            'to_state' => 'assembly',
            'quantity' => 5,
        ])->assertStatus(422);

        // STORE -> PAINT should be rejected
        $this->postJson('/api/v1/std/transition', [
            'standard_part_no' => $this->stdPartNo1,
            'from_state' => 'store',
            'to_state' => 'paint',
            'quantity' => 5,
        ])->assertStatus(422);

        // Move store to QC
        $this->postJson('/api/v1/std/transition', [
            'standard_part_no' => $this->stdPartNo1,
            'from_state' => 'store',
            'to_state' => 'qc',
            'quantity' => 10,
        ])->assertStatus(200);

        // QC -> COMPLETED should be rejected (Must reach Assembly first)
        $this->postJson('/api/v1/std/transition', [
            'standard_part_no' => $this->stdPartNo1,
            'from_state' => 'qc',
            'to_state' => 'completed',
            'quantity' => 5,
        ])->assertStatus(422);
    }

    /**
     * Test 5: STD QC Route distributes quantity across Rework, Paint, and Assembly in one atomic action.
     */
    public function test_std_qc_three_way_routing_distributes_all_destinations_atomically(): void
    {
        // 1. Pending -> Store (30 pcs)
        $this->postJson('/api/v1/std/transition', [
            'standard_part_no' => $this->stdPartNo1,
            'from_state' => 'pending',
            'to_state' => 'store',
            'quantity' => 30,
            'project_id' => $this->project->id,
        ])->assertStatus(200);

        // 2. Store -> QC (30 pcs)
        $this->postJson('/api/v1/std/transition', [
            'standard_part_no' => $this->stdPartNo1,
            'from_state' => 'store',
            'to_state' => 'qc',
            'quantity' => 30,
            'project_id' => $this->project->id,
        ])->assertStatus(200);

        // 3. 3-Way QC Route: Rework: 5, Paint: 15, Assembly: 10 (Total 30)
        $routeRes = $this->postJson('/api/v1/std/qc-route', [
            'standard_part_no' => $this->stdPartNo1,
            'rework_quantity' => 5,
            'paint_quantity' => 15,
            'assembly_quantity' => 10,
            'project_id' => $this->project->id,
        ]);

        $routeRes->assertStatus(200);
        $routeRes->assertJson([
            'success' => true,
            'data' => [
                'standard_part_no' => $this->stdPartNo1,
                'rework_quantity' => 5,
                'paint_quantity' => 15,
                'assembly_quantity' => 10,
                'total_routed' => 30,
                'qc_remaining' => 0,
            ],
        ]);

        // 4. Verify aggregated parts state
        $res = $this->getJson('/api/v1/std/parts?project_id=' . $this->project->id);
        $res->assertStatus(200);
        $part = collect($res->json('data.parts'))->firstWhere('standard_part_no', $this->stdPartNo1);

        $this->assertEquals(0, $part['parts_in_qc'], 'QC should be fully depleted.');
        $this->assertEquals(5, $part['parts_in_rework'], 'Rework should receive exactly 5 pcs.');
        $this->assertEquals(15, $part['parts_in_paint'], 'Paint should receive exactly 15 pcs.');
        $this->assertEquals(10, $part['parts_in_assembly'], 'Assembly should receive exactly 10 pcs.');
    }

    /**
     * Test 6: STD QC partial routing leaves remaining quantity in QC.
     */
    public function test_std_qc_partial_routing_leaves_remainder_in_qc(): void
    {
        // 1. Pending -> Store (30 pcs)
        $this->postJson('/api/v1/std/transition', [
            'standard_part_no' => $this->stdPartNo1,
            'from_state' => 'pending',
            'to_state' => 'store',
            'quantity' => 30,
            'project_id' => $this->project->id,
        ])->assertStatus(200);

        // 2. Store -> QC (30 pcs)
        $this->postJson('/api/v1/std/transition', [
            'standard_part_no' => $this->stdPartNo1,
            'from_state' => 'store',
            'to_state' => 'qc',
            'quantity' => 30,
            'project_id' => $this->project->id,
        ])->assertStatus(200);

        // 3. Partial 3-Way QC Route: Rework: 5, Paint: 10, Assembly: 5 (Total 20, Remainder 10 in QC)
        $routeRes = $this->postJson('/api/v1/std/qc-route', [
            'standard_part_no' => $this->stdPartNo1,
            'rework_quantity' => 5,
            'paint_quantity' => 10,
            'assembly_quantity' => 5,
            'project_id' => $this->project->id,
        ]);

        $routeRes->assertStatus(200);
        $routeRes->assertJson([
            'success' => true,
            'data' => [
                'standard_part_no' => $this->stdPartNo1,
                'rework_quantity' => 5,
                'paint_quantity' => 10,
                'assembly_quantity' => 5,
                'total_routed' => 20,
                'qc_remaining' => 10,
            ],
        ]);

        // 4. Verify aggregated parts state
        $res = $this->getJson('/api/v1/std/parts?project_id=' . $this->project->id);
        $res->assertStatus(200);
        $part = collect($res->json('data.parts'))->firstWhere('standard_part_no', $this->stdPartNo1);

        $this->assertEquals(10, $part['parts_in_qc'], 'QC should retain 10 pcs.');
        $this->assertEquals(5, $part['parts_in_rework'], 'Rework should receive 5 pcs.');
        $this->assertEquals(10, $part['parts_in_paint'], 'Paint should receive 10 pcs.');
        $this->assertEquals(5, $part['parts_in_assembly'], 'Assembly should receive 5 pcs.');
    }

    /**
     * Test 7: STD QC Route strictly rejects over-allocation and invalid inputs.
     */
    public function test_std_qc_route_rejects_over_allocation_and_invalid_inputs(): void
    {
        // 1. Pending -> Store (20 pcs)
        $this->postJson('/api/v1/std/transition', [
            'standard_part_no' => $this->stdPartNo1,
            'from_state' => 'pending',
            'to_state' => 'store',
            'quantity' => 20,
            'project_id' => $this->project->id,
        ])->assertStatus(200);

        // 2. Store -> QC (20 pcs)
        $this->postJson('/api/v1/std/transition', [
            'standard_part_no' => $this->stdPartNo1,
            'from_state' => 'store',
            'to_state' => 'qc',
            'quantity' => 20,
            'project_id' => $this->project->id,
        ])->assertStatus(200);

        // Attempt Over-allocation: 10 + 10 + 5 = 25 (Available is 20) -> 422
        $this->postJson('/api/v1/std/qc-route', [
            'standard_part_no' => $this->stdPartNo1,
            'rework_quantity' => 10,
            'paint_quantity' => 10,
            'assembly_quantity' => 5,
            'project_id' => $this->project->id,
        ])->assertStatus(422);

        // Attempt Total 0 -> 422
        $this->postJson('/api/v1/std/qc-route', [
            'standard_part_no' => $this->stdPartNo1,
            'rework_quantity' => 0,
            'paint_quantity' => 0,
            'assembly_quantity' => 0,
            'project_id' => $this->project->id,
        ])->assertStatus(422);

        // Attempt Negative Quantity -> 422
        $this->postJson('/api/v1/std/qc-route', [
            'standard_part_no' => $this->stdPartNo1,
            'rework_quantity' => -5,
            'paint_quantity' => 10,
            'assembly_quantity' => 5,
            'project_id' => $this->project->id,
        ])->assertStatus(422);
    }

    /**
     * Test 8: STD Progress calculation strictly reflects production completion (Completed / Required).
     */
    public function test_std_progress_calculation_strictly_reflects_production_completion(): void
    {
        $testPartNo = 'PROGRESS-STD-TEST-' . uniqid();
        $item = BomItem::create([
            'project_id' => $this->project->id,
            'standard_part_no' => $testPartNo,
            'part_type' => 'STD',
            'supplier_id' => $this->supplier->id,
            'jig_no' => 'JIG-PRG',
            'unit_no' => 'Unit 1',
        ]);
        BomRequirement::create([
            'bom_item_id' => $item->id,
            'side' => 'COMMON',
            'required_quantity' => 100,
        ]);

        // 1. Initial state: 0% Progress
        $res = $this->getJson('/api/v1/std/parts?project_id=' . $this->project->id);
        $part = collect($res->json('data.parts'))->firstWhere('standard_part_no', $testPartNo);
        $this->assertEquals(0, $part['completion_pct']);

        // 2. Receive 100 pcs into Store -> 0% Progress (receipt does not increase progress)
        $this->postJson('/api/v1/std/transition', [
            'standard_part_no' => $testPartNo,
            'from_state' => 'pending',
            'to_state' => 'store',
            'quantity' => 100,
            'project_id' => $this->project->id,
        ])->assertStatus(200);

        $res = $this->getJson('/api/v1/std/parts?project_id=' . $this->project->id);
        $part = collect($res->json('data.parts'))->firstWhere('standard_part_no', $testPartNo);
        $this->assertEquals(100, $part['total_received']);
        $this->assertEquals(0, $part['completion_pct'], 'Store quantity must not increase progress');

        // 3. Move 100 pcs to QC -> 0% Progress
        $this->postJson('/api/v1/std/transition', [
            'standard_part_no' => $testPartNo,
            'from_state' => 'store',
            'to_state' => 'qc',
            'quantity' => 100,
            'project_id' => $this->project->id,
        ])->assertStatus(200);

        $res = $this->getJson('/api/v1/std/parts?project_id=' . $this->project->id);
        $part = collect($res->json('data.parts'))->firstWhere('standard_part_no', $testPartNo);
        $this->assertEquals(100, $part['parts_in_qc']);
        $this->assertEquals(0, $part['completion_pct'], 'QC quantity must not increase progress');

        // 4. QC Route: Rework 20, Paint 40, Assembly 40 -> 0% Progress
        $this->postJson('/api/v1/std/qc-route', [
            'standard_part_no' => $testPartNo,
            'rework_quantity' => 20,
            'paint_quantity' => 40,
            'assembly_quantity' => 40,
            'project_id' => $this->project->id,
        ])->assertStatus(200);

        $res = $this->getJson('/api/v1/std/parts?project_id=' . $this->project->id);
        $part = collect($res->json('data.parts'))->firstWhere('standard_part_no', $testPartNo);
        $this->assertEquals(0, $part['completion_pct'], 'Rework/Paint/Assembly in-flight must not increase progress');

        // 5. Complete 40 pcs in Assembly -> 40% Progress
        $this->postJson('/api/v1/std/transition', [
            'standard_part_no' => $testPartNo,
            'from_state' => 'assembly',
            'to_state' => 'completed',
            'quantity' => 40,
            'project_id' => $this->project->id,
        ])->assertStatus(200);

        $res = $this->getJson('/api/v1/std/parts?project_id=' . $this->project->id);
        $part = collect($res->json('data.parts'))->firstWhere('standard_part_no', $testPartNo);
        $this->assertEquals(40, $part['assembly_completed']);
        $this->assertEquals(40, $part['completion_pct'], '40/100 completed must equal exactly 40%');

        // 6. Move 40 from Paint to Assembly, then Complete -> 80% Progress
        $this->postJson('/api/v1/std/transition', [
            'standard_part_no' => $testPartNo,
            'from_state' => 'paint',
            'to_state' => 'assembly',
            'quantity' => 40,
            'project_id' => $this->project->id,
        ])->assertStatus(200);

        $this->postJson('/api/v1/std/transition', [
            'standard_part_no' => $testPartNo,
            'from_state' => 'assembly',
            'to_state' => 'completed',
            'quantity' => 40,
            'project_id' => $this->project->id,
        ])->assertStatus(200);

        $res = $this->getJson('/api/v1/std/parts?project_id=' . $this->project->id);
        $part = collect($res->json('data.parts'))->firstWhere('standard_part_no', $testPartNo);
        $this->assertEquals(80, $part['assembly_completed']);
        $this->assertEquals(80, $part['completion_pct'], '80/100 completed must equal 80%');
    }
}
