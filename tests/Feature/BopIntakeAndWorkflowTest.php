<?php

namespace Tests\Feature;

use App\Models\AssemblyRecord;
use App\Models\BomItem;
use App\Models\BomRequirement;
use App\Models\Project;
use App\Models\ReceiptItem;
use App\Models\Supplier;
use App\Models\User;
use App\Services\BopIntakeService;
use App\Services\QuantityCalculationService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class BopIntakeAndWorkflowTest extends TestCase
{
    use DatabaseTransactions;

    protected User $user;
    protected Supplier $supplier;
    protected Project $projectA;
    protected Project $projectB;
    protected BomItem $bopPart1_ProjA_Unit1;
    protected BomItem $bopPart1_ProjA_Unit2;
    protected BomItem $bopPart1_ProjB_Unit1;
    protected BomItem $bopPart2;
    protected string $partNo1;
    protected string $partNo2;

    protected function setUp(): void
    {
        parent::setUp();

        $role = Role::firstOrCreate(['name' => 'ADMIN', 'guard_name' => 'web']);
        $this->user = User::create([
            'name' => 'BOP Test Admin',
            'email' => 'bop_admin_' . uniqid() . '@example.com',
            'password' => bcrypt('secret'),
        ]);
        $this->user->assignRole($role);
        Sanctum::actingAs($this->user);

        $this->supplier = Supplier::create([
            'name' => 'BOP Supplier ' . uniqid(),
            'code' => 'BOP-SUP-' . uniqid(),
            'is_active' => true,
        ]);

        $this->projectA = Project::create([
            'name' => 'BOP Project A ' . uniqid(),
            'project_code' => 'BOP-PRJ-A-' . uniqid(),
            'status' => 'active',
        ]);

        $this->projectB = Project::create([
            'name' => 'BOP Project B ' . uniqid(),
            'project_code' => 'BOP-PRJ-B-' . uniqid(),
            'status' => 'active',
        ]);

        $this->partNo1 = 'PU-BLOCK-BUSH-' . uniqid();
        $this->partNo2 = 'CYLINDER-SENSOR-' . uniqid();

        // Project A - Unit 1: 10 pcs required
        $this->bopPart1_ProjA_Unit1 = BomItem::create([
            'project_id' => $this->projectA->id,
            'standard_part_no' => $this->partNo1,
            'part_type' => 'BOP',
            'supplier_id' => $this->supplier->id,
            'jig_no' => 'JIG-01',
            'unit_no' => 'Unit 1',
            'size' => '25x50mm',
        ]);
        BomRequirement::create([
            'bom_item_id' => $this->bopPart1_ProjA_Unit1->id,
            'side' => 'COMMON',
            'required_quantity' => 10,
        ]);

        // Project A - Unit 2: 15 pcs required
        $this->bopPart1_ProjA_Unit2 = BomItem::create([
            'project_id' => $this->projectA->id,
            'standard_part_no' => $this->partNo1,
            'part_type' => 'BOP',
            'supplier_id' => $this->supplier->id,
            'jig_no' => 'JIG-01',
            'unit_no' => 'Unit 2',
            'size' => '25x50mm',
        ]);
        BomRequirement::create([
            'bom_item_id' => $this->bopPart1_ProjA_Unit2->id,
            'side' => 'COMMON',
            'required_quantity' => 15,
        ]);

        // Project B - Unit 1: 25 pcs required
        $this->bopPart1_ProjB_Unit1 = BomItem::create([
            'project_id' => $this->projectB->id,
            'standard_part_no' => $this->partNo1,
            'part_type' => 'BOP',
            'supplier_id' => $this->supplier->id,
            'jig_no' => 'JIG-10',
            'unit_no' => 'Unit 1',
            'size' => '25x50mm',
        ]);
        BomRequirement::create([
            'bom_item_id' => $this->bopPart1_ProjB_Unit1->id,
            'side' => 'COMMON',
            'required_quantity' => 25,
        ]);

        // Part 2: 5 pcs in Project A
        $this->bopPart2 = BomItem::create([
            'project_id' => $this->projectA->id,
            'standard_part_no' => $this->partNo2,
            'part_type' => 'BOP',
            'supplier_id' => $this->supplier->id,
            'jig_no' => 'JIG-02',
            'unit_no' => 'Unit 1',
            'size' => 'M12 PNP',
        ]);
        BomRequirement::create([
            'bom_item_id' => $this->bopPart2->id,
            'side' => 'COMMON',
            'required_quantity' => 5,
        ]);
    }

    /**
     * Test 1: Aggregated BOP Parts endpoint aggregates across projects and units.
     */
    public function test_aggregated_bop_parts_endpoint_returns_consolidated_rows(): void
    {
        $response = $this->getJson('/api/v1/bop/parts');
        $response->assertStatus(200);
        $response->assertJson(['success' => true]);

        $parts = collect($response->json('data.parts'));
        $part1 = $parts->firstWhere('standard_part_no', $this->partNo1);

        $this->assertNotNull($part1);
        $this->assertEquals(50, $part1['total_required']); // 10 + 15 + 25
        $this->assertEquals(0, $part1['total_received']);
        $this->assertEquals(50, $part1['total_pending']);
        $this->assertEquals(0, $part1['parts_in_store']);
        $this->assertEquals(0, $part1['parts_in_assembly']);
        $this->assertEquals(0, $part1['assembly_completed']);
        $this->assertEquals(2, $part1['distinct_projects']);
        $this->assertEquals(2, $part1['distinct_units']);
    }

    /**
     * Test 2: Unit breakdown returns accurate traceability lines for a part.
     */
    public function test_bop_part_breakdown_returns_unit_level_details(): void
    {
        $response = $this->getJson('/api/v1/bop/parts/' . urlencode($this->partNo1) . '/breakdown');
        $response->assertStatus(200);
        $response->assertJson(['success' => true]);

        $breakdown = collect($response->json('data.breakdown'));
        $this->assertCount(3, $breakdown);

        $this->assertEquals(10, $breakdown->firstWhere('unit_no', 'Unit 1')['required_quantity']);
    }

    /**
     * Test 3: Transactional FIFO intake from pending to store across units.
     */
    public function test_transition_pending_to_store_allocates_fifo(): void
    {
        // Transition 18 pcs into store: should fill ProjA-Unit1 (10 pcs) and ProjA-Unit2 (8 pcs)
        $response = $this->postJson('/api/v1/bop/transition', [
            'standard_part_no' => $this->partNo1,
            'from_state' => 'pending',
            'to_state' => 'store',
            'quantity' => 18,
        ]);

        $response->assertStatus(200);
        $response->assertJson(['success' => true]);

        // Verify receipt items in DB
        $recUnit1 = ReceiptItem::where('bom_item_id', $this->bopPart1_ProjA_Unit1->id)->first();
        $this->assertNotNull($recUnit1);
        $this->assertEquals(10, $recUnit1->received_quantity);
        $this->assertEquals('received', $recUnit1->status);

        $recUnit2 = ReceiptItem::where('bom_item_id', $this->bopPart1_ProjA_Unit2->id)->first();
        $this->assertNotNull($recUnit2);
        $this->assertEquals(8, $recUnit2->received_quantity);
        $this->assertEquals('received', $recUnit2->status);

        // Verify API aggregation reflects changes
        $listRes = $this->getJson('/api/v1/bop/parts');
        $parts = collect($listRes->json('data.parts'));
        $part1 = $parts->firstWhere('standard_part_no', $this->partNo1);

        $this->assertEquals(18, $part1['total_received']);
        $this->assertEquals(18, $part1['parts_in_store']);
        $this->assertEquals(32, $part1['total_pending']); // 50 - 18
    }

    /**
     * Test 4: Complete BOP lifecycle: Pending -> Store -> Assembly -> Completed.
     */
    public function test_complete_bop_workflow_lifecycle(): void
    {
        // 1. Pending -> Store (12 pcs: 10 in Unit1, 2 in Unit2)
        $this->postJson('/api/v1/bop/transition', [
            'standard_part_no' => $this->partNo1,
            'from_state' => 'pending',
            'to_state' => 'store',
            'quantity' => 12,
        ])->assertStatus(200);

        // 2. Store -> Assembly (8 pcs: 8 from Unit1)
        $this->postJson('/api/v1/bop/transition', [
            'standard_part_no' => $this->partNo1,
            'from_state' => 'store',
            'to_state' => 'assembly',
            'quantity' => 8,
        ])->assertStatus(200);

        // Verify in DB
        $inAssemblyRec = ReceiptItem::where('bom_item_id', $this->bopPart1_ProjA_Unit1->id)
            ->where('status', 'in_assembly')
            ->first();
        $this->assertNotNull($inAssemblyRec);
        $this->assertEquals(8, $inAssemblyRec->received_quantity);

        // 3. Assembly -> Completed (5 pcs: 5 completed in Unit1)
        $this->postJson('/api/v1/bop/transition', [
            'standard_part_no' => $this->partNo1,
            'from_state' => 'assembly',
            'to_state' => 'completed',
            'quantity' => 5,
        ])->assertStatus(200);

        $asmRecord = AssemblyRecord::where('bom_item_id', $this->bopPart1_ProjA_Unit1->id)
            ->where('status', 'completed')
            ->first();
        $this->assertNotNull($asmRecord);
        $this->assertEquals(5, $asmRecord->quantity);

        // Verify aggregated state:
        // Store: 4 (2 in Unit1, 2 in Unit2)
        // Assembly: 3 (8 - 5 in Unit1)
        // Completed: 5
        // Pending: 38 (50 - 12)
        $listRes = $this->getJson('/api/v1/bop/parts');
        $parts = collect($listRes->json('data.parts'));
        $part1 = $parts->firstWhere('standard_part_no', $this->partNo1);

        $this->assertEquals(12, $part1['total_received']);
        $this->assertEquals(38, $part1['total_pending']);
        $this->assertEquals(4, $part1['parts_in_store']);
        $this->assertEquals(3, $part1['parts_in_assembly']);
        $this->assertEquals(5, $part1['assembly_completed']);
    }

    /**
     * Test 5: Rejection of invalid transitions (BOP cannot go to QC, Rework, or Paint).
     */
    public function test_bop_rejects_invalid_intermediate_states(): void
    {
        $response = $this->postJson('/api/v1/bop/transition', [
            'standard_part_no' => $this->partNo1,
            'from_state' => 'store',
            'to_state' => 'qc',
            'quantity' => 5,
        ]);

        $response->assertStatus(422);
    }

    /**
     * Test 6: Dashboard Summary realigned BOP metrics returns strictly 6 KPIs with zero QC/Rework/Paint.
     */
    public function test_dashboard_summary_returns_bop_isolated_6_kpis(): void
    {
        // Receive 10 pcs into store and move 6 to assembly and 4 to completed for projectA
        $this->postJson('/api/v1/bop/transition', [
            'standard_part_no' => $this->partNo1,
            'from_state' => 'pending',
            'to_state' => 'store',
            'quantity' => 10,
            'project_id' => $this->projectA->id,
        ])->assertStatus(200);

        $this->postJson('/api/v1/bop/transition', [
            'standard_part_no' => $this->partNo1,
            'from_state' => 'store',
            'to_state' => 'assembly',
            'quantity' => 6,
            'project_id' => $this->projectA->id,
        ])->assertStatus(200);

        $this->postJson('/api/v1/bop/transition', [
            'standard_part_no' => $this->partNo1,
            'from_state' => 'assembly',
            'to_state' => 'completed',
            'quantity' => 4,
            'project_id' => $this->projectA->id,
        ])->assertStatus(200);

        $summaryRes = $this->getJson('/api/v1/dashboard/summary?project_id=' . $this->projectA->id);
        $summaryRes->assertStatus(200);

        $bopSummary = $summaryRes->json('summary.bop');
        $this->assertNotNull($bopSummary);

        // QC, Rework, Paint MUST BE strictly 0 in BOP metrics
        $this->assertEquals(0, $bopSummary['parts_in_qc']);
        $this->assertEquals(0, $bopSummary['parts_in_rework']);
        $this->assertEquals(0, $bopSummary['parts_in_paint']);
        $this->assertEquals(0, $bopSummary['awaiting_qc']);
        $this->assertEquals(0, $bopSummary['qc_approved']);
        $this->assertEquals(0, $bopSummary['qc_rejected']);

        // Check BOP resident values
        $this->assertEquals(4, $bopSummary['parts_in_store']);
        $this->assertEquals(2, $bopSummary['parts_in_assembly']); // 6 - 4
        $this->assertEquals(4, $bopSummary['assembly_completed']);
    }

    /**
     * Test 7: BOP part list order is 100% deterministic and stable across transitions.
     */
    public function test_bop_part_list_order_is_deterministic_and_stable(): void
    {
        $partA = 'AAA-BEARING-' . uniqid();
        $partM = 'MMM-CYLINDER-' . uniqid();
        $partZ = 'ZZZ-VALVE-' . uniqid();

        foreach ([$partZ, $partA, $partM] as $pNo) {
            $item = BomItem::create([
                'project_id' => $this->projectA->id,
                'standard_part_no' => $pNo,
                'part_type' => 'BOP',
                'supplier_id' => $this->supplier->id,
                'jig_no' => 'JIG-STABLE',
                'unit_no' => 'Unit 1',
            ]);
            BomRequirement::create([
                'bom_item_id' => $item->id,
                'side' => 'COMMON',
                'required_quantity' => 20,
            ]);
        }

        // 1. Initial list: must be naturally sorted [AAA, MMM, ZZZ]
        $res1 = $this->getJson('/api/v1/bop/parts?project_id=' . $this->projectA->id);
        $parts1 = collect($res1->json('data.parts'))->whereIn('standard_part_no', [$partA, $partM, $partZ])->values();

        $this->assertEquals($partA, $parts1[0]['standard_part_no']);
        $this->assertEquals($partM, $parts1[1]['standard_part_no']);
        $this->assertEquals($partZ, $parts1[2]['standard_part_no']);

        // 2. Receive 100% of AAA (pending becomes 0) -> order must NOT change
        $this->postJson('/api/v1/bop/transition', [
            'standard_part_no' => $partA,
            'from_state' => 'pending',
            'to_state' => 'store',
            'quantity' => 20,
            'project_id' => $this->projectA->id,
        ])->assertStatus(200);

        $res2 = $this->getJson('/api/v1/bop/parts?project_id=' . $this->projectA->id);
        $parts2 = collect($res2->json('data.parts'))->whereIn('standard_part_no', [$partA, $partM, $partZ])->values();

        $this->assertEquals($partA, $parts2[0]['standard_part_no'], 'Row position shifted after receive!');
        $this->assertEquals(0, $parts2[0]['total_pending']);
        $this->assertEquals(20, $parts2[0]['parts_in_store']);
        $this->assertEquals($partM, $parts2[1]['standard_part_no']);
        $this->assertEquals($partZ, $parts2[2]['standard_part_no']);

        // 3. Move AAA to Assembly and Complete -> order must NOT change
        $this->postJson('/api/v1/bop/transition', [
            'standard_part_no' => $partA,
            'from_state' => 'store',
            'to_state' => 'assembly',
            'quantity' => 20,
            'project_id' => $this->projectA->id,
        ])->assertStatus(200);

        $this->postJson('/api/v1/bop/transition', [
            'standard_part_no' => $partA,
            'from_state' => 'assembly',
            'to_state' => 'completed',
            'quantity' => 20,
            'project_id' => $this->projectA->id,
        ])->assertStatus(200);

        $res3 = $this->getJson('/api/v1/bop/parts?project_id=' . $this->projectA->id);
        $parts3 = collect($res3->json('data.parts'))->whereIn('standard_part_no', [$partA, $partM, $partZ])->values();

        $this->assertEquals($partA, $parts3[0]['standard_part_no'], 'Completed row changed position!');
        $this->assertEquals(20, $parts3[0]['assembly_completed']);
        $this->assertEquals($partM, $parts3[1]['standard_part_no']);
        $this->assertEquals($partZ, $parts3[2]['standard_part_no']);
    }

    /**
     * Test 8: BOP strictly rejects transitions to QC, Rework, or Paint.
     */
    public function test_bop_strictly_rejects_qc_rework_paint(): void
    {
        // PENDING -> QC should be rejected
        $this->postJson('/api/v1/bop/transition', [
            'standard_part_no' => $this->partNo1,
            'from_state' => 'pending',
            'to_state' => 'qc',
            'quantity' => 5,
        ])->assertStatus(422);

        // Receive into store
        $this->postJson('/api/v1/bop/transition', [
            'standard_part_no' => $this->partNo1,
            'from_state' => 'pending',
            'to_state' => 'store',
            'quantity' => 10,
        ])->assertStatus(200);

        // STORE -> QC should be rejected
        $this->postJson('/api/v1/bop/transition', [
            'standard_part_no' => $this->partNo1,
            'from_state' => 'store',
            'to_state' => 'qc',
            'quantity' => 5,
        ])->assertStatus(422);

        // STORE -> PAINT should be rejected
        $this->postJson('/api/v1/bop/transition', [
            'standard_part_no' => $this->partNo1,
            'from_state' => 'store',
            'to_state' => 'paint',
            'quantity' => 5,
        ])->assertStatus(422);
    }

    /**
     * Test 9: BOP Progress calculation strictly reflects production completion (Completed / Required).
     */
    public function test_bop_progress_calculation_strictly_reflects_production_completion(): void
    {
        $testPartNo = 'PROGRESS-BOP-TEST-' . uniqid();
        $item = BomItem::create([
            'project_id' => $this->projectA->id,
            'standard_part_no' => $testPartNo,
            'part_type' => 'BOP',
            'supplier_id' => $this->supplier->id,
            'jig_no' => 'JIG-PRG',
            'unit_no' => 'Unit 1',
        ]);
        BomRequirement::create([
            'bom_item_id' => $item->id,
            'side' => 'COMMON',
            'required_quantity' => 100,
        ]);

        // 1. 100 Required / 0 Received / 0 Completed -> 0%
        $res = $this->getJson('/api/v1/bop/parts?project_id=' . $this->projectA->id);
        $part = collect($res->json('data.parts'))->firstWhere('standard_part_no', $testPartNo);
        $this->assertEquals(0, $part['completion_pct']);

        // 2. 100 Required / 50 Received / 0 Completed -> 0% (Receiving must NOT increase progress)
        $this->postJson('/api/v1/bop/transition', [
            'standard_part_no' => $testPartNo,
            'from_state' => 'pending',
            'to_state' => 'store',
            'quantity' => 50,
            'project_id' => $this->projectA->id,
        ])->assertStatus(200);

        $res = $this->getJson('/api/v1/bop/parts?project_id=' . $this->projectA->id);
        $part = collect($res->json('data.parts'))->firstWhere('standard_part_no', $testPartNo);
        $this->assertEquals(50, $part['total_received']);
        $this->assertEquals(0, $part['completion_pct'], 'Receiving 50% must not increase production progress');

        // 3. 100 Required / 100 Received / 0 Completed -> 0%
        $this->postJson('/api/v1/bop/transition', [
            'standard_part_no' => $testPartNo,
            'from_state' => 'pending',
            'to_state' => 'store',
            'quantity' => 50,
            'project_id' => $this->projectA->id,
        ])->assertStatus(200);

        $res = $this->getJson('/api/v1/bop/parts?project_id=' . $this->projectA->id);
        $part = collect($res->json('data.parts'))->firstWhere('standard_part_no', $testPartNo);
        $this->assertEquals(100, $part['total_received']);
        $this->assertEquals(0, $part['completion_pct'], '100% received must still show 0% progress if 0 completed');

        // 4. Move 30 pcs to Assembly -> still 0% Completed
        $this->postJson('/api/v1/bop/transition', [
            'standard_part_no' => $testPartNo,
            'from_state' => 'store',
            'to_state' => 'assembly',
            'quantity' => 30,
            'project_id' => $this->projectA->id,
        ])->assertStatus(200);

        $res = $this->getJson('/api/v1/bop/parts?project_id=' . $this->projectA->id);
        $part = collect($res->json('data.parts'))->firstWhere('standard_part_no', $testPartNo);
        $this->assertEquals(30, $part['parts_in_assembly']);
        $this->assertEquals(0, $part['completion_pct'], 'Assembly in-progress must not show completion');

        // 5. Complete 20 pcs from Assembly -> 20% Progress
        $this->postJson('/api/v1/bop/transition', [
            'standard_part_no' => $testPartNo,
            'from_state' => 'assembly',
            'to_state' => 'completed',
            'quantity' => 20,
            'project_id' => $this->projectA->id,
        ])->assertStatus(200);

        $res = $this->getJson('/api/v1/bop/parts?project_id=' . $this->projectA->id);
        $part = collect($res->json('data.parts'))->firstWhere('standard_part_no', $testPartNo);
        $this->assertEquals(20, $part['assembly_completed']);
        $this->assertEquals(20, $part['completion_pct'], '20/100 completed must equal exactly 20% progress');

        // 6. Move remaining 70 to Assembly and Complete 30 more -> 50% Progress
        $this->postJson('/api/v1/bop/transition', [
            'standard_part_no' => $testPartNo,
            'from_state' => 'store',
            'to_state' => 'assembly',
            'quantity' => 70,
            'project_id' => $this->projectA->id,
        ])->assertStatus(200);

        $this->postJson('/api/v1/bop/transition', [
            'standard_part_no' => $testPartNo,
            'from_state' => 'assembly',
            'to_state' => 'completed',
            'quantity' => 30,
            'project_id' => $this->projectA->id,
        ])->assertStatus(200);

        $res = $this->getJson('/api/v1/bop/parts?project_id=' . $this->projectA->id);
        $part = collect($res->json('data.parts'))->firstWhere('standard_part_no', $testPartNo);
        $this->assertEquals(50, $part['assembly_completed']);
        $this->assertEquals(50, $part['completion_pct'], '50/100 completed must equal 50%');

        // 7. Complete remaining 50 pcs -> 100% Progress
        $this->postJson('/api/v1/bop/transition', [
            'standard_part_no' => $testPartNo,
            'from_state' => 'assembly',
            'to_state' => 'completed',
            'quantity' => 50,
            'project_id' => $this->projectA->id,
        ])->assertStatus(200);

        $res = $this->getJson('/api/v1/bop/parts?project_id=' . $this->projectA->id);
        $part = collect($res->json('data.parts'))->firstWhere('standard_part_no', $testPartNo);
        $this->assertEquals(100, $part['assembly_completed']);
        $this->assertEquals(100, $part['completion_pct'], '100/100 completed must reach 100%');
    }
}
