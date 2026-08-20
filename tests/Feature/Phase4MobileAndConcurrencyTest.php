<?php

namespace Tests\Feature;

use App\Models\BomItem;
use App\Models\BomRequirement;
use App\Models\Project;
use App\Models\Receipt;
use App\Models\ReceiptItem;
use App\Models\User;
use App\Models\WorkflowEvent;
use App\Services\HierarchyService;
use App\Services\QuantityCalculationService;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class Phase4MobileAndConcurrencyTest extends TestCase
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

    public function test_partial_quantity_receipt_preserves_pending_count_and_status()
    {
        $user = $this->getAuthUser('STORE');
        $this->actingAs($user, 'sanctum');

        $project = Project::create([
            'project_code' => 'TEST-P4-PARTIAL-' . uniqid(),
            'name' => 'Phase 4 Partial Test Project',
            'status' => 'active',
        ]);

        $bomItem = BomItem::create([
            'project_id' => $project->id,
            'standard_part_no' => 'PART-P4-001',
            'item_no' => '10',
            'jig_no' => 'JIG 01',
            'unit_no' => 'Unit 01',
        ]);

        BomRequirement::create([
            'bom_item_id' => $bomItem->id,
            'side' => 'RH',
            'required_quantity' => 6,
        ]);

        // 1. Partial receipt of 4 out of 6
        $response = $this->postJson('/api/v1/store/receipts', [
            'project_id' => $project->id,
            'delivery_note_number' => 'DN-PARTIAL-001',
            'items' => [
                [
                    'bom_item_id' => $bomItem->id,
                    'side' => 'RH',
                    'received_quantity' => 4,
                ]
            ]
        ]);

        $response->assertStatus(200)
            ->assertJson(['success' => true]);

        // Verify Database State
        $receiptItem = ReceiptItem::where('bom_item_id', $bomItem->id)->where('side', 'RH')->first();
        $this->assertNotNull($receiptItem);
        $this->assertEquals(4, $receiptItem->received_quantity);

        // Verify Pending calculation
        $metrics = $this->quantityService->calculateProjectMetrics($project);
        $this->assertEquals(6, $metrics['required_qty']);
        $this->assertEquals(4, $metrics['received_qty']);
        $this->assertEquals(2, $metrics['pending_qty']);

        // Verify /store/pending API returns item with pending_qty = 2 and status = partially_received
        $pendingRes = $this->getJson('/api/v1/store/pending?project_id=' . $project->id);
        $pendingRes->assertStatus(200);
        $pendingData = $pendingRes->json('data');
        $this->assertCount(1, $pendingData);
        $this->assertEquals(2, $pendingData[0]['pending_qty']);
        $this->assertEquals(4, $pendingData[0]['received_qty']);
        $this->assertEquals('partially_received', $pendingData[0]['status']);

        // 2. Receive remaining 2
        $response2 = $this->postJson('/api/v1/store/receipts', [
            'project_id' => $project->id,
            'delivery_note_number' => 'DN-PARTIAL-002',
            'items' => [
                [
                    'bom_item_id' => $bomItem->id,
                    'side' => 'RH',
                    'received_quantity' => 2,
                ]
            ]
        ]);
        $response2->assertStatus(200);

        // Verify Total Received = 6, Pending = 0
        $metrics2 = $this->quantityService->calculateProjectMetrics($project->fresh());
        $this->assertEquals(6, $metrics2['required_qty']);
        $this->assertEquals(6, $metrics2['received_qty']);
        $this->assertEquals(0, $metrics2['pending_qty']);

        // Pending list should now be empty
        $pendingRes2 = $this->getJson('/api/v1/store/pending?project_id=' . $project->id . '&only_pending=1');
        $this->assertCount(0, $pendingRes2->json('data'));
    }

    public function test_bulk_qc_arrival_acceptance_processes_all_items_atomically()
    {
        $qcUser = $this->getAuthUser('QC');
        $this->actingAs($qcUser, 'sanctum');

        $project = Project::create([
            'project_code' => 'TEST-P4-BULKQC-' . uniqid(),
            'name' => 'Phase 4 Bulk QC Project',
            'status' => 'active',
        ]);

        $receipt = Receipt::create([
            'project_id' => $project->id,
            'received_by' => $qcUser->id,
        ]);

        $itemIds = [];
        for ($i = 1; $i <= 3; $i++) {
            $b = BomItem::create([
                'project_id' => $project->id,
                'standard_part_no' => "BULK-QC-PART-{$i}",
                'jig_no' => 'JIG 01',
                'unit_no' => 'Unit 01',
            ]);
            BomRequirement::create([
                'bom_item_id' => $b->id,
                'side' => 'RH',
                'required_quantity' => 10,
            ]);
            $rItem = ReceiptItem::create([
                'receipt_id' => $receipt->id,
                'bom_item_id' => $b->id,
                'side' => 'RH',
                'received_quantity' => 10,
                'status' => 'received',
            ]);
            $itemIds[] = $rItem->id;
        }

        // Execute bulk physical arrival
        $res = $this->postJson('/api/v1/qc/bulk-receive', [
            'receipt_item_ids' => $itemIds,
            'side' => 'RH',
        ]);

        $res->assertStatus(200)
            ->assertJson([
                'success' => true,
                'processed_count' => 3,
            ]);

        // Verify all 3 are qc_received
        foreach ($itemIds as $id) {
            $updated = ReceiptItem::find($id);
            $this->assertEquals('qc_received', $updated->status);
            $this->assertNotNull($updated->qc_received_at);
        }

        // Test idempotency: re-running when already received returns friendly notice
        $resReRun = $this->postJson('/api/v1/qc/bulk-receive', [
            'receipt_item_ids' => $itemIds,
            'side' => 'RH',
        ]);
        $resReRun->assertStatus(200)
            ->assertJson([
                'success' => true,
                'already_processed' => 3,
            ]);
    }

    public function test_bulk_qc_inspection_with_destination_routing()
    {
        $qcUser = $this->getAuthUser('QC');
        $this->actingAs($qcUser, 'sanctum');

        $project = Project::create([
            'project_code' => 'TEST-P4-INSPECT-' . uniqid(),
            'name' => 'Phase 4 Bulk Inspect Project',
            'status' => 'active',
        ]);

        $receipt = Receipt::create(['project_id' => $project->id, 'received_by' => $qcUser->id]);

        $itemIds = [];
        for ($i = 1; $i <= 2; $i++) {
            $b = BomItem::create([
                'project_id' => $project->id,
                'standard_part_no' => "INSPECT-PART-{$i}",
                'jig_no' => 'JIG 01',
                'unit_no' => 'Unit 01',
            ]);
            BomRequirement::create(['bom_item_id' => $b->id, 'side' => 'RH', 'required_quantity' => 5]);
            $rItem = ReceiptItem::create([
                'receipt_id' => $receipt->id,
                'bom_item_id' => $b->id,
                'side' => 'RH',
                'received_quantity' => 5,
                'status' => 'qc_received',
            ]);
            $itemIds[] = $rItem->id;
        }

        // Bulk Inspect -> Approved to PAINT
        $res = $this->postJson('/api/v1/qc/bulk-inspect', [
            'receipt_item_ids' => $itemIds,
            'side' => 'RH',
            'result' => 'approved',
            'destination' => 'PAINT',
            'remarks' => 'Bulk approval for painting',
        ]);

        $res->assertStatus(200)->assertJson(['success' => true]);

        foreach ($itemIds as $id) {
            $item = ReceiptItem::find($id);
            $this->assertEquals('qc_approved', $item->status);
        }
    }

    public function test_side_isolation_in_partial_receipts()
    {
        $user = $this->getAuthUser('STORE');
        $this->actingAs($user, 'sanctum');

        $project = Project::create([
            'project_code' => 'TEST-P4-SIDES-' . uniqid(),
            'name' => 'Phase 4 Side Isolation Project',
            'status' => 'active',
        ]);

        $bomItem = BomItem::create([
            'project_id' => $project->id,
            'standard_part_no' => 'SIDE-ISOLATION-001',
            'jig_no' => 'JIG 01',
            'unit_no' => 'Unit 01',
        ]);

        // RH = 8, LH = 4
        BomRequirement::create(['bom_item_id' => $bomItem->id, 'side' => 'RH', 'required_quantity' => 8]);
        BomRequirement::create(['bom_item_id' => $bomItem->id, 'side' => 'LH', 'required_quantity' => 4]);

        // Receive 5 on RH only
        $this->postJson('/api/v1/store/receipts', [
            'project_id' => $project->id,
            'items' => [
                ['bom_item_id' => $bomItem->id, 'side' => 'RH', 'received_quantity' => 5]
            ]
        ])->assertStatus(200);

        // Check RH: required = 8, received = 5, pending = 3
        // Check LH: required = 4, received = 0, pending = 4
        $hierarchy = $this->hierarchyService->getDepartmentHierarchy('store', $project->id);
        $part = $hierarchy['jigs'][0]['units'][0]['parts'][0];

        $this->assertEquals(8, $part['side_stats']['RH']['required']);
        $this->assertEquals(5, $part['side_stats']['RH']['received']);
        $this->assertEquals(3, $part['side_stats']['RH']['pending']);

        $this->assertEquals(4, $part['side_stats']['LH']['required']);
        $this->assertEquals(0, $part['side_stats']['LH']['received']);
        $this->assertEquals(4, $part['side_stats']['LH']['pending']);
    }
}
