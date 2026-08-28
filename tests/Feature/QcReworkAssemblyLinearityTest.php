<?php

namespace Tests\Feature;

use App\Models\BomItem;
use App\Models\BomRequirement;
use App\Models\Jig;
use App\Models\Project;
use App\Models\ReceiptItem;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class QcReworkAssemblyLinearityTest extends TestCase
{
    use DatabaseTransactions;

    protected User $admin;
    protected Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        $role = \Spatie\Permission\Models\Role::firstOrCreate(['name' => 'ADMIN', 'guard_name' => 'web']);
        $this->admin = User::firstOrCreate(
            ['email' => 'admin-linearity@sparetrack.internal'],
            [
                'name' => 'Admin User',
                'password' => bcrypt('password123'),
            ]
        );
        $this->admin->syncRoles(['ADMIN']);

        $this->project = Project::create([
            'project_code' => 'PRJ-REWORK-ASM',
            'name' => 'QC Rework Assembly Test Project',
            'status' => 'active',
        ]);
    }

    protected function createPartWithRequirement(string $partNo, int $requiredQty, string $side = 'LH'): BomItem
    {
        $bomItem = BomItem::create([
            'project_id' => $this->project->id,
            'jig_no' => 'JIG-100',
            'unit_no' => 'UNIT-01',
            'standard_part_no' => $partNo,
            'item_no' => $partNo,
            'description' => "Test Part {$partNo}",
        ]);

        BomRequirement::create([
            'bom_item_id' => $bomItem->id,
            'side' => $side,
            'required_quantity' => $requiredQty,
        ]);

        return $bomItem;
    }

    /**
     * Exact Production Scenario:
     * 2 required on LH.
     * QC split: 1 Direct Assembly, 1 Rework.
     * 1st piece is assembled.
     * 2nd piece completes rework, is reinspected and approved to Assembly in QC.
     * 2nd piece is assembled -> must succeed cleanly without 0 available error!
     */
    public function test_qc_split_rework_to_qc_to_assembly_workflow_succeeds()
    {
        $part = $this->createPartWithRequirement('170#R00', 2, 'LH');

        // 1. Store receipt: 2 pcs received
        $storeRes = $this->actingAs($this->admin)->postJson('/api/v1/store/receipts', [
            'project_id' => $this->project->id,
            'delivery_note_number' => 'DN-001',
            'items' => [
                ['bom_item_id' => $part->id, 'side' => 'LH', 'received_quantity' => 2]
            ]
        ]);
        $storeRes->assertStatus(200);

        // 2. QC Physical arrival: 2 pcs
        $recItem = ReceiptItem::where('bom_item_id', $part->id)->where('side', 'LH')->first();
        $this->assertNotNull($recItem);

        $arrivalRes = $this->actingAs($this->admin)->postJson('/api/v1/qc/receive', [
            'receipt_item_id' => $recItem->id,
            'bom_item_id' => $part->id,
            'side' => 'LH',
            'quantity' => 2,
        ]);
        $arrivalRes->assertStatus(200);

        // 3. QC Split Inspection: 1 pc directly to Assembly, 1 pc to Rework
        $splitRes = $this->actingAs($this->admin)->postJson('/api/v1/qc/inspect', [
            'receipt_item_id' => $recItem->id,
            'bom_item_id' => $part->id,
            'side' => 'LH',
            'result' => 'partial',
            'approved_quantity' => 1,
            'assembly_quantity' => 1,
            'paint_quantity' => 0,
            'rework_quantity' => 1,
            'destination' => 'ASSEMBLY',
            'rework_reason' => 'Edge deburring needed',
        ]);
        $splitRes->assertStatus(200);

        // Verify state after QC split
        $calcService = app(\App\Services\QuantityCalculationService::class);
        $summary = $calcService->calculateProjectMetrics($this->project);
        $this->assertEquals(1, $summary['parts_in_assembly']);
        $this->assertEquals(1, $summary['parts_in_rework']);
        $this->assertEquals(0, $summary['assembly_completed']);

        // 4. Assemble 1st piece (from direct QC approval)
        $asm1Res = $this->actingAs($this->admin)->postJson('/api/v1/assembly/items', [
            'bom_item_id' => $part->id,
            'side' => 'LH',
            'quantity' => 1,
            'remarks' => '1st unit assembled from direct QC',
        ]);
        $asm1Res->assertStatus(200);
        $this->assertTrue($asm1Res->json('success'));

        $summary = $calcService->calculateProjectMetrics($this->project);
        $this->assertEquals(0, $summary['parts_in_assembly']);
        $this->assertEquals(1, $summary['assembly_completed']);
        $this->assertEquals(1, $summary['parts_in_rework']);

        // 5. Complete Rework for 2nd piece -> returns to QC
        $reworkRes = $this->actingAs($this->admin)->postJson('/api/v1/rework/complete', [
            'bom_item_id' => $part->id,
            'side' => 'LH',
            'quantity' => 1,
            'completion_notes' => 'Deburring completed',
        ]);
        $reworkRes->assertStatus(200);

        $summary = $calcService->calculateProjectMetrics($this->project);
        $this->assertEquals(0, $summary['parts_in_rework']);
        $this->assertEquals(1, $summary['parts_in_qc']);
        $this->assertEquals(1, $summary['assembly_completed']);

        // 6. QC Reinspects and Approves 2nd piece directly to Assembly
        $reinspectRes = $this->actingAs($this->admin)->postJson('/api/v1/qc/inspect', [
            'bom_item_id' => $part->id,
            'receipt_item_id' => 0, // auto-resolved from qc_received
            'side' => 'LH',
            'result' => 'approved',
            'approved_quantity' => 1,
            'destination' => 'ASSEMBLY',
            'assembly_quantity' => 1,
            'paint_quantity' => 0,
        ]);
        $reinspectRes->assertStatus(200);

        $summary = $calcService->calculateProjectMetrics($this->project);
        $this->assertEquals(0, $summary['parts_in_qc']);
        $this->assertEquals(1, $summary['parts_in_assembly']);
        $this->assertEquals(1, $summary['assembly_completed']);

        // 7. Assemble 2nd piece -> MUST SUCCEED (Eliminates "Only 0 units available for assembly" bug!)
        $asm2Res = $this->actingAs($this->admin)->postJson('/api/v1/assembly/items', [
            'bom_item_id' => $part->id,
            'side' => 'LH',
            'quantity' => 1,
            'remarks' => '2nd unit assembled after rework & QC approval',
        ]);
        $asm2Res->assertStatus(200);
        $this->assertTrue($asm2Res->json('success'));

        // Final state: 2 completed, 0 active assembly, 0 rework, 0 pending
        $summary = $calcService->calculateProjectMetrics($this->project);
        $this->assertEquals(2, $summary['total_required']);
        $this->assertEquals(2, $summary['total_received']);
        $this->assertEquals(0, $summary['total_pending']);
        $this->assertEquals(0, $summary['parts_in_assembly']);
        $this->assertEquals(2, $summary['assembly_completed']);
        $this->assertEquals(0, $summary['parts_in_rework']);
        $this->assertEquals(0, $summary['parts_in_qc']);
    }

    /**
     * Paint & Assembly Scenario:
     * 2 required on LH.
     * QC split: 1 Direct Paint, 1 Rework.
     * 1st piece painted and assembled.
     * 2nd piece completes rework, approved to Paint in QC, painted, and assembled.
     */
    public function test_qc_split_rework_to_qc_to_paint_to_assembly_workflow_succeeds()
    {
        $part = $this->createPartWithRequirement('PAINT-TEST-01', 2, 'LH');

        // 1. Store receipt: 2 pcs
        $this->actingAs($this->admin)->postJson('/api/v1/store/receipts', [
            'project_id' => $this->project->id,
            'delivery_note_number' => 'DN-PAINT-001',
            'items' => [['bom_item_id' => $part->id, 'side' => 'LH', 'received_quantity' => 2]]
        ])->assertStatus(200);

        // 2. QC Physical arrival
        $recItem = ReceiptItem::where('bom_item_id', $part->id)->where('side', 'LH')->first();
        $this->actingAs($this->admin)->postJson('/api/v1/qc/receive', [
            'receipt_item_id' => $recItem->id,
            'bom_item_id' => $part->id,
            'side' => 'LH',
            'quantity' => 2,
        ])->assertStatus(200);

        // 3. QC Split: 1 to Paint, 1 to Rework
        $this->actingAs($this->admin)->postJson('/api/v1/qc/inspect', [
            'receipt_item_id' => $recItem->id,
            'bom_item_id' => $part->id,
            'side' => 'LH',
            'result' => 'partial',
            'approved_quantity' => 1,
            'paint_quantity' => 1,
            'assembly_quantity' => 0,
            'rework_quantity' => 1,
            'destination' => 'PAINT',
            'rework_reason' => 'Surface rust',
        ])->assertStatus(200);

        // 4. Paint 1st piece
        $paint1Res = $this->actingAs($this->admin)->postJson('/api/v1/paint/items', [
            'bom_item_id' => $part->id,
            'side' => 'LH',
            'quantity' => 1,
            'paint_type' => 'Powder Coat',
        ]);
        $paint1Res->assertStatus(200);

        // 5. Assemble 1st piece from Paint
        $asm1Res = $this->actingAs($this->admin)->postJson('/api/v1/assembly/items', [
            'bom_item_id' => $part->id,
            'side' => 'LH',
            'quantity' => 1,
        ]);
        $asm1Res->assertStatus(200);

        // 6. Complete Rework for 2nd piece
        $this->actingAs($this->admin)->postJson('/api/v1/rework/complete', [
            'bom_item_id' => $part->id,
            'side' => 'LH',
            'quantity' => 1,
        ])->assertStatus(200);

        // 7. QC Reinspects and approves 2nd piece to Paint
        $this->actingAs($this->admin)->postJson('/api/v1/qc/inspect', [
            'bom_item_id' => $part->id,
            'receipt_item_id' => 0,
            'side' => 'LH',
            'result' => 'approved',
            'approved_quantity' => 1,
            'destination' => 'PAINT',
            'paint_quantity' => 1,
            'assembly_quantity' => 0,
        ])->assertStatus(200);

        // 8. Paint 2nd piece -> must succeed
        $paint2Res = $this->actingAs($this->admin)->postJson('/api/v1/paint/items', [
            'bom_item_id' => $part->id,
            'side' => 'LH',
            'quantity' => 1,
            'paint_type' => 'Powder Coat',
        ]);
        $paint2Res->assertStatus(200);

        // 9. Assemble 2nd piece from Paint -> must succeed
        $asm2Res = $this->actingAs($this->admin)->postJson('/api/v1/assembly/items', [
            'bom_item_id' => $part->id,
            'side' => 'LH',
            'quantity' => 1,
        ]);
        $asm2Res->assertStatus(200);

        $calcService = app(\App\Services\QuantityCalculationService::class);
        $summary = $calcService->calculateProjectMetrics($this->project);
        $this->assertEquals(2, $summary['assembly_completed']);
        $this->assertEquals(0, $summary['parts_in_assembly']);
        $this->assertEquals(0, $summary['parts_in_paint']);
    }

    /**
     * Multi-source batch assembly: 2 units assemble in single call from mixed sources.
     */
    public function test_multi_source_simultaneous_assembly_fulfillment()
    {
        $part = $this->createPartWithRequirement('BATCH-ASM-01', 2, 'LH');

        // Store & QC arrival
        $this->actingAs($this->admin)->postJson('/api/v1/store/receipts', [
            'project_id' => $this->project->id,
            'delivery_note_number' => 'DN-BATCH',
            'items' => [['bom_item_id' => $part->id, 'side' => 'LH', 'received_quantity' => 2]]
        ])->assertStatus(200);

        $recItem = ReceiptItem::where('bom_item_id', $part->id)->where('side', 'LH')->first();
        $this->actingAs($this->admin)->postJson('/api/v1/qc/receive', [
            'receipt_item_id' => $recItem->id,
            'bom_item_id' => $part->id,
            'side' => 'LH',
            'quantity' => 2,
        ])->assertStatus(200);

        // Split in QC: 1 to Direct Assembly, 1 to Paint
        $this->actingAs($this->admin)->postJson('/api/v1/qc/inspect', [
            'receipt_item_id' => $recItem->id,
            'bom_item_id' => $part->id,
            'side' => 'LH',
            'result' => 'partial',
            'approved_quantity' => 2,
            'assembly_quantity' => 1,
            'paint_quantity' => 1,
        ])->assertStatus(200);

        // Paint the 1 pc
        $this->actingAs($this->admin)->postJson('/api/v1/paint/items', [
            'bom_item_id' => $part->id,
            'side' => 'LH',
            'quantity' => 1,
        ])->assertStatus(200);

        // Now we have 2 pcs ready for assembly: 1 from Direct QC, 1 from Paint
        $calcService = app(\App\Services\QuantityCalculationService::class);
        $summary = $calcService->calculateProjectMetrics($this->project);
        $this->assertEquals(2, $summary['parts_in_assembly']);

        // Complete 2 pcs in single Assembly call
        $asmRes = $this->actingAs($this->admin)->postJson('/api/v1/assembly/items', [
            'bom_item_id' => $part->id,
            'side' => 'LH',
            'quantity' => 2,
            'remarks' => 'Fulfill 2 pcs from mixed sources',
        ]);
        $asmRes->assertStatus(200);
        $this->assertTrue($asmRes->json('success'));

        $summary = $calcService->calculateProjectMetrics($this->project);
        $this->assertEquals(2, $summary['assembly_completed']);
        $this->assertEquals(0, $summary['parts_in_assembly']);
    }

    /**
     * Strict side isolation: LH assembly does not consume RH stock.
     */
    public function test_assembly_strict_side_isolation()
    {
        $part = BomItem::create([
            'project_id' => $this->project->id,
            'jig_no' => 'JIG-100',
            'unit_no' => 'UNIT-01',
            'standard_part_no' => 'SIDE-ISO-01',
            'item_no' => 'SIDE-ISO-01',
        ]);
        BomRequirement::create(['bom_item_id' => $part->id, 'side' => 'LH', 'required_quantity' => 1]);
        BomRequirement::create(['bom_item_id' => $part->id, 'side' => 'RH', 'required_quantity' => 1]);

        // Receive both LH and RH in Store
        $this->actingAs($this->admin)->postJson('/api/v1/store/receipts', [
            'project_id' => $this->project->id,
            'delivery_note_number' => 'DN-SIDE-ISO',
            'items' => [
                ['bom_item_id' => $part->id, 'side' => 'LH', 'received_quantity' => 1],
                ['bom_item_id' => $part->id, 'side' => 'RH', 'received_quantity' => 1],
            ]
        ])->assertStatus(200);

        // QC receive & inspect LH only to Assembly
        $lhRec = ReceiptItem::where('bom_item_id', $part->id)->where('side', 'LH')->first();
        $this->actingAs($this->admin)->postJson('/api/v1/qc/receive', [
            'receipt_item_id' => $lhRec->id,
            'bom_item_id' => $part->id,
            'side' => 'LH',
            'quantity' => 1,
        ])->assertStatus(200);

        $this->actingAs($this->admin)->postJson('/api/v1/qc/inspect', [
            'receipt_item_id' => $lhRec->id,
            'bom_item_id' => $part->id,
            'side' => 'LH',
            'result' => 'approved',
            'approved_quantity' => 1,
            'destination' => 'ASSEMBLY',
        ])->assertStatus(200);

        // Attempting to assemble RH must fail because only LH has assembly stock
        $rhFailRes = $this->actingAs($this->admin)->postJson('/api/v1/assembly/items', [
            'bom_item_id' => $part->id,
            'side' => 'RH',
            'quantity' => 1,
        ]);
        $rhFailRes->assertStatus(422);

        // Assemble LH must succeed
        $lhSuccessRes = $this->actingAs($this->admin)->postJson('/api/v1/assembly/items', [
            'bom_item_id' => $part->id,
            'side' => 'LH',
            'quantity' => 1,
        ]);
        $lhSuccessRes->assertStatus(200);
    }
}
