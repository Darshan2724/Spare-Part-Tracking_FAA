<?php

namespace Tests\Feature;

use App\Models\AssemblyRecord;
use App\Models\BomItem;
use App\Models\BomRequirement;
use App\Models\PaintRecord;
use App\Models\Project;
use App\Models\QcInspection;
use App\Models\Receipt;
use App\Models\ReceiptItem;
use App\Models\ReworkRecord;
use App\Models\User;
use App\Services\QuantityCalculationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WorkflowStrictRevertSystemTest extends TestCase
{
    use RefreshDatabase;

    protected User $adminUser;
    protected Project $project;
    protected BomItem $bomItem;
    protected BomItem $bomItem2;
    protected QuantityCalculationService $calcService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->calcService = app(QuantityCalculationService::class);

        \Spatie\Permission\Models\Role::firstOrCreate(['name' => 'ADMIN', 'guard_name' => 'web']);
        \Spatie\Permission\Models\Role::firstOrCreate(['name' => 'ADMIN', 'guard_name' => 'sanctum']);

        $this->adminUser = User::firstOrCreate(
            ['email' => 'admin-revert@sparetrack.internal'],
            [
                'name' => 'Admin User',
                'password' => bcrypt('password123'),
            ]
        );
        $this->adminUser->syncRoles(['ADMIN']);

        $this->project = Project::create([
            'project_code' => 'FA-REV-01',
            'name' => 'Automotive Tooling Project',
            'customer_name' => 'Automotive Tooling Corp',
            'status' => 'active',
        ]);

        $this->bomItem = BomItem::create([
            'project_id' => $this->project->id,
            'jig_no' => '169961@',
            'unit_no' => '01',
            'item_no' => '020',
            'standard_part_no' => '020#R00',
            'part_description' => 'Revert Test Bracket',
        ]);

        // Requirement: LH = 2, RH = 2
        BomRequirement::create([
            'bom_item_id' => $this->bomItem->id,
            'side' => 'LH',
            'required_quantity' => 2,
        ]);
        BomRequirement::create([
            'bom_item_id' => $this->bomItem->id,
            'side' => 'RH',
            'required_quantity' => 2,
        ]);
    }

    /**
     * Helper to create store receipt.
     */
    protected function createStoreReceipt(string $side, int $quantity, ?BomItem $item = null): ReceiptItem
    {
        $targetItem = $item ?? $this->bomItem;
        $receipt = Receipt::create([
            'project_id' => $this->project->id,
            'received_by' => $this->adminUser->id,
            'receipt_number' => 'REC-' . uniqid(),
            'received_date' => now(),
        ]);

        return ReceiptItem::create([
            'receipt_id' => $receipt->id,
            'bom_item_id' => $targetItem->id,
            'side' => $side,
            'received_quantity' => $quantity,
            'status' => 'received',
        ]);
    }

    /**
     * Test 1: QC -> Store Revert (Physical Arrival reverted to Store Bay).
     */
    public function test_qc_can_revert_physical_arrival_to_store(): void
    {
        $rec = $this->createStoreReceipt('LH', 2);
        // Mark physical arrival in QC
        $rec->update(['status' => 'qc_received']);

        $metricsBefore = $this->calcService->calculateProjectMetrics($this->project, 'LH');
        $this->assertEquals(0, $metricsBefore['parts_in_store']);
        $this->assertEquals(2, $metricsBefore['parts_in_qc']);
        $this->assertEquals(2, $metricsBefore['total_received']);

        // Revert 1 unit from QC back to Store
        $response = $this->actingAs($this->adminUser)->postJson('/api/v1/workflow/revert', [
            'department' => 'qc',
            'bom_item_id' => $this->bomItem->id,
            'side' => 'LH',
            'quantity' => 1,
            'reason' => 'Store sent wrong batch to QC bay',
        ]);

        $response->assertStatus(200);
        $response->assertJson([
            'success' => true,
            'reverted_quantity' => 1,
            'from_department' => 'QC',
            'to_department' => 'STORE',
        ]);

        $metricsAfter = $this->calcService->calculateProjectMetrics($this->project, 'LH');
        $this->assertEquals(1, $metricsAfter['parts_in_store']);
        $this->assertEquals(1, $metricsAfter['parts_in_qc']);
        $this->assertEquals(2, $metricsAfter['total_received']); // Conservation
        $this->assertEquals(0, $metricsAfter['total_pending']);
    }

    /**
     * Test 2: Rework -> QC Revert (Rework allocation reverted back to QC queue).
     */
    public function test_rework_can_be_reverted_to_qc(): void
    {
        $rec = $this->createStoreReceipt('LH', 2);
        $rec->update(['status' => 'qc_received']);

        // QC routes 2 to Rework
        $insp = QcInspection::create([
            'bom_item_id' => $this->bomItem->id,
            'receipt_item_id' => $rec->id,
            'side' => 'LH',
            'inspected_quantity' => 2,
            'rework_quantity' => 2,
            'approved_quantity' => 0,
            'rejected_quantity' => 0,
            'result' => 'rework',
            'inspected_by' => $this->adminUser->id,
            'inspection_date' => now(),
        ]);

        $metricsBefore = $this->calcService->calculateProjectMetrics($this->project, 'LH');
        $this->assertEquals(2, $metricsBefore['parts_in_rework']);
        $this->assertEquals(0, $metricsBefore['parts_in_qc']);

        // Revert 1 unit from Rework back to QC
        $response = $this->actingAs($this->adminUser)->postJson('/api/v1/workflow/revert', [
            'department' => 'rework',
            'bom_item_id' => $this->bomItem->id,
            'side' => 'LH',
            'source_type' => 'qc_inspection',
            'source_id' => $insp->id,
            'quantity' => 1,
            'reason' => 'Minor burr easily removed at QC station',
        ]);

        $response->assertStatus(200);

        $metricsAfter = $this->calcService->calculateProjectMetrics($this->project, 'LH');
        $this->assertEquals(1, $metricsAfter['parts_in_rework']);
        $this->assertEquals(1, $metricsAfter['parts_in_qc']);
        $this->assertEquals(2, $metricsAfter['total_received']);
    }

    /**
     * Test 3: Paint -> QC Revert (Approved for paint reverted back to QC queue).
     */
    public function test_paint_can_be_reverted_to_qc(): void
    {
        $rec = $this->createStoreReceipt('LH', 2);
        $rec->update(['status' => 'qc_received']);

        $insp = QcInspection::create([
            'bom_item_id' => $this->bomItem->id,
            'receipt_item_id' => $rec->id,
            'side' => 'LH',
            'inspected_quantity' => 2,
            'approved_quantity' => 2,
            'paint_quantity' => 2,
            'destination' => 'PAINT',
            'result' => 'approved',
            'inspected_by' => $this->adminUser->id,
            'inspection_date' => now(),
        ]);

        $metricsBefore = $this->calcService->calculateProjectMetrics($this->project, 'LH');
        $this->assertEquals(2, $metricsBefore['parts_in_paint']);
        $this->assertEquals(0, $metricsBefore['parts_in_qc']);

        // Revert 2 units from Paint back to QC
        $response = $this->actingAs($this->adminUser)->postJson('/api/v1/workflow/revert', [
            'department' => 'paint',
            'bom_item_id' => $this->bomItem->id,
            'side' => 'LH',
            'quantity' => 2,
            'reason' => 'Defect discovered before powder coat application',
        ]);

        $response->assertStatus(200);

        $metricsAfter = $this->calcService->calculateProjectMetrics($this->project, 'LH');
        $this->assertEquals(0, $metricsAfter['parts_in_paint']);
        $this->assertEquals(2, $metricsAfter['parts_in_qc']);
        $this->assertEquals(2, $metricsAfter['total_received']);
    }

    /**
     * Test 4: Direct Assembly -> QC Revert.
     */
    public function test_direct_assembly_can_be_reverted_to_qc(): void
    {
        $rec = $this->createStoreReceipt('LH', 2);
        $rec->update(['status' => 'qc_received']);

        $insp = QcInspection::create([
            'bom_item_id' => $this->bomItem->id,
            'receipt_item_id' => $rec->id,
            'side' => 'LH',
            'inspected_quantity' => 2,
            'approved_quantity' => 2,
            'assembly_quantity' => 2,
            'destination' => 'ASSEMBLY',
            'result' => 'approved',
            'inspected_by' => $this->adminUser->id,
            'inspection_date' => now(),
        ]);

        $metricsBefore = $this->calcService->calculateProjectMetrics($this->project, 'LH');
        $this->assertEquals(2, $metricsBefore['parts_in_assembly']);
        $this->assertEquals(0, $metricsBefore['parts_in_qc']);

        // Revert 1 unit from Assembly back to QC
        $response = $this->actingAs($this->adminUser)->postJson('/api/v1/workflow/revert', [
            'department' => 'assembly',
            'bom_item_id' => $this->bomItem->id,
            'side' => 'LH',
            'source_type' => 'qc_inspection',
            'source_id' => $insp->id,
            'quantity' => 1,
            'reason' => 'Fitment issue reported by assembly fitter',
        ]);

        $response->assertStatus(200);
        $response->assertJson([
            'success' => true,
            'from_department' => 'ASSEMBLY',
            'to_department' => 'QC',
        ]);

        $metricsAfter = $this->calcService->calculateProjectMetrics($this->project, 'LH');
        $this->assertEquals(1, $metricsAfter['parts_in_assembly']);
        $this->assertEquals(1, $metricsAfter['parts_in_qc']);
        $this->assertEquals(2, $metricsAfter['total_received']);
    }

    /**
     * Test 5: Paint -> Assembly -> Revert to Paint.
     */
    public function test_painted_assembly_can_be_reverted_to_paint(): void
    {
        $rec = $this->createStoreReceipt('LH', 2);
        $rec->update(['status' => 'qc_received']);

        $insp = QcInspection::create([
            'bom_item_id' => $this->bomItem->id,
            'receipt_item_id' => $rec->id,
            'side' => 'LH',
            'inspected_quantity' => 2,
            'approved_quantity' => 2,
            'paint_quantity' => 2,
            'destination' => 'PAINT',
            'result' => 'approved',
            'inspected_by' => $this->adminUser->id,
            'inspection_date' => now(),
        ]);

        // Paint completes 2
        $paint = PaintRecord::create([
            'bom_item_id' => $this->bomItem->id,
            'qc_inspection_id' => $insp->id,
            'side' => 'LH',
            'quantity' => 2,
            'status' => 'completed',
            'painted_by' => $this->adminUser->id,
        ]);

        $metricsBefore = $this->calcService->calculateProjectMetrics($this->project, 'LH');
        $this->assertEquals(2, $metricsBefore['parts_in_assembly']);
        $this->assertEquals(0, $metricsBefore['parts_in_paint']);

        // Assembly reverts 1 back to Paint
        $response = $this->actingAs($this->adminUser)->postJson('/api/v1/workflow/revert', [
            'department' => 'assembly',
            'bom_item_id' => $this->bomItem->id,
            'side' => 'LH',
            'source_type' => 'paint_record',
            'source_id' => $paint->id,
            'quantity' => 1,
            'reason' => 'Paint scratch noticed in assembly bay, re-spray needed',
        ]);

        $response->assertStatus(200);
        $response->assertJson([
            'success' => true,
            'from_department' => 'ASSEMBLY',
            'to_department' => 'PAINT',
        ]);

        $metricsAfter = $this->calcService->calculateProjectMetrics($this->project, 'LH');
        $this->assertEquals(1, $metricsAfter['parts_in_assembly']);
        $this->assertEquals(1, $metricsAfter['parts_in_paint']);
        $this->assertEquals(2, $metricsAfter['total_received']);
    }

    /**
     * Test 6: Multi-Source Lineage Discovery & Revert in Assembly (QC + Paint mix).
     */
    public function test_mixed_lineage_assembly_reverts_correctly_to_respective_sources(): void
    {
        BomRequirement::where('bom_item_id', $this->bomItem->id)->where('side', 'LH')->update(['required_quantity' => 3]);

        $rec = $this->createStoreReceipt('LH', 3);
        $rec->update(['status' => 'qc_received']);

        // 1 pc approved Direct to Assembly
        $inspDirect = QcInspection::create([
            'bom_item_id' => $this->bomItem->id,
            'receipt_item_id' => $rec->id,
            'side' => 'LH',
            'inspected_quantity' => 1,
            'approved_quantity' => 1,
            'assembly_quantity' => 1,
            'destination' => 'ASSEMBLY',
            'result' => 'approved',
            'inspected_by' => $this->adminUser->id,
            'inspection_date' => now(),
        ]);

        // 2 pcs approved for Paint
        $inspPaint = QcInspection::create([
            'bom_item_id' => $this->bomItem->id,
            'receipt_item_id' => $rec->id,
            'side' => 'LH',
            'inspected_quantity' => 2,
            'approved_quantity' => 2,
            'paint_quantity' => 2,
            'destination' => 'PAINT',
            'result' => 'approved',
            'inspected_by' => $this->adminUser->id,
            'inspection_date' => now(),
        ]);

        // Paint completes 2 pcs
        $paintRec = PaintRecord::create([
            'bom_item_id' => $this->bomItem->id,
            'qc_inspection_id' => $inspPaint->id,
            'side' => 'LH',
            'quantity' => 2,
            'status' => 'completed',
            'painted_by' => $this->adminUser->id,
        ]);

        // Verify Assembly has 3 available
        $metricsStart = $this->calcService->calculateProjectMetrics($this->project, 'LH');
        $this->assertEquals(3, $metricsStart['parts_in_assembly']);

        // Check revert options endpoint returns both distinct lineage sources
        $optionsRes = $this->actingAs($this->adminUser)->getJson("/api/v1/workflow/revert-options?department=assembly&bom_item_id={$this->bomItem->id}&side=LH");
        $optionsRes->assertStatus(200);
        $optionsRes->assertJsonCount(2, 'options');

        // Revert 1 unit from Paint segment to Paint Shop
        $res1 = $this->actingAs($this->adminUser)->postJson('/api/v1/workflow/revert', [
            'department' => 'assembly',
            'bom_item_id' => $this->bomItem->id,
            'side' => 'LH',
            'source_type' => 'paint_record',
            'source_id' => $paintRec->id,
            'quantity' => 1,
        ]);
        $res1->assertStatus(200);
        $res1->assertJson(['to_department' => 'PAINT']);

        // Revert 1 unit from Direct QC segment to QC Bay
        $res2 = $this->actingAs($this->adminUser)->postJson('/api/v1/workflow/revert', [
            'department' => 'assembly',
            'bom_item_id' => $this->bomItem->id,
            'side' => 'LH',
            'source_type' => 'qc_inspection',
            'source_id' => $inspDirect->id,
            'quantity' => 1,
        ]);
        $res2->assertStatus(200);
        $res2->assertJson(['to_department' => 'QC']);

        // Final verification: 1 in Assembly (from Paint), 1 in Paint, 1 in QC
        $metricsFinal = $this->calcService->calculateProjectMetrics($this->project, 'LH');
        $this->assertEquals(1, $metricsFinal['parts_in_assembly']);
        $this->assertEquals(1, $metricsFinal['parts_in_paint']);
        $this->assertEquals(1, $metricsFinal['parts_in_qc']);
        $this->assertEquals(3, $metricsFinal['raw_received']);
    }

    /**
     * Test 7: Strict Side Isolation during Reverts (LH vs RH).
     */
    public function test_revert_maintains_strict_side_isolation(): void
    {
        // LH = 2, RH = 2 in Paint
        $recLh = $this->createStoreReceipt('LH', 2);
        $recLh->update(['status' => 'qc_received']);
        $inspLh = QcInspection::create([
            'bom_item_id' => $this->bomItem->id,
            'receipt_item_id' => $recLh->id,
            'side' => 'LH',
            'inspected_quantity' => 2,
            'approved_quantity' => 2,
            'destination' => 'PAINT',
            'result' => 'approved',
            'inspected_by' => $this->adminUser->id,
            'inspection_date' => now(),
        ]);

        $recRh = $this->createStoreReceipt('RH', 2);
        $recRh->update(['status' => 'qc_received']);
        $inspRh = QcInspection::create([
            'bom_item_id' => $this->bomItem->id,
            'receipt_item_id' => $recRh->id,
            'side' => 'RH',
            'inspected_quantity' => 2,
            'approved_quantity' => 2,
            'destination' => 'PAINT',
            'result' => 'approved',
            'inspected_by' => $this->adminUser->id,
            'inspection_date' => now(),
        ]);

        // Revert 1 unit of LH from Paint back to QC
        $response = $this->actingAs($this->adminUser)->postJson('/api/v1/workflow/revert', [
            'department' => 'paint',
            'bom_item_id' => $this->bomItem->id,
            'side' => 'LH',
            'quantity' => 1,
        ]);
        $response->assertStatus(200);

        $metricsLh = $this->calcService->calculateProjectMetrics($this->project, 'LH');
        $metricsRh = $this->calcService->calculateProjectMetrics($this->project, 'RH');

        // LH: Paint = 1, QC = 1
        $this->assertEquals(1, $metricsLh['parts_in_paint']);
        $this->assertEquals(1, $metricsLh['parts_in_qc']);

        // RH: Paint = 2, QC = 0 (completely untouched)
        $this->assertEquals(2, $metricsRh['parts_in_paint']);
        $this->assertEquals(0, $metricsRh['parts_in_qc']);
    }

    /**
     * Test 8: Over-Revert protection returns 422.
     */
    public function test_revert_exceeding_available_quantity_fails_with_422(): void
    {
        $rec = $this->createStoreReceipt('LH', 1);
        $rec->update(['status' => 'qc_received']);

        $response = $this->actingAs($this->adminUser)->postJson('/api/v1/workflow/revert', [
            'department' => 'qc',
            'bom_item_id' => $this->bomItem->id,
            'side' => 'LH',
            'quantity' => 5, // Exceeds available 1
        ]);

        $response->assertStatus(422);
        $response->assertJson(['success' => false]);
    }

    /**
     * Test 9: Store -> Pending Supplier Arrival Revert (Restores to Pending Arrival).
     */
    public function test_store_can_revert_receipt_to_pending_supplier_arrival(): void
    {
        $rec = $this->createStoreReceipt('LH', 2);

        $metricsBefore = $this->calcService->calculateProjectMetrics($this->project, 'LH');
        $this->assertEquals(2, $metricsBefore['parts_in_store']);
        $this->assertEquals(2, $metricsBefore['total_received']);
        $this->assertEquals(0, $metricsBefore['total_pending']);

        // Revert 1 unit from Store back to Pending Arrival
        $response = $this->actingAs($this->adminUser)->postJson('/api/v1/workflow/revert', [
            'department' => 'store',
            'bom_item_id' => $this->bomItem->id,
            'side' => 'LH',
            'quantity' => 1,
            'reason' => 'Damaged shipping box rejected upon review',
        ]);

        $response->assertStatus(200);
        $response->assertJson([
            'success' => true,
            'reverted_quantity' => 1,
            'from_department' => 'STORE',
            'to_department' => 'PENDING_ARRIVAL',
        ]);

        $metricsAfter = $this->calcService->calculateProjectMetrics($this->project, 'LH');
        $this->assertEquals(1, $metricsAfter['parts_in_store']);
        $this->assertEquals(1, $metricsAfter['total_received']);
        $this->assertEquals(1, $metricsAfter['total_pending']);
    }

    /**
     * Test 10: Sequential / Concurrency Revert Depletion Protection.
     */
    public function test_sequential_reverts_deplete_and_then_fail_cleanly(): void
    {
        $rec = $this->createStoreReceipt('LH', 2);
        $rec->update(['status' => 'qc_received']);

        // First revert of 1
        $res1 = $this->actingAs($this->adminUser)->postJson('/api/v1/workflow/revert', [
            'department' => 'qc',
            'bom_item_id' => $this->bomItem->id,
            'side' => 'LH',
            'quantity' => 1,
        ]);
        $res1->assertStatus(200);

        // Second revert of 1 (exhausts balance)
        $res2 = $this->actingAs($this->adminUser)->postJson('/api/v1/workflow/revert', [
            'department' => 'qc',
            'bom_item_id' => $this->bomItem->id,
            'side' => 'LH',
            'quantity' => 1,
        ]);
        $res2->assertStatus(200);

        // Third revert of 1 (must fail with 422 because balance is exhausted)
        $res3 = $this->actingAs($this->adminUser)->postJson('/api/v1/workflow/revert', [
            'department' => 'qc',
            'bom_item_id' => $this->bomItem->id,
            'side' => 'LH',
            'quantity' => 1,
        ]);
        $res3->assertStatus(422);
        $res3->assertJson(['success' => false]);
    }

    /**
     * Helper to create a secondary BOM item with requirements for bulk testing.
     */
    protected function createSecondaryBomItem(): BomItem
    {
        $item = BomItem::create([
            'project_id' => $this->project->id,
            'jig_no' => '169961@',
            'unit_no' => '01',
            'item_no' => '030',
            'standard_part_no' => '030#R00',
            'part_description' => 'Revert Secondary Bracket',
        ]);

        BomRequirement::create([
            'bom_item_id' => $item->id,
            'side' => 'LH',
            'required_quantity' => 2,
        ]);
        BomRequirement::create([
            'bom_item_id' => $item->id,
            'side' => 'RH',
            'required_quantity' => 2,
        ]);

        return $item;
    }

    /**
     * Test 11: Bulk Revert in Store (multiple parts reverted atomically to supplier pending arrival).
     */
    public function test_bulk_revert_multiple_items_in_store_atomically(): void
    {
        $item2 = $this->createSecondaryBomItem();
        $rec1 = $this->createStoreReceipt('LH', 2, $this->bomItem);
        $rec2 = $this->createStoreReceipt('LH', 2, $item2);

        $response = $this->actingAs($this->adminUser)->postJson('/api/v1/workflow/bulk-revert', [
            'department' => 'store',
            'items' => [
                ['bom_item_id' => $this->bomItem->id, 'side' => 'LH', 'quantity' => 1],
                ['bom_item_id' => $item2->id, 'side' => 'LH', 'quantity' => 1],
            ],
            'reason' => 'Bulk store return to vendor',
        ]);

        $response->assertStatus(200);
        $response->assertJson([
            'success' => true,
            'total_reverted' => 2,
            'items_count' => 2,
        ]);

        $metricsLh = $this->calcService->calculateProjectMetrics($this->project, 'LH');
        $this->assertEquals(2, $metricsLh['parts_in_store']); // 1 + 1 remaining
        $this->assertEquals(2, $metricsLh['total_received']);
        $this->assertEquals(2, $metricsLh['total_pending']); // Restored 2 pending
    }

    /**
     * Test 12: Bulk Revert in QC (multiple parts physical arrival reverted to Store Bay).
     */
    public function test_bulk_revert_multiple_items_in_qc_to_store_atomically(): void
    {
        $item2 = $this->createSecondaryBomItem();
        $rec1 = $this->createStoreReceipt('LH', 2, $this->bomItem);
        $rec1->update(['status' => 'qc_received']);

        $rec2 = $this->createStoreReceipt('LH', 2, $item2);
        $rec2->update(['status' => 'qc_received']);

        $response = $this->actingAs($this->adminUser)->postJson('/api/v1/workflow/bulk-revert', [
            'department' => 'qc',
            'items' => [
                ['bom_item_id' => $this->bomItem->id, 'side' => 'LH', 'quantity' => 2],
                ['bom_item_id' => $item2->id, 'side' => 'LH', 'quantity' => 1],
            ],
            'reason' => 'Bulk return to store inspection error',
        ]);

        $response->assertStatus(200);
        $response->assertJson([
            'success' => true,
            'total_reverted' => 3,
            'items_count' => 2,
        ]);

        $metricsLh = $this->calcService->calculateProjectMetrics($this->project, 'LH');
        $this->assertEquals(3, $metricsLh['parts_in_store']); // 2 from item1 + 1 from item2
        $this->assertEquals(1, $metricsLh['parts_in_qc']); // 1 from item2
    }

    /**
     * Test 13: Bulk Revert in Rework back to QC.
     */
    public function test_bulk_revert_multiple_items_in_rework_to_qc_atomically(): void
    {
        $item2 = $this->createSecondaryBomItem();
        $rec1 = $this->createStoreReceipt('LH', 2, $this->bomItem);
        $rec1->update(['status' => 'qc_received']);
        QcInspection::create([
            'receipt_item_id' => $rec1->id,
            'bom_item_id' => $this->bomItem->id,
            'side' => 'LH',
            'inspected_quantity' => 2,
            'rework_quantity' => 2,
            'result' => 'rework',
            'status' => 'rework_needed',
            'inspected_by' => $this->adminUser->id,
        ]);

        $rec2 = $this->createStoreReceipt('LH', 2, $item2);
        $rec2->update(['status' => 'qc_received']);
        QcInspection::create([
            'receipt_item_id' => $rec2->id,
            'bom_item_id' => $item2->id,
            'side' => 'LH',
            'inspected_quantity' => 2,
            'rework_quantity' => 2,
            'result' => 'rework',
            'status' => 'rework_needed',
            'inspected_by' => $this->adminUser->id,
        ]);

        $response = $this->actingAs($this->adminUser)->postJson('/api/v1/workflow/bulk-revert', [
            'department' => 'rework',
            'items' => [
                ['bom_item_id' => $this->bomItem->id, 'side' => 'LH', 'quantity' => 1],
                ['bom_item_id' => $item2->id, 'side' => 'LH', 'quantity' => 2],
            ],
            'reason' => 'False rework trigger corrected',
        ]);

        $response->assertStatus(200);
        $response->assertJson([
            'success' => true,
            'total_reverted' => 3,
            'items_count' => 2,
        ]);

        $metricsLh = $this->calcService->calculateProjectMetrics($this->project, 'LH');
        $this->assertEquals(1, $metricsLh['parts_in_rework']); // 1 left on item1
        $this->assertEquals(3, $metricsLh['parts_in_qc']); // 1 from item1 + 2 from item2
    }

    /**
     * Test 14: Bulk Revert in Paint back to QC.
     */
    public function test_bulk_revert_multiple_items_in_paint_to_qc_atomically(): void
    {
        $item2 = $this->createSecondaryBomItem();
        $rec1 = $this->createStoreReceipt('LH', 2, $this->bomItem);
        $rec1->update(['status' => 'qc_received']);
        QcInspection::create([
            'receipt_item_id' => $rec1->id,
            'bom_item_id' => $this->bomItem->id,
            'side' => 'LH',
            'inspected_quantity' => 2,
            'approved_quantity' => 2,
            'destination' => 'PAINT',
            'result' => 'approved',
            'status' => 'approved',
            'inspected_by' => $this->adminUser->id,
        ]);

        $rec2 = $this->createStoreReceipt('LH', 2, $item2);
        $rec2->update(['status' => 'qc_received']);
        QcInspection::create([
            'receipt_item_id' => $rec2->id,
            'bom_item_id' => $item2->id,
            'side' => 'LH',
            'inspected_quantity' => 2,
            'approved_quantity' => 2,
            'destination' => 'PAINT',
            'result' => 'approved',
            'status' => 'approved',
            'inspected_by' => $this->adminUser->id,
        ]);

        $response = $this->actingAs($this->adminUser)->postJson('/api/v1/workflow/bulk-revert', [
            'department' => 'paint',
            'items' => [
                ['bom_item_id' => $this->bomItem->id, 'side' => 'LH', 'quantity' => 2],
                ['bom_item_id' => $item2->id, 'side' => 'LH', 'quantity' => 1],
            ],
            'reason' => 'Quality re-check required before painting',
        ]);

        $response->assertStatus(200);
        $response->assertJson([
            'success' => true,
            'total_reverted' => 3,
            'items_count' => 2,
        ]);

        $metricsLh = $this->calcService->calculateProjectMetrics($this->project, 'LH');
        $this->assertEquals(1, $metricsLh['parts_in_paint']); // 1 left on item2
        $this->assertEquals(3, $metricsLh['parts_in_qc']); // 2 from item1 + 1 from item2
    }

    /**
     * Test 15: Bulk Revert Failure Rollback (Atomicity guarantee).
     */
    public function test_bulk_revert_fails_and_rolls_back_if_any_single_item_exceeds_quantity(): void
    {
        $item2 = $this->createSecondaryBomItem();
        $rec1 = $this->createStoreReceipt('LH', 2, $this->bomItem);
        $rec1->update(['status' => 'qc_received']);

        $rec2 = $this->createStoreReceipt('LH', 1, $item2);
        $rec2->update(['status' => 'qc_received']);

        // Item 1 has 2, Item 2 only has 1. We request 1 for Item 1 and 5 for Item 2.
        $response = $this->actingAs($this->adminUser)->postJson('/api/v1/workflow/bulk-revert', [
            'department' => 'qc',
            'items' => [
                ['bom_item_id' => $this->bomItem->id, 'side' => 'LH', 'quantity' => 1],
                ['bom_item_id' => $item2->id, 'side' => 'LH', 'quantity' => 5], // Fails
            ],
        ]);

        $response->assertStatus(422);
        $response->assertJson(['success' => false]);

        // Entire transaction rolled back: both items remain unchanged in QC
        $metricsLh = $this->calcService->calculateProjectMetrics($this->project, 'LH');
        $this->assertEquals(3, $metricsLh['parts_in_qc']);
        $this->assertEquals(0, $metricsLh['parts_in_store']);
    }

    /**
     * Test 16: Global Department Revert Items Endpoint.
     */
    public function test_global_department_revert_items_endpoint_returns_hierarchical_records_without_unit_id(): void
    {
        // Setup Store receipt
        $storeRec = $this->createStoreReceipt('LH', 3);

        // Setup QC arrived receipt
        $item2 = $this->createSecondaryBomItem();
        $qcRec = $this->createStoreReceipt('RH', 2, $item2);
        $qcRec->update(['status' => 'qc_received']);

        // Query Store global revert queue
        $storeResp = $this->actingAs($this->adminUser)->getJson('/api/v1/workflow/revert-items?department=store');
        $storeResp->assertStatus(200);
        $storeResp->assertJson(['success' => true, 'department' => 'store']);
        $storeItems = $storeResp->json('items');
        $this->assertNotEmpty($storeItems);
        $this->assertEquals('STORE', $storeItems[0]['from_department']);
        $this->assertEquals('PENDING_ARRIVAL', $storeItems[0]['to_department']);

        // Query QC global revert queue
        $qcResp = $this->actingAs($this->adminUser)->getJson('/api/v1/workflow/revert-items?department=qc');
        $qcResp->assertStatus(200);
        $qcResp->assertJson(['success' => true, 'department' => 'qc']);
        $qcItems = $qcResp->json('items');
        $this->assertNotEmpty($qcItems);
        $this->assertEquals('QC', $qcItems[0]['from_department']);
        $this->assertEquals('STORE', $qcItems[0]['to_department']);

        // Test project filter
        $filterResp = $this->actingAs($this->adminUser)->getJson("/api/v1/workflow/revert-items?department=store&project_id={$this->project->id}&side=LH");
        $filterResp->assertStatus(200);
        $this->assertGreaterThanOrEqual(1, $filterResp->json('total'));
    }

    /**
     * Test 21: Full End-to-End Revert Destination Queue Verification (Assembly -> QC).
     * Proves that reverting from Assembly to QC makes the part immediately visible in:
     * 1. QC Quality Inspection Queue API (/qc/queue?stage=inspection)
     * 2. QC Department Hierarchy API (/qc/hierarchy)
     * 3. QC Revert Inside Unit API (/workflow/revert-options?department=qc)
     * 4. QC Global Department Revert Queue API (/workflow/revert-items?department=qc)
     */
    public function test_assembly_revert_to_qc_makes_part_immediately_visible_in_qc_queues(): void
    {
        // 1. Initial intake in Store -> QC arrival
        $rec = $this->createStoreReceipt('LH', 2);
        $rec->update(['status' => 'qc_received']);

        // 2. QC inspects and approves directly for Assembly
        $insp = QcInspection::create([
            'bom_item_id' => $this->bomItem->id,
            'receipt_item_id' => $rec->id,
            'side' => 'LH',
            'inspected_quantity' => 2,
            'approved_quantity' => 2,
            'assembly_quantity' => 2,
            'destination' => 'ASSEMBLY',
            'result' => 'approved',
            'inspected_by' => $this->adminUser->id,
            'inspection_date' => now(),
        ]);
        $rec->update(['status' => 'qc_approved']);

        // Before revert: QC queue has 0 uninspected parts for this project
        $qcQueueBefore = $this->actingAs($this->adminUser)->getJson("/api/v1/qc/queue?stage=inspection&project_id={$this->project->id}&side=LH");
        $qcQueueBefore->assertStatus(200);
        $this->assertEmpty($qcQueueBefore->json('data'));

        // 3. User reverts 1 part from Assembly to QC
        $revertResp = $this->actingAs($this->adminUser)->postJson('/api/v1/workflow/revert', [
            'department' => 'assembly',
            'bom_item_id' => $this->bomItem->id,
            'side' => 'LH',
            'source_type' => 'qc_inspection',
            'source_id' => $insp->id,
            'quantity' => 1,
            'reason' => 'Assembly fitment error - return to QC',
        ]);
        $revertResp->assertStatus(200);
        $revertResp->assertJson([
            'success' => true,
            'from_department' => 'ASSEMBLY',
            'to_department' => 'QC',
            'reverted_quantity' => 1,
        ]);

        // 4. Verify Immediate Real-Time Visibility in QC Inspection Queue
        $qcQueueAfter = $this->actingAs($this->adminUser)->getJson("/api/v1/qc/queue?stage=inspection&project_id={$this->project->id}&side=LH");
        $qcQueueAfter->assertStatus(200);
        $qcItems = $qcQueueAfter->json('data');
        $this->assertCount(1, $qcItems);
        $this->assertEquals($this->bomItem->id, $qcItems[0]['bom_item_id']);
        $this->assertEquals('qc_received', $qcItems[0]['status']);
        $this->assertEquals(1, $qcItems[0]['received_quantity']);

        // 5. Verify QC Hierarchy reflects 1 pending inspection part
        $qcHierResp = $this->actingAs($this->adminUser)->getJson("/api/v1/qc/hierarchy?project_id={$this->project->id}&side=LH");
        $qcHierResp->assertStatus(200);
        $jigs = $qcHierResp->json('jigs');
        $this->assertNotEmpty($jigs);
        $this->assertEquals(1, $jigs[0]['metrics']['qc_pending_inspection']);

        // 6. Verify QC Revert-Options API inside unit
        $unitRevertOptions = $this->actingAs($this->adminUser)->getJson("/api/v1/workflow/revert-options?department=qc&bom_item_id={$this->bomItem->id}&side=LH");
        $unitRevertOptions->assertStatus(200);
        $this->assertTrue($unitRevertOptions->json('success'));
        $this->assertGreaterThanOrEqual(1, $unitRevertOptions->json('total_revertible'));

        // 7. Verify QC Global Department Revert-Items Queue API
        $qcGlobalRevert = $this->actingAs($this->adminUser)->getJson("/api/v1/workflow/revert-items?department=qc&project_id={$this->project->id}");
        $qcGlobalRevert->assertStatus(200);
        $this->assertTrue($qcGlobalRevert->json('success'));
        $this->assertNotEmpty($qcGlobalRevert->json('items') ?? $qcGlobalRevert->json('data'));
    }

    /**
     * Test 22: Paint -> QC Revert Destination Queue Verification.
     */
    public function test_paint_revert_to_qc_makes_part_immediately_visible_in_qc_queues(): void
    {
        $rec = $this->createStoreReceipt('LH', 2);
        $rec->update(['status' => 'qc_received']);

        $insp = QcInspection::create([
            'bom_item_id' => $this->bomItem->id,
            'receipt_item_id' => $rec->id,
            'side' => 'LH',
            'inspected_quantity' => 2,
            'approved_quantity' => 2,
            'paint_quantity' => 2,
            'destination' => 'PAINT',
            'result' => 'approved',
            'inspected_by' => $this->adminUser->id,
            'inspection_date' => now(),
        ]);
        $rec->update(['status' => 'qc_approved']);

        // Revert 1 unit from Paint to QC
        $revertResp = $this->actingAs($this->adminUser)->postJson('/api/v1/workflow/revert', [
            'department' => 'paint',
            'bom_item_id' => $this->bomItem->id,
            'side' => 'LH',
            'source_type' => 'qc_inspection',
            'source_id' => $insp->id,
            'quantity' => 1,
            'reason' => 'Paint shop batch rejected before spray',
        ]);
        $revertResp->assertStatus(200);

        // Verify QC Queue has 1 uninspected part
        $qcQueueAfter = $this->actingAs($this->adminUser)->getJson("/api/v1/qc/queue?stage=inspection&project_id={$this->project->id}&side=LH");
        $qcQueueAfter->assertStatus(200);
        $qcItems = $qcQueueAfter->json('data');
        $this->assertCount(1, $qcItems);
        $this->assertEquals('qc_received', $qcItems[0]['status']);
        $this->assertEquals(1, $qcItems[0]['received_quantity']);

        // Verify Paint still has 1 unit
        $paintQueueResp = $this->actingAs($this->adminUser)->getJson("/api/v1/paint/queue?project_id={$this->project->id}&side=LH");
        $paintQueueResp->assertStatus(200);
        $paintItems = $paintQueueResp->json('data');
        $this->assertCount(1, $paintItems);
        $this->assertEquals(1, $paintItems[0]['available_paint_quantity']);
    }

    /**
     * Test 23: Rework -> QC Revert Destination Queue Verification.
     */
    public function test_rework_revert_to_qc_makes_part_immediately_visible_in_qc_queues(): void
    {
        $rec = $this->createStoreReceipt('LH', 2);
        $rec->update(['status' => 'qc_received']);

        $insp = QcInspection::create([
            'bom_item_id' => $this->bomItem->id,
            'receipt_item_id' => $rec->id,
            'side' => 'LH',
            'inspected_quantity' => 2,
            'approved_quantity' => 0,
            'rework_quantity' => 2,
            'result' => 'rework',
            'inspected_by' => $this->adminUser->id,
            'inspection_date' => now(),
        ]);
        $rec->update(['status' => 'qc_rework']);

        // Revert 1 unit from Rework to QC
        $revertResp = $this->actingAs($this->adminUser)->postJson('/api/v1/workflow/revert', [
            'department' => 'rework',
            'bom_item_id' => $this->bomItem->id,
            'side' => 'LH',
            'source_type' => 'qc_inspection',
            'source_id' => $insp->id,
            'quantity' => 1,
            'reason' => 'Rework deemed unnecessary by QC Lead',
        ]);
        $revertResp->assertStatus(200);

        // Verify QC Queue has 1 uninspected part
        $qcQueueAfter = $this->actingAs($this->adminUser)->getJson("/api/v1/qc/queue?stage=inspection&project_id={$this->project->id}&side=LH");
        $qcQueueAfter->assertStatus(200);
        $qcItems = $qcQueueAfter->json('data');
        $this->assertCount(1, $qcItems);
        $this->assertEquals('qc_received', $qcItems[0]['status']);
        $this->assertEquals(1, $qcItems[0]['received_quantity']);
    }

    public function test_complete_four_stage_reverse_chain_from_assembly_to_qc_to_store_to_pending_arrival()
    {
        // 1. Initial State: Store Receipt (2 units)
        $rec = $this->createStoreReceipt('LH', 2);
        $rec->update(['status' => 'qc_received']);

        // 2. QC Approves 2 units to Assembly
        $insp = QcInspection::create([
            'bom_item_id' => $this->bomItem->id,
            'receipt_item_id' => $rec->id,
            'side' => 'LH',
            'inspected_quantity' => 2,
            'approved_quantity' => 2,
            'destination' => 'ASSEMBLY',
            'result' => 'approved',
            'inspected_by' => $this->adminUser->id,
            'inspection_date' => now(),
        ]);
        $rec->update(['status' => 'qc_approved']);

        // 3. Stage 1 Revert: Assembly -> QC (1 unit)
        $revertToQcResp = $this->actingAs($this->adminUser)->postJson('/api/v1/workflow/revert', [
            'department' => 'assembly',
            'bom_item_id' => $this->bomItem->id,
            'side' => 'LH',
            'source_type' => 'qc_inspection',
            'source_id' => $insp->id,
            'quantity' => 1,
            'reason' => 'Assembly quality mismatch, returning to QC',
        ]);
        $revertToQcResp->assertStatus(200);

        // 4. Stage 2 Revert: QC -> Store (1 unit)
        $qcOptions = $this->actingAs($this->adminUser)->getJson("/api/v1/workflow/revert-options?department=qc&bom_item_id={$this->bomItem->id}&side=LH");
        $qcOptions->assertStatus(200);
        $qcSourceId = $qcOptions->json('options.0.source_id');
        $this->assertNotNull($qcSourceId);

        $revertToStoreResp = $this->actingAs($this->adminUser)->postJson('/api/v1/workflow/revert', [
            'department' => 'qc',
            'bom_item_id' => $this->bomItem->id,
            'side' => 'LH',
            'source_type' => 'receipt_item',
            'source_id' => $qcSourceId,
            'quantity' => 1,
            'reason' => 'Wrong part delivered to QC, returning to Store stock',
        ]);
        $revertToStoreResp->assertStatus(200);

        // 5. Verification in Global Store Revert queue
        $globalStoreRevert = $this->actingAs($this->adminUser)->getJson("/api/v1/workflow/revert-items?department=store&project_id={$this->project->id}&side=LH");
        $globalStoreRevert->assertStatus(200);
        $globalItems = $globalStoreRevert->json('items');
        $this->assertNotEmpty($globalItems);
        $this->assertEquals(1, $globalItems[0]['available_quantity']);
        $this->assertEquals('STORE', $globalItems[0]['from_department']);
        $this->assertEquals('PENDING_ARRIVAL', $globalItems[0]['to_department']);

        // 6. Verification in Unit-Level Store Revert options
        $storeOptions = $this->actingAs($this->adminUser)->getJson("/api/v1/workflow/revert-options?department=store&bom_item_id={$this->bomItem->id}&side=LH");
        $storeOptions->assertStatus(200);
        $storeSourceId = $storeOptions->json('options.0.source_id');
        $this->assertNotNull($storeSourceId);
        $this->assertEquals(1, $storeOptions->json('options.0.available_quantity'));

        // 7. Stage 3 Revert: Store -> Pending Supplier Arrival (1 unit)
        $revertToPendingResp = $this->actingAs($this->adminUser)->postJson('/api/v1/workflow/revert', [
            'department' => 'store',
            'bom_item_id' => $this->bomItem->id,
            'side' => 'LH',
            'source_type' => 'receipt_item',
            'source_id' => $storeSourceId,
            'quantity' => 1,
            'reason' => 'Return to vendor, physical defect discovered in Store',
        ]);
        $revertToPendingResp->assertStatus(200);
        $revertToPendingResp->assertJsonFragment([
            'success' => true,
            'from_department' => 'STORE',
            'to_department' => 'PENDING_ARRIVAL',
        ]);

        // 8. Global Store Revert should now be empty for that item
        $globalStoreRevertAfter = $this->actingAs($this->adminUser)->getJson("/api/v1/workflow/revert-items?department=store&project_id={$this->project->id}&side=LH");
        $globalStoreRevertAfter->assertStatus(200);
        $this->assertEmpty($globalStoreRevertAfter->json('items'));
    }

    public function test_revert_department_data_isolation_and_authorization()
    {
        // 1. Create a dedicated Rework User
        \Spatie\Permission\Models\Role::firstOrCreate(['name' => 'REWORK', 'guard_name' => 'web']);
        \Spatie\Permission\Models\Role::firstOrCreate(['name' => 'REWORK', 'guard_name' => 'sanctum']);

        $reworkUser = User::firstOrCreate(
            ['email' => 'rework-operator@sparetrack.internal'],
            [
                'name' => 'Rework Operator',
                'password' => bcrypt('password123'),
            ]
        );
        $reworkUser->syncRoles(['REWORK']);

        // 2. Rework user attempts to query QC revert items -> must be rejected with 403
        $unauthorizedResp = $this->actingAs($reworkUser)->getJson('/api/v1/workflow/revert-items?department=qc');
        $unauthorizedResp->assertStatus(403);
        $unauthorizedResp->assertJsonFragment([
            'success' => false,
        ]);

        // 3. Rework user queries Rework revert items -> allowed with 200
        $authorizedResp = $this->actingAs($reworkUser)->getJson('/api/v1/workflow/revert-items?department=rework');
        $authorizedResp->assertStatus(200);

        // 4. Admin user can query both departments, each containing strictly isolated records
        $rec = $this->createStoreReceipt('LH', 2);
        $rec->update(['status' => 'qc_received']);

        $insp = QcInspection::create([
            'bom_item_id' => $this->bomItem->id,
            'receipt_item_id' => $rec->id,
            'side' => 'LH',
            'inspected_quantity' => 2,
            'approved_quantity' => 0,
            'rework_quantity' => 2,
            'result' => 'rework',
            'inspected_by' => $this->adminUser->id,
            'inspection_date' => now(),
        ]);
        $rec->update(['status' => 'qc_rework']);

        $reworkList = $this->actingAs($this->adminUser)->getJson("/api/v1/workflow/revert-items?department=rework&project_id={$this->project->id}&side=LH");
        $reworkList->assertStatus(200);
        $reworkItems = $reworkList->json('items');
        $this->assertNotEmpty($reworkItems);
        foreach ($reworkItems as $item) {
            $this->assertEquals('REWORK', $item['from_department']);
            $this->assertEquals('QC', $item['to_department']);
        }
    }
}


