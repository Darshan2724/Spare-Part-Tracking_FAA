<?php

namespace Tests\Feature;

use App\Models\EcnRequirement;
use App\Models\EcnReceiptItem;
use App\Models\EcnWorkflowRecord;
use App\Models\EcnWorkflowEvent;
use App\Models\Project;
use App\Models\User;
use App\Services\EcnWorkflowService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class EcnWorkflowTest extends TestCase
{
    use DatabaseTransactions;
    protected function getAdminUser(): User
    {
        $user = User::where('email', 'admin@sparetrack.internal')->first();
        if (!$user) {
            $user = User::first();
        }
        if (!$user) {
            $user = User::create([
                'name' => 'Admin User',
                'email' => 'admin@sparetrack.internal',
                'password' => bcrypt('password'),
            ]);
            $user->assignRole('ADMIN');
        }
        return $user;
    }

    protected function setupEcnRequirement(?string $projectCode = null, string $ecnNumber = 'ECN-1', string $side = 'LA', int $qty = 5): EcnRequirement
    {
        $code = $projectCode ?: ('TEST-' . uniqid());
        $project = Project::firstOrCreate(
            ['project_code' => $code],
            ['name' => "Project {$code}", 'status' => 'active']
        );

        return EcnRequirement::firstOrCreate(
            [
                'project_id' => $project->id,
                'ecn_number' => $ecnNumber,
                'jig_no' => 'LIMOFD20',
                'unit_no' => '07',
                'part_no' => '05',
                'side' => $side,
            ],
            [
                'side_display' => 'LH',
                'side_family' => 'LEFT',
                'required_qty' => $qty,
                'received_qty' => 0,
                'current_state' => 'PENDING',
            ]
        );
    }

    public function test_ecn_store_intake_and_send_to_qc()
    {
        $user = $this->getAdminUser();
        $this->actingAs($user, 'sanctum');

        $req = $this->setupEcnRequirement('FA-273', 'ECN-1', 'LA', 5);

        // 1. Store Intake
        $resStore = $this->postJson('/api/v1/ecn/store/receive', [
            'ecn_requirement_id' => $req->id,
            'quantity' => 5,
            'remarks' => 'Initial ECN delivery',
        ]);
        $resStore->assertStatus(200);
        $this->assertTrue($resStore->json('success'));

        $req->refresh();
        $this->assertEquals(5, $req->received_qty);
        $this->assertEquals('STORE', $req->current_state);

        $receiptItem = EcnReceiptItem::where('ecn_requirement_id', $req->id)->first();
        $this->assertNotNull($receiptItem);
        $this->assertEquals('received', $receiptItem->status);

        // 2. Send to QC
        $resQc = $this->postJson('/api/v1/ecn/store/send-to-qc', [
            'ecn_receipt_item_id' => $receiptItem->id,
            'quantity' => 5,
            'remarks' => 'Transfer to inspection',
        ]);
        $resQc->assertStatus(200);

        $receiptItem->refresh();
        $this->assertEquals('sent_to_qc', $receiptItem->status);

        $req->refresh();
        $this->assertEquals('QC', $req->current_state);

        // Verify audit event
        $this->assertDatabaseHas('ecn_workflow_events', [
            'ecn_requirement_id' => $req->id,
            'event_type' => 'ECN_SENT_TO_QC',
            'quantity' => 5,
        ]);
    }

    public function test_ecn_qc_inspect_split_and_rework_loop()
    {
        $user = $this->getAdminUser();
        $this->actingAs($user, 'sanctum');

        $req = $this->setupEcnRequirement('FA-273', 'ECN-3', 'RA', 10);
        $workflowService = new EcnWorkflowService();

        // 1. Store Receive & send to QC
        $storeRes = $workflowService->receiveStore($req->id, 10, 'Batch intake', $user->id);
        $receiptItem = $storeRes['receipt_item'];
        $workflowService->sendToQc($receiptItem->id, 10, 'To QC', $user->id);

        // 2. QC Arrival
        $arrivalRes = $this->postJson('/api/v1/ecn/qc/receive', [
            'ecn_receipt_item_id' => $receiptItem->id,
            'quantity' => 10,
        ]);
        $arrivalRes->assertStatus(200);

        // 3. QC Inspect with Split: 6 approved (ASSEMBLY), 3 rework, 1 rejected
        $inspRes = $this->postJson('/api/v1/ecn/qc/inspect', [
            'ecn_receipt_item_id' => $receiptItem->id,
            'approved_quantity' => 6,
            'destination' => 'ASSEMBLY',
            'rework_quantity' => 3,
            'rejected_quantity' => 1,
            'remarks' => '3 parts need deburring, 1 scrap',
        ]);
        $inspRes->assertStatus(200);

        // Verify QC inspection records
        $this->assertDatabaseHas('ecn_workflow_records', [
            'ecn_requirement_id' => $req->id,
            'department' => 'QC',
            'action' => 'qc_inspected',
            'approved_quantity' => 6,
            'rework_quantity' => 3,
            'rejected_quantity' => 1,
        ]);

        // Verify Assembly queue record for approved quantity
        $this->assertDatabaseHas('ecn_workflow_records', [
            'ecn_requirement_id' => $req->id,
            'department' => 'ASSEMBLY',
            'action' => 'assembly_queued',
            'quantity' => 6,
            'status' => 'in_progress',
        ]);

        // Verify Rework queue record for rework quantity
        $reworkRecord = EcnWorkflowRecord::where('ecn_requirement_id', $req->id)
            ->where('department', 'REWORK')
            ->where('status', 'in_progress')
            ->first();
        $this->assertNotNull($reworkRecord);
        $this->assertEquals(3, $reworkRecord->quantity);

        // 4. Complete Rework -> return to QC
        $rewCompRes = $this->postJson('/api/v1/ecn/rework/complete', [
            'workflow_record_id' => $reworkRecord->id,
            'quantity' => 3,
            'remarks' => 'Deburring done',
        ]);
        $rewCompRes->assertStatus(200);

        $reworkRecord->refresh();
        $this->assertEquals('completed', $reworkRecord->status);
        $this->assertEquals('rework_completed', $reworkRecord->action);

        $req->refresh();
        $this->assertEquals('QC', $req->current_state);
    }

    public function test_ecn_paint_to_assembly_and_completion()
    {
        $user = $this->getAdminUser();
        $this->actingAs($user, 'sanctum');

        $req = $this->setupEcnRequirement('FA-273', 'ECN-17', 'L', 4);
        $workflowService = new EcnWorkflowService();

        // 1. Store Receive & send to QC
        $storeRes = $workflowService->receiveStore($req->id, 4, null, $user->id);
        $receiptItem = $storeRes['receipt_item'];
        $workflowService->sendToQc($receiptItem->id, 4, null, $user->id);
        $workflowService->qcReceive($receiptItem->id, 4, null, $user->id);

        // 2. QC Inspect -> destination: PAINT
        $workflowService->qcInspect($receiptItem->id, 4, 'PAINT', 0, 0, 'Pass to paint', $user->id);

        $paintRecord = EcnWorkflowRecord::where('ecn_requirement_id', $req->id)
            ->where('department', 'PAINT')
            ->where('status', 'in_progress')
            ->first();
        $this->assertNotNull($paintRecord);
        $this->assertEquals(4, $paintRecord->quantity);

        // 3. Complete Paint -> moves to Assembly
        $paintRes = $this->postJson('/api/v1/ecn/paint/complete', [
            'workflow_record_id' => $paintRecord->id,
            'quantity' => 4,
            'remarks' => 'Powder coat applied',
        ]);
        $paintRes->assertStatus(200);

        $asmRecord = EcnWorkflowRecord::where('ecn_requirement_id', $req->id)
            ->where('department', 'ASSEMBLY')
            ->where('status', 'in_progress')
            ->first();
        $this->assertNotNull($asmRecord);

        // 4. Complete Assembly (final stage)
        $asmRes = $this->postJson('/api/v1/ecn/assembly/complete', [
            'workflow_record_id' => $asmRecord->id,
            'quantity' => 4,
            'remarks' => 'Fitted onto unit 07',
        ]);
        $asmRes->assertStatus(200);

        $req->refresh();
        $this->assertEquals('ASSEMBLY_COMPLETED', $req->current_state);

        $this->assertDatabaseHas('ecn_workflow_events', [
            'ecn_requirement_id' => $req->id,
            'event_type' => 'ECN_ASSEMBLY_COMPLETED',
            'quantity' => 4,
        ]);
    }

    public function test_ecn_revert_preserves_classification_and_lineage()
    {
        $user = $this->getAdminUser();
        $this->actingAs($user, 'sanctum');

        $req = $this->setupEcnRequirement('FA-273', 'ECN-40', 'AR', 2);
        $workflowService = new EcnWorkflowService();

        // 1. Store Intake
        $storeRes = $workflowService->receiveStore($req->id, 2, 'Initial', $user->id);
        $receiptItem = $storeRes['receipt_item'];

        // 2. Revert from Store -> PENDING_ARRIVAL
        $revertRes = $this->postJson('/api/v1/ecn/revert', [
            'department' => 'store',
            'record_id' => $receiptItem->id,
            'quantity' => 2,
            'remarks' => 'Supplier sent wrong revision',
        ]);
        $revertRes->assertStatus(200);
        $this->assertEquals('PENDING_ARRIVAL', $revertRes->json('target_department'));

        $req->refresh();
        $this->assertEquals(0, $req->received_qty);
        $this->assertEquals('PENDING', $req->current_state);
        $this->assertEquals('ECN-40', $req->ecn_number);
        $this->assertEquals('AR', $req->side);
    }
}
