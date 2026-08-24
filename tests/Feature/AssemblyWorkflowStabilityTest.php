<?php

namespace Tests\Feature;

use App\Events\AssemblyUpdated;
use App\Models\AssemblyRecord;
use App\Models\BomItem;
use App\Models\BomRequirement;
use App\Models\PaintRecord;
use App\Models\Project;
use App\Models\QcInspection;
use App\Models\Receipt;
use App\Models\ReceiptItem;
use App\Models\User;
use App\Models\WorkflowEvent;
use App\Services\HierarchyService;
use App\Services\QuantityCalculationService;
use Illuminate\Support\Facades\Event;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AssemblyWorkflowStabilityTest extends TestCase
{
    protected QuantityCalculationService $quantityService;
    protected HierarchyService $hierarchyService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->quantityService = new QuantityCalculationService();
        $this->hierarchyService = new HierarchyService($this->quantityService);
    }

    protected function getAuthUser(string $roleName = 'ADMIN'): User
    {
        $role = Role::firstOrCreate(['name' => $roleName, 'guard_name' => 'web']);
        $user = User::where('email', strtolower($roleName) . '@sparetrack.internal')->first();
        if (!$user) {
            $user = User::create([
                'name' => ucfirst($roleName) . ' User',
                'email' => strtolower($roleName) . '@sparetrack.internal',
                'password' => bcrypt('password123'),
            ]);
        }
        $user->syncRoles([$roleName]);
        return $user;
    }

    public function test_assembly_complete_single_quantity_from_paint()
    {
        Event::fake([AssemblyUpdated::class]);

        $user = $this->getAuthUser('ASSEMBLY');
        $this->actingAs($user, 'sanctum');

        $project = Project::create([
            'project_code' => 'TEST-ASM-01-' . uniqid(),
            'name' => 'Assembly Single Test Project',
            'status' => 'active',
        ]);

        $bomItem = BomItem::create([
            'project_id' => $project->id,
            'standard_part_no' => 'ASM-PART-001',
            'item_no' => '10',
            'jig_no' => 'JIG 01',
            'unit_no' => 'Unit 01',
        ]);

        BomRequirement::create([
            'bom_item_id' => $bomItem->id,
            'side' => 'RH',
            'required_quantity' => 2,
        ]);

        $receipt = Receipt::create([
            'project_id' => $project->id,
            'delivery_note_number' => 'DN-ASM-01',
            'received_by' => $user->id,
            'status' => 'completed',
        ]);

        $receiptItem = ReceiptItem::create([
            'receipt_id' => $receipt->id,
            'bom_item_id' => $bomItem->id,
            'side' => 'RH',
            'received_quantity' => 2,
            'status' => 'paint_completed',
        ]);

        $inspection = QcInspection::create([
            'receipt_item_id' => $receiptItem->id,
            'bom_item_id' => $bomItem->id,
            'inspected_by' => $user->id,
            'side' => 'RH',
            'result' => 'approved',
            'destination' => 'PAINT',
            'inspected_quantity' => 2,
            'approved_quantity' => 2,
            'inspection_date' => now()->toDateString(),
        ]);

        $paintRecord = PaintRecord::create([
            'bom_item_id' => $bomItem->id,
            'qc_inspection_id' => $inspection->id,
            'side' => 'RH',
            'quantity' => 2,
            'painted_by' => $user->id,
            'status' => 'completed',
            'completed_at' => now(),
        ]);

        // Complete 1 unit out of 2 in Assembly
        $response = $this->postJson('/api/v1/assembly/items', [
            'bom_item_id' => $bomItem->id,
            'paint_record_id' => $paintRecord->id,
            'side' => 'RH',
            'quantity' => 1,
            'remarks' => 'Partial 1 pc assembly complete',
        ]);

        $response->assertStatus(200);
        $response->assertJson([
            'success' => true,
            'message' => 'Assembly process completed successfully.',
            'quantity' => 1,
        ]);

        // Verify database records
        $this->assertDatabaseHas('assembly_records', [
            'bom_item_id' => $bomItem->id,
            'paint_record_id' => $paintRecord->id,
            'side' => 'RH',
            'quantity' => 1,
            'status' => 'completed',
        ]);

        // Verify partial quantity does not advance receiptItem status to assembly_completed yet
        $this->assertEquals('paint_completed', $receiptItem->fresh()->status);
        $this->assertEquals('completed', $paintRecord->fresh()->status);

        // Verify hierarchy stats: 1 ready, 1 completed
        $hierarchy = $this->hierarchyService->getDepartmentHierarchy('assembly', $project->id, ['side' => 'RH']);
        $partStats = $hierarchy['jigs'][0]['units'][0]['parts'][0]->side_stats['RH'];
        $this->assertEquals(1, $partStats['assembly_ready']);
        $this->assertEquals(1, $partStats['assembly_completed']);

        // Verify Event dispatched
        Event::assertDispatched(AssemblyUpdated::class);
    }

    public function test_assembly_complete_second_quantity_marks_receipt_completed()
    {
        $user = $this->getAuthUser('ASSEMBLY');
        $this->actingAs($user, 'sanctum');

        $project = Project::create([
            'project_code' => 'TEST-ASM-02-' . uniqid(),
            'name' => 'Assembly Full Test Project',
            'status' => 'active',
        ]);

        $bomItem = BomItem::create([
            'project_id' => $project->id,
            'standard_part_no' => 'ASM-PART-002',
            'item_no' => '20',
            'jig_no' => 'JIG 01',
            'unit_no' => 'Unit 01',
        ]);

        BomRequirement::create([
            'bom_item_id' => $bomItem->id,
            'side' => 'RH',
            'required_quantity' => 2,
        ]);

        $receipt = Receipt::create([
            'project_id' => $project->id,
            'delivery_note_number' => 'DN-ASM-02',
            'received_by' => $user->id,
            'status' => 'completed',
        ]);

        $receiptItem = ReceiptItem::create([
            'receipt_id' => $receipt->id,
            'bom_item_id' => $bomItem->id,
            'side' => 'RH',
            'received_quantity' => 2,
            'status' => 'paint_completed',
        ]);

        $inspection = QcInspection::create([
            'receipt_item_id' => $receiptItem->id,
            'bom_item_id' => $bomItem->id,
            'inspected_by' => $user->id,
            'side' => 'RH',
            'result' => 'approved',
            'destination' => 'PAINT',
            'inspected_quantity' => 2,
            'approved_quantity' => 2,
            'inspection_date' => now()->toDateString(),
        ]);

        $paintRecord = PaintRecord::create([
            'bom_item_id' => $bomItem->id,
            'qc_inspection_id' => $inspection->id,
            'side' => 'RH',
            'quantity' => 2,
            'painted_by' => $user->id,
            'status' => 'completed',
            'completed_at' => now(),
        ]);

        // Complete 1st unit
        $this->postJson('/api/v1/assembly/items', [
            'bom_item_id' => $bomItem->id,
            'paint_record_id' => $paintRecord->id,
            'side' => 'RH',
            'quantity' => 1,
        ])->assertStatus(200);

        // Complete 2nd unit (finishing the batch)
        $this->postJson('/api/v1/assembly/items', [
            'bom_item_id' => $bomItem->id,
            'paint_record_id' => $paintRecord->id,
            'side' => 'RH',
            'quantity' => 1,
        ])->assertStatus(200);

        // Now both paintRecord and receiptItem must be advanced to assembled
        $this->assertEquals('assembled', $paintRecord->fresh()->status);
        $this->assertEquals('assembly_completed', $receiptItem->fresh()->status);

        // Verify hierarchy stats
        $hierarchy = $this->hierarchyService->getDepartmentHierarchy('assembly', $project->id, ['side' => 'RH']);
        $partStats = $hierarchy['jigs'][0]['units'][0]['parts'][0]->side_stats['RH'];
        $this->assertEquals(0, $partStats['assembly_ready']);
        $this->assertEquals(2, $partStats['assembly_completed']);
        $this->assertTrue($partStats['is_done']);
    }

    public function test_assembly_complete_from_direct_qc_routing()
    {
        $user = $this->getAuthUser('ASSEMBLY');
        $this->actingAs($user, 'sanctum');

        $project = Project::create([
            'project_code' => 'TEST-ASM-QC-' . uniqid(),
            'name' => 'Assembly Direct QC Project',
            'status' => 'active',
        ]);

        $bomItem = BomItem::create([
            'project_id' => $project->id,
            'standard_part_no' => 'ASM-DIRECT-001',
            'item_no' => '30',
            'jig_no' => 'JIG 02',
            'unit_no' => 'Unit 01',
        ]);

        BomRequirement::create([
            'bom_item_id' => $bomItem->id,
            'side' => 'LH',
            'required_quantity' => 3,
        ]);

        $receipt = Receipt::create([
            'project_id' => $project->id,
            'delivery_note_number' => 'DN-ASM-03',
            'received_by' => $user->id,
            'status' => 'completed',
        ]);

        $receiptItem = ReceiptItem::create([
            'receipt_id' => $receipt->id,
            'bom_item_id' => $bomItem->id,
            'side' => 'LH',
            'received_quantity' => 3,
            'status' => 'qc_approved',
        ]);

        // Route directly to ASSEMBLY
        $inspection = QcInspection::create([
            'receipt_item_id' => $receiptItem->id,
            'bom_item_id' => $bomItem->id,
            'inspected_by' => $user->id,
            'side' => 'LH',
            'result' => 'approved',
            'destination' => 'ASSEMBLY',
            'inspected_quantity' => 3,
            'approved_quantity' => 3,
            'inspection_date' => now()->toDateString(),
        ]);

        // Complete 3 units directly from QC inspection
        $response = $this->postJson('/api/v1/assembly/items', [
            'bom_item_id' => $bomItem->id,
            'qc_inspection_id' => $inspection->id,
            'side' => 'LH',
            'quantity' => 3,
            'remarks' => 'Direct QC assembly complete',
        ]);

        $response->assertStatus(200);
        $this->assertEquals('assembly_completed', $receiptItem->fresh()->status);

        $this->assertDatabaseHas('assembly_records', [
            'bom_item_id' => $bomItem->id,
            'qc_inspection_id' => $inspection->id,
            'side' => 'LH',
            'quantity' => 3,
            'status' => 'completed',
        ]);
    }

    public function test_assembly_auto_resolves_when_parent_ids_are_null()
    {
        $user = $this->getAuthUser('ASSEMBLY');
        $this->actingAs($user, 'sanctum');

        $project = Project::create([
            'project_code' => 'TEST-ASM-AUTO-' . uniqid(),
            'name' => 'Assembly Auto Resolve Project',
            'status' => 'active',
        ]);

        $bomItem = BomItem::create([
            'project_id' => $project->id,
            'standard_part_no' => 'ASM-AUTO-001',
            'item_no' => '40',
            'jig_no' => 'JIG 03',
            'unit_no' => 'Unit 01',
        ]);

        BomRequirement::create([
            'bom_item_id' => $bomItem->id,
            'side' => 'RH',
            'required_quantity' => 1,
        ]);

        $receipt = Receipt::create([
            'project_id' => $project->id,
            'delivery_note_number' => 'DN-ASM-04',
            'received_by' => $user->id,
            'status' => 'completed',
        ]);

        $receiptItem = ReceiptItem::create([
            'receipt_id' => $receipt->id,
            'bom_item_id' => $bomItem->id,
            'side' => 'RH',
            'received_quantity' => 1,
            'status' => 'paint_completed',
        ]);

        $inspection = QcInspection::create([
            'receipt_item_id' => $receiptItem->id,
            'bom_item_id' => $bomItem->id,
            'inspected_by' => $user->id,
            'side' => 'RH',
            'result' => 'approved',
            'destination' => 'PAINT',
            'inspected_quantity' => 1,
            'approved_quantity' => 1,
            'inspection_date' => now()->toDateString(),
        ]);

        $paintRecord = PaintRecord::create([
            'bom_item_id' => $bomItem->id,
            'qc_inspection_id' => $inspection->id,
            'side' => 'RH',
            'quantity' => 1,
            'painted_by' => $user->id,
            'status' => 'completed',
            'completed_at' => now(),
        ]);

        // Send request WITHOUT paint_record_id or qc_inspection_id
        $response = $this->postJson('/api/v1/assembly/items', [
            'bom_item_id' => $bomItem->id,
            'paint_record_id' => null,
            'qc_inspection_id' => null,
            'side' => 'RH',
            'quantity' => 1,
            'remarks' => 'Auto-resolved assembly complete',
        ]);

        $response->assertStatus(200);
        $response->assertJson([
            'success' => true,
            'quantity' => 1,
        ]);

        $this->assertDatabaseHas('assembly_records', [
            'bom_item_id' => $bomItem->id,
            'paint_record_id' => $paintRecord->id,
            'side' => 'RH',
            'quantity' => 1,
        ]);
    }

    public function test_assembly_rejects_excess_quantity()
    {
        $user = $this->getAuthUser('ASSEMBLY');
        $this->actingAs($user, 'sanctum');

        $project = Project::create([
            'project_code' => 'TEST-ASM-EXCESS-' . uniqid(),
            'name' => 'Assembly Excess Project',
            'status' => 'active',
        ]);

        $bomItem = BomItem::create([
            'project_id' => $project->id,
            'standard_part_no' => 'ASM-EXCESS-001',
            'item_no' => '50',
            'jig_no' => 'JIG 04',
            'unit_no' => 'Unit 01',
        ]);

        BomRequirement::create([
            'bom_item_id' => $bomItem->id,
            'side' => 'RH',
            'required_quantity' => 2,
        ]);

        $receipt = Receipt::create([
            'project_id' => $project->id,
            'delivery_note_number' => 'DN-ASM-05',
            'received_by' => $user->id,
            'status' => 'completed',
        ]);

        $receiptItem = ReceiptItem::create([
            'receipt_id' => $receipt->id,
            'bom_item_id' => $bomItem->id,
            'side' => 'RH',
            'received_quantity' => 2,
            'status' => 'paint_completed',
        ]);

        $inspection = QcInspection::create([
            'receipt_item_id' => $receiptItem->id,
            'bom_item_id' => $bomItem->id,
            'inspected_by' => $user->id,
            'side' => 'RH',
            'result' => 'approved',
            'destination' => 'PAINT',
            'inspected_quantity' => 2,
            'approved_quantity' => 2,
            'inspection_date' => now()->toDateString(),
        ]);

        $paintRecord = PaintRecord::create([
            'bom_item_id' => $bomItem->id,
            'qc_inspection_id' => $inspection->id,
            'side' => 'RH',
            'quantity' => 2,
            'painted_by' => $user->id,
            'status' => 'completed',
            'completed_at' => now(),
        ]);

        // Attempt to assemble 5 units when only 2 are available
        $response = $this->postJson('/api/v1/assembly/items', [
            'bom_item_id' => $bomItem->id,
            'paint_record_id' => $paintRecord->id,
            'side' => 'RH',
            'quantity' => 5,
        ]);

        $response->assertStatus(422);
        $response->assertJson([
            'success' => false,
        ]);
        $this->assertStringContainsString('Only 2 units available', $response->json('message'));
    }

    public function test_assembly_bulk_complete_with_side_isolation()
    {
        $user = $this->getAuthUser('ASSEMBLY');
        $this->actingAs($user, 'sanctum');

        $project = Project::create([
            'project_code' => 'TEST-ASM-BULK-' . uniqid(),
            'name' => 'Assembly Bulk Project',
            'status' => 'active',
        ]);

        $item1 = BomItem::create([
            'project_id' => $project->id,
            'standard_part_no' => 'ASM-BULK-001',
            'item_no' => '60',
            'jig_no' => 'JIG 05',
            'unit_no' => 'Unit 01',
        ]);

        $item2 = BomItem::create([
            'project_id' => $project->id,
            'standard_part_no' => 'ASM-BULK-002',
            'item_no' => '61',
            'jig_no' => 'JIG 05',
            'unit_no' => 'Unit 01',
        ]);

        BomRequirement::create(['bom_item_id' => $item1->id, 'side' => 'RH', 'required_quantity' => 2]);
        BomRequirement::create(['bom_item_id' => $item2->id, 'side' => 'RH', 'required_quantity' => 3]);

        $receipt = Receipt::create([
            'project_id' => $project->id,
            'delivery_note_number' => 'DN-ASM-06',
            'received_by' => $user->id,
            'status' => 'completed',
        ]);

        $rec1 = ReceiptItem::create(['receipt_id' => $receipt->id, 'bom_item_id' => $item1->id, 'side' => 'RH', 'received_quantity' => 2, 'status' => 'paint_completed']);
        $rec2 = ReceiptItem::create(['receipt_id' => $receipt->id, 'bom_item_id' => $item2->id, 'side' => 'RH', 'received_quantity' => 3, 'status' => 'paint_completed']);

        $insp1 = QcInspection::create(['receipt_item_id' => $rec1->id, 'bom_item_id' => $item1->id, 'inspected_by' => $user->id, 'side' => 'RH', 'result' => 'approved', 'destination' => 'PAINT', 'inspected_quantity' => 2, 'approved_quantity' => 2, 'inspection_date' => now()->toDateString()]);
        $insp2 = QcInspection::create(['receipt_item_id' => $rec2->id, 'bom_item_id' => $item2->id, 'inspected_by' => $user->id, 'side' => 'RH', 'result' => 'approved', 'destination' => 'PAINT', 'inspected_quantity' => 3, 'approved_quantity' => 3, 'inspection_date' => now()->toDateString()]);

        $p1 = PaintRecord::create(['bom_item_id' => $item1->id, 'qc_inspection_id' => $insp1->id, 'side' => 'RH', 'quantity' => 2, 'painted_by' => $user->id, 'status' => 'completed', 'completed_at' => now()]);
        $p2 = PaintRecord::create(['bom_item_id' => $item2->id, 'qc_inspection_id' => $insp2->id, 'side' => 'RH', 'quantity' => 3, 'painted_by' => $user->id, 'status' => 'completed', 'completed_at' => now()]);

        $response = $this->postJson('/api/v1/assembly/bulk-complete', [
            'items' => [
                ['bom_item_id' => $item1->id, 'paint_record_id' => $p1->id, 'side' => 'RH', 'quantity' => 2],
                ['bom_item_id' => $item2->id, 'paint_record_id' => $p2->id, 'side' => 'RH', 'quantity' => 3],
            ],
            'remarks' => 'Bulk assembly test',
        ]);

        $response->assertStatus(200);
        $response->assertJson([
            'success' => true,
            'processed_count' => 2,
        ]);

        $this->assertEquals('assembly_completed', $rec1->fresh()->status);
        $this->assertEquals('assembly_completed', $rec2->fresh()->status);
    }

    public function test_dashboard_mathematical_invariants_preserved()
    {
        $user = $this->getAuthUser('ASSEMBLY');
        $this->actingAs($user, 'sanctum');

        $project = Project::create([
            'project_code' => 'TEST-ASM-MATH-' . uniqid(),
            'name' => 'Assembly Math Preservation Project',
            'status' => 'active',
        ]);

        $bomItem = BomItem::create([
            'project_id' => $project->id,
            'standard_part_no' => 'ASM-MATH-001',
            'item_no' => '70',
            'jig_no' => 'JIG 06',
            'unit_no' => 'Unit 01',
        ]);

        BomRequirement::create(['bom_item_id' => $bomItem->id, 'side' => 'RH', 'required_quantity' => 10]);

        $receipt = Receipt::create([
            'project_id' => $project->id,
            'delivery_note_number' => 'DN-ASM-07',
            'received_by' => $user->id,
            'status' => 'completed',
        ]);

        $receiptItem = ReceiptItem::create([
            'receipt_id' => $receipt->id,
            'bom_item_id' => $bomItem->id,
            'side' => 'RH',
            'received_quantity' => 6,
            'status' => 'paint_completed',
        ]);

        $inspection = QcInspection::create([
            'receipt_item_id' => $receiptItem->id,
            'bom_item_id' => $bomItem->id,
            'inspected_by' => $user->id,
            'side' => 'RH',
            'result' => 'approved',
            'destination' => 'PAINT',
            'inspected_quantity' => 6,
            'approved_quantity' => 6,
            'inspection_date' => now()->toDateString(),
        ]);

        $paintRecord = PaintRecord::create([
            'bom_item_id' => $bomItem->id,
            'qc_inspection_id' => $inspection->id,
            'side' => 'RH',
            'quantity' => 6,
            'painted_by' => $user->id,
            'status' => 'completed',
            'completed_at' => now(),
        ]);

        // Prior metrics
        $priorMetrics = $this->quantityService->calculateProjectMetrics($project, 'RH');
        $this->assertEquals(10, $priorMetrics['total_required']);
        $this->assertEquals(6, $priorMetrics['total_received']);
        $this->assertEquals(4, $priorMetrics['total_pending']);
        $this->assertEquals(6, $priorMetrics['assembly_ready']);
        $this->assertEquals(0, $priorMetrics['assembly_completed']);

        // Complete 4 in Assembly
        $this->postJson('/api/v1/assembly/items', [
            'bom_item_id' => $bomItem->id,
            'paint_record_id' => $paintRecord->id,
            'side' => 'RH',
            'quantity' => 4,
        ])->assertStatus(200);

        // After metrics: Canonical invariants MUST hold:
        // total_required = 10 (unchanged)
        // total_received = 6 (unchanged)
        // total_pending = 4 (unchanged)
        // assembly_ready = 2
        // assembly_completed = 4
        $afterMetrics = $this->quantityService->calculateProjectMetrics($project, 'RH');
        $this->assertEquals(10, $afterMetrics['total_required']);
        $this->assertEquals(6, $afterMetrics['total_received']);
        $this->assertEquals(4, $afterMetrics['total_pending']);
        $this->assertEquals(2, $afterMetrics['assembly_ready']);
        $this->assertEquals(4, $afterMetrics['assembly_completed']);

        // Total Parts Received = Parts In Store + In QC + In Rework + In Paint + Assembly Ready + Assembly Completed
        $residentSum = $afterMetrics['parts_in_store'] +
                       $afterMetrics['parts_in_qc'] +
                       $afterMetrics['parts_in_rework'] +
                       $afterMetrics['parts_in_paint'] +
                       $afterMetrics['assembly_ready'] +
                       $afterMetrics['assembly_completed'];

        $this->assertEquals($afterMetrics['total_received'], $residentSum);
    }
}
