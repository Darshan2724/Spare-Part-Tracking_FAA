<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\Project;
use App\Models\BomItem;
use App\Models\Receipt;
use App\Models\ReceiptItem;
use App\Models\EcnRequirement;
use App\Models\EcnReceiptItem;
use App\Models\EcnWorkflowRecord;
use App\Models\PurchaseQueueItem;
use App\Services\HierarchyService;
use App\Services\EcnWorkflowService;
use Illuminate\Support\Facades\DB;

class EcnRejectIdempotencyAndRevertContractTest extends TestCase
{
    protected User $admin;
    protected Project $project;
    protected EcnWorkflowService $ecnWorkflowService;
    protected HierarchyService $hierarchyService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::firstOrCreate(
            ['email' => 'admin_test@sparetrack.com'],
            ['name' => 'Admin Test', 'password' => bcrypt('password'), 'role' => 'ADMIN']
        );

        $this->project = Project::create([
            'name' => 'TEST-REJECT-REVERT-' . uniqid(),
            'project_code' => 'TEST-RR-' . uniqid(),
            'status' => 'active',
        ]);

        $this->ecnWorkflowService = app(EcnWorkflowService::class);
        $this->hierarchyService = app(HierarchyService::class);
    }

    public function test_ecn_qc_reject_transitions_state_and_removes_from_inspection_hierarchy(): void
    {
        // 1. Create ECN requirement & receive into QC
        $ecnReq = EcnRequirement::create([
            'project_id' => $this->project->id,
            'ecn_number' => 'ECN-REJ-01',
            'part_no' => 'ECN-PART-REJ',
            'jig_no' => 'JIG-01',
            'unit_no' => '01',
            'side' => 'LH',
            'side_display' => 'LH',
            'side_family' => 'LEFT',
            'required_qty' => 2,
            'received_qty' => 2,
            'current_state' => 'STORE',
        ]);

        $receiptItem = EcnReceiptItem::create([
            'project_id' => $this->project->id,
            'ecn_requirement_id' => $ecnReq->id,
            'ecn_number' => 'ECN-REJ-01',
            'side' => 'LH',
            'side_display' => 'LH',
            'received_quantity' => 2,
            'status' => 'received',
            'remarks' => 'Store intake',
        ]);

        // Move to QC Arrival
        $this->ecnWorkflowService->qcReceive($receiptItem->id, 2, 'Physical arrival at QC');
        $ecnReq->refresh();
        $this->assertEquals('QC', $ecnReq->current_state);

        // Verify it is visible in QC inspection hierarchy
        $hBefore = $this->hierarchyService->getDepartmentHierarchy('qc', $this->project->id, ['stage' => 'inspection']);
        $this->assertEquals(2, $hBefore['project']['ecn_parts']);

        // 2. Reject the item via API
        $response = $this->actingAs($this->admin, 'sanctum')->postJson('/api/v1/ecn/qc/inspect', [
            'ecn_receipt_item_id' => $receiptItem->id,
            'ecn_requirement_id' => $ecnReq->id,
            'result' => 'rejected',
            'approved_quantity' => 0,
            'rejected_quantity' => 2,
            'rework_quantity' => 0,
            'rejection_reason' => 'Defective welding',
        ]);

        $response->assertStatus(200);
        $response->assertJson(['success' => true]);

        // 3. Verify PostgreSQL State
        $ecnReq->refresh();
        $this->assertEquals('PENDING', $ecnReq->current_state);

        $receiptItem->refresh();
        $this->assertEquals('qc_rejected', $receiptItem->status);

        // Verify Purchase Queue entry created exactly once
        $purchaseCount = PurchaseQueueItem::where('project_id', $this->project->id)
            ->where('standard_part_no', 'ECN-PART-REJ')
            ->where('status', 'pending_purchase')
            ->count();
        $this->assertEquals(1, $purchaseCount);

        $purchaseItem = PurchaseQueueItem::where('project_id', $this->project->id)
            ->where('standard_part_no', 'ECN-PART-REJ')
            ->first();
        $this->assertEquals(2, $purchaseItem->rejected_quantity);

        // 4. Verify Hierarchy excludes the rejected part from active QC Inspection
        $hAfter = $this->hierarchyService->getDepartmentHierarchy('qc', $this->project->id, ['stage' => 'inspection']);
        $this->assertEquals(0, $hAfter['project']['ecn_parts']);
        $this->assertNull($hAfter['project']['ecn_number_display']);
        $this->assertEmpty($hAfter['jigs']);
    }

    public function test_duplicate_or_repeated_reject_is_blocked_and_does_not_create_duplicate_purchase(): void
    {
        $ecnReq = EcnRequirement::create([
            'project_id' => $this->project->id,
            'ecn_number' => 'ECN-DUP-01',
            'part_no' => 'ECN-PART-DUP',
            'jig_no' => 'JIG-01',
            'unit_no' => '01',
            'side' => 'LH',
            'side_display' => 'LH',
            'side_family' => 'LEFT',
            'required_qty' => 1,
            'received_qty' => 1,
            'current_state' => 'STORE',
        ]);

        $receiptItem = EcnReceiptItem::create([
            'project_id' => $this->project->id,
            'ecn_requirement_id' => $ecnReq->id,
            'ecn_number' => 'ECN-DUP-01',
            'side' => 'LH',
            'side_display' => 'LH',
            'received_quantity' => 1,
            'status' => 'received',
        ]);

        $this->ecnWorkflowService->qcReceive($receiptItem->id, 1);

        // First Reject (Succeeds)
        $res1 = $this->actingAs($this->admin, 'sanctum')->postJson('/api/v1/ecn/qc/inspect', [
            'ecn_receipt_item_id' => $receiptItem->id,
            'result' => 'rejected',
            'rejected_quantity' => 1,
            'rejection_reason' => 'Crack detected',
        ]);
        $res1->assertStatus(200);

        // Immediate Second / Duplicate Reject (Must Fail cleanly without duplicating Purchase)
        $res2 = $this->actingAs($this->admin, 'sanctum')->postJson('/api/v1/ecn/qc/inspect', [
            'ecn_receipt_item_id' => $receiptItem->id,
            'result' => 'rejected',
            'rejected_quantity' => 1,
            'rejection_reason' => 'Crack detected',
        ]);
        $this->assertTrue(in_array($res2->status(), [400, 422, 500]));

        // Purchase queue must contain strictly 1 item with quantity 1
        $purchases = PurchaseQueueItem::where('project_id', $this->project->id)
            ->where('standard_part_no', 'ECN-PART-DUP')
            ->get();
        $this->assertCount(1, $purchases);
        $this->assertEquals(1, $purchases->first()->rejected_quantity);
    }

    public function test_ecn_revert_accepts_all_canonical_identifiers_across_departments(): void
    {
        // 1. QC -> Store Revert
        $ecnReq1 = EcnRequirement::create([
            'project_id' => $this->project->id,
            'ecn_number' => 'ECN-REV-01',
            'part_no' => 'ECN-REV-QC',
            'jig_no' => 'JIG-01',
            'unit_no' => '01',
            'side' => 'LH',
            'side_display' => 'LH',
            'side_family' => 'LEFT',
            'required_qty' => 1,
            'received_qty' => 1,
            'current_state' => 'STORE',
        ]);

        $ri1 = EcnReceiptItem::create([
            'project_id' => $this->project->id,
            'ecn_requirement_id' => $ecnReq1->id,
            'ecn_number' => 'ECN-REV-01',
            'side' => 'LH',
            'side_display' => 'LH',
            'received_quantity' => 1,
            'status' => 'received',
        ]);

        $this->ecnWorkflowService->qcReceive($ri1->id, 1);

        // Test calling /api/v1/ecn/revert with source_id (as mobile sends)
        $qcRevRes = $this->actingAs($this->admin, 'sanctum')->postJson('/api/v1/ecn/revert', [
            'department' => 'qc',
            'source_id' => $ri1->id,
            'source_type' => 'ecn_receipt_item',
            'ecn_requirement_id' => $ecnReq1->id,
            'quantity' => 1,
            'reason' => 'Testing mobile revert payload',
        ]);
        $qcRevRes->assertStatus(200);
        $ecnReq1->refresh();
        $this->assertEquals('STORE', $ecnReq1->current_state);

        // 2. Rework -> QC Revert
        $ri1->update(['status' => 'qc_received']);
        $this->ecnWorkflowService->qcInspect($ri1->id, 0, 'ASSEMBLY', 0, 1, 'Minor scratch');
        $ecnReq1->refresh();
        $this->assertEquals('REWORK', $ecnReq1->current_state);

        $rwRecord = EcnWorkflowRecord::where('ecn_requirement_id', $ecnReq1->id)
            ->where('department', 'REWORK')
            ->where('status', 'in_progress')
            ->first();
        $this->assertNotNull($rwRecord);

        // Test calling /api/v1/ecn/revert for rework with record_id
        $rwRevRes = $this->actingAs($this->admin, 'sanctum')->postJson('/api/v1/ecn/revert', [
            'department' => 'rework',
            'record_id' => $rwRecord->id,
            'quantity' => 1,
            'reason' => 'Rework cancel',
        ]);
        $rwRevRes->assertStatus(200);
        $ecnReq1->refresh();
        $this->assertEquals('QC', $ecnReq1->current_state);

        // 3. Generic /workflow/revert endpoint forwarding ECN
        $ri1->update(['status' => 'qc_received']);
        $this->ecnWorkflowService->qcInspect($ri1->id, 1, 'PAINT', 0, 0);
        $ecnReq1->refresh();
        $this->assertEquals('PAINT', $ecnReq1->current_state);

        $paintRecord = EcnWorkflowRecord::where('ecn_requirement_id', $ecnReq1->id)
            ->where('department', 'PAINT')
            ->where('status', 'in_progress')
            ->first();

        $genRevRes = $this->actingAs($this->admin, 'sanctum')->postJson('/api/v1/workflow/revert', [
            'department' => 'paint',
            'bom_item_id' => 'ecn_' . $ecnReq1->id,
            'source_id' => $paintRecord->id,
            'source_type' => 'ecn_workflow_record',
            'classification' => 'ECN',
            'quantity' => 1,
            'reason' => 'Paint revert via generic endpoint',
        ]);
        $genRevRes->assertStatus(200);
        $ecnReq1->refresh();
        $this->assertEquals('QC', $ecnReq1->current_state);
    }
}
