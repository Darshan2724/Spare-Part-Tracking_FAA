<?php

namespace Tests\Feature;

use App\Models\BomItem;
use App\Models\BomRequirement;
use App\Models\Project;
use App\Models\PurchaseQueueItem;
use App\Models\QcInspection;
use App\Models\Receipt;
use App\Models\ReceiptItem;
use App\Models\User;
use App\Models\WorkflowEvent;
use App\Services\QuantityCalculationService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class PurchaseRevertWorkflowTest extends TestCase
{
    use DatabaseTransactions;

    protected User $adminUser;
    protected User $purchaseUser;
    protected User $unauthorizedUser;
    protected Project $project;
    protected BomItem $bomItem;
    protected QuantityCalculationService $calcService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->calcService = app(QuantityCalculationService::class);

        \Spatie\Permission\Models\Role::firstOrCreate(['name' => 'ADMIN', 'guard_name' => 'web']);
        \Spatie\Permission\Models\Role::firstOrCreate(['name' => 'ADMIN', 'guard_name' => 'sanctum']);
        \Spatie\Permission\Models\Role::firstOrCreate(['name' => 'PURCHASE', 'guard_name' => 'web']);
        \Spatie\Permission\Models\Role::firstOrCreate(['name' => 'PURCHASE', 'guard_name' => 'sanctum']);
        \Spatie\Permission\Models\Role::firstOrCreate(['name' => 'STORE', 'guard_name' => 'web']);
        \Spatie\Permission\Models\Role::firstOrCreate(['name' => 'STORE', 'guard_name' => 'sanctum']);

        $this->adminUser = User::firstOrCreate(
            ['email' => 'admin-test-purchaserevert@sparetrack.internal'],
            ['name' => 'Admin User', 'password' => bcrypt('password123')]
        );
        $this->adminUser->syncRoles(['ADMIN']);

        $this->purchaseUser = User::firstOrCreate(
            ['email' => 'purchase-test-purchaserevert@sparetrack.internal'],
            ['name' => 'Purchase User', 'password' => bcrypt('password123')]
        );
        $this->purchaseUser->syncRoles(['PURCHASE']);

        $this->unauthorizedUser = User::firstOrCreate(
            ['email' => 'store-test-purchaserevert@sparetrack.internal'],
            ['name' => 'Store Only User', 'password' => bcrypt('password123')]
        );
        $this->unauthorizedUser->syncRoles(['STORE']);

        $this->project = Project::create([
            'project_code' => 'FA-PREV-01',
            'name' => 'Purchase Revert Test Project',
            'customer_name' => 'Test Customer',
            'status' => 'active',
        ]);

        $this->bomItem = BomItem::create([
            'project_id' => $this->project->id,
            'jig_no' => 'JIG-TEST-10',
            'unit_no' => '17',
            'item_no' => '050',
            'standard_part_no' => '050#TEST-REV',
            'part_description' => 'Test Bracket For Purchase Revert',
            'part_type' => 'MFG',
        ]);

        BomRequirement::create([
            'bom_item_id' => $this->bomItem->id,
            'side' => 'LH',
            'required_quantity' => 10,
        ]);
    }

    /**
     * Helper to set up an item rejected from QC into Purchase queue.
     */
    protected function setupQcRejectedItem(int $qty = 4): array
    {
        $receipt = Receipt::create([
            'project_id' => $this->project->id,
            'receipt_number' => 'REC-TEST-REV-' . uniqid(),
            'delivery_note_number' => 'DN-TEST-001',
            'received_by' => $this->adminUser->id,
            'received_at' => now(),
            'status' => 'received',
        ]);

        $receiptItem = ReceiptItem::create([
            'receipt_id' => $receipt->id,
            'bom_item_id' => $this->bomItem->id,
            'side' => 'LH',
            'received_quantity' => $qty,
            'status' => 'qc_rejected',
            'qc_received_at' => now(),
        ]);

        $qcInspection = QcInspection::create([
            'bom_item_id' => $this->bomItem->id,
            'receipt_item_id' => $receiptItem->id,
            'side' => 'LH',
            'inspected_quantity' => $qty,
            'approved_quantity' => 0,
            'rejected_quantity' => $qty,
            'rework_quantity' => 0,
            'result' => 'rejected',
            'rejection_reason' => 'Defective Surface Finish',
            'inspected_by' => $this->adminUser->id,
            'inspection_date' => now(),
        ]);

        $purchaseItem = PurchaseQueueItem::create([
            'bom_item_id' => $this->bomItem->id,
            'qc_inspection_id' => $qcInspection->id,
            'project_id' => $this->project->id,
            'standard_part_no' => $this->bomItem->standard_part_no,
            'side' => 'LH',
            'rejected_quantity' => $qty,
            'rejection_reason' => 'Defective Surface Finish',
            'rejected_by' => $this->adminUser->id,
            'rejected_at' => now(),
            'status' => 'pending_purchase',
        ]);

        return [$receiptItem, $qcInspection, $purchaseItem];
    }

    public function test_purchase_can_revert_qc_rejected_part_to_qc_arrival(): void
    {
        [$receiptItem, $qcInspection, $purchaseItem] = $this->setupQcRejectedItem(4);

        $response = $this->actingAs($this->purchaseUser)
            ->postJson('/api/v1/purchase/revert-rejected', [
                'purchase_queue_item_id' => $purchaseItem->id,
                'quantity' => 4,
                'reason' => 'Mistaken rejection by QC inspector',
            ]);

        $response->assertStatus(200);
        $response->assertJson([
            'success' => true,
            'from_department' => 'PURCHASE',
            'to_department' => 'QC_ARRIVAL',
            'reverted_quantity' => 4,
        ]);

        // Verify PurchaseQueueItem is updated
        $purchaseItem->refresh();
        $this->assertEquals(0, $purchaseItem->rejected_quantity);
        $this->assertEquals('closed', $purchaseItem->status);
        $this->assertStringContainsString('Reverted 4 pcs to QC Arrival', $purchaseItem->remarks);

        // Verify QcInspection is decremented
        $qcInspection->refresh();
        $this->assertEquals(0, $qcInspection->rejected_quantity);

        // Verify ReceiptItem is restored to 'sent_to_qc' (QC Arrival)
        $receiptItem->refresh();
        $this->assertEquals('sent_to_qc', $receiptItem->status);
        $this->assertNull($receiptItem->qc_received_at);

        // Verify WorkflowEvent audit entry was logged
        $event = WorkflowEvent::where('bom_item_id', $this->bomItem->id)
            ->where('event_type', 'purchase_reverted_to_qc')
            ->first();
        $this->assertNotNull($event);
        $this->assertEquals('qc_rejected', $event->previous_state);
        $this->assertEquals('sent_to_qc', $event->new_state);
        $this->assertEquals(4, $event->quantity);
        $this->assertEquals($this->purchaseUser->id, $event->user_id);

        // Verify calculation conservation:
        $metrics = $this->calcService->calculateProjectMetrics($this->project, 'LH');
        $this->assertEquals(10, $metrics['total_required']);
        $this->assertEquals(4, $metrics['total_received']);
        $this->assertEquals(6, $metrics['pending_qty']);
        $this->assertEquals(0, $metrics['rejected_qty']); // QC Rejected is now 0!
        $this->assertEquals(4, $metrics['awaiting_qc']); // Restored to QC!
    }

    public function test_partial_quantity_reversal_from_purchase_to_qc_arrival(): void
    {
        [$receiptItem, $qcInspection, $purchaseItem] = $this->setupQcRejectedItem(5);

        // Revert only 2 pcs out of 5
        $response = $this->actingAs($this->purchaseUser)
            ->postJson('/api/v1/purchase/revert-rejected', [
                'purchase_queue_item_id' => $purchaseItem->id,
                'quantity' => 2,
                'reason' => 'Partial lot acceptable',
            ]);

        $response->assertStatus(200);

        // PurchaseQueueItem should retain 3 pcs in pending_purchase
        $purchaseItem->refresh();
        $this->assertEquals(3, $purchaseItem->rejected_quantity);
        $this->assertEquals('pending_purchase', $purchaseItem->status);

        // QcInspection rejected should be decremented to 3
        $qcInspection->refresh();
        $this->assertEquals(3, $qcInspection->rejected_quantity);

        // ReceiptItem split: original has 3 in qc_rejected, new slice has 2 in sent_to_qc
        $receiptItem->refresh();
        $this->assertEquals(3, $receiptItem->received_quantity);
        $this->assertEquals('qc_rejected', $receiptItem->status);

        $arrivedItem = ReceiptItem::where('bom_item_id', $this->bomItem->id)
            ->where('status', 'sent_to_qc')
            ->first();
        $this->assertNotNull($arrivedItem);
        $this->assertEquals(2, $arrivedItem->received_quantity);
        $this->assertNull($arrivedItem->qc_received_at);
    }

    public function test_idempotency_preventing_duplicate_reversal(): void
    {
        [$receiptItem, $qcInspection, $purchaseItem] = $this->setupQcRejectedItem(3);

        // First revert succeeds
        $res1 = $this->actingAs($this->purchaseUser)
            ->postJson('/api/v1/purchase/revert-rejected', [
                'purchase_queue_item_id' => $purchaseItem->id,
                'quantity' => 3,
            ]);
        $res1->assertStatus(200);

        // Second rapid tap / duplicate attempt must be rejected with 422
        $res2 = $this->actingAs($this->purchaseUser)
            ->postJson('/api/v1/purchase/revert-rejected', [
                'purchase_queue_item_id' => $purchaseItem->id,
                'quantity' => 3,
            ]);
        $res2->assertStatus(422);
        $res2->assertJsonFragment([
            'success' => false,
        ]);
    }

    public function test_cannot_revert_more_than_rejected_quantity(): void
    {
        [$receiptItem, $qcInspection, $purchaseItem] = $this->setupQcRejectedItem(3);

        $response = $this->actingAs($this->purchaseUser)
            ->postJson('/api/v1/purchase/revert-rejected', [
                'purchase_queue_item_id' => $purchaseItem->id,
                'quantity' => 10, // Exceeds 3
            ]);

        $response->assertStatus(422);
    }

    public function test_unauthorized_department_role_cannot_revert_purchase(): void
    {
        [$receiptItem, $qcInspection, $purchaseItem] = $this->setupQcRejectedItem(3);

        $response = $this->actingAs($this->unauthorizedUser)
            ->postJson('/api/v1/purchase/revert-rejected', [
                'purchase_queue_item_id' => $purchaseItem->id,
                'quantity' => 3,
            ]);

        $response->assertStatus(403);
    }

    public function test_get_revert_items_for_purchase_department(): void
    {
        [$receiptItem, $qcInspection, $purchaseItem] = $this->setupQcRejectedItem(4);

        $response = $this->actingAs($this->purchaseUser)
            ->getJson('/api/v1/workflow/revert-items?department=purchase&project_id=' . $this->project->id);

        $response->assertStatus(200);
        $response->assertJsonFragment([
            'success' => true,
            'department' => 'purchase',
        ]);

        $data = $response->json('items');
        $this->assertNotEmpty($data);
        $first = $data[0];
        $this->assertEquals($this->bomItem->standard_part_no, $first['standard_part_no']);
        $this->assertEquals('LH', $first['side']);
        $this->assertEquals(4, $first['available_quantity']);
        $this->assertEquals('PURCHASE', $first['from_department']);
        $this->assertEquals('QC_ARRIVAL', $first['to_department']);
        $this->assertEquals('JIG-TEST-10', $first['jig_name']);
        $this->assertEquals('17', $first['unit_no']);
    }
}
