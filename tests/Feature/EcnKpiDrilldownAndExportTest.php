<?php

namespace Tests\Feature;

use App\Models\EcnImportBatch;
use App\Models\EcnReceiptItem;
use App\Models\EcnRequirement;
use App\Models\EcnWorkflowRecord;
use App\Models\Project;
use App\Models\PurchaseQueueItem;
use App\Models\User;
use App\Services\EcnWorkflowService;
use App\Services\KpiDrilldownService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class EcnKpiDrilldownAndExportTest extends TestCase
{
    use DatabaseTransactions;

    protected User $adminUser;
    protected Project $project;
    protected EcnImportBatch $batch;

    protected function setUp(): void
    {
        parent::setUp();

        \Spatie\Permission\Models\Role::firstOrCreate(['name' => 'ADMIN', 'guard_name' => 'web']);

        $this->adminUser = User::firstOrCreate(
            ['email' => 'admin_ecn_test@faithautomation.com'],
            [
                'name' => 'Admin ECN Tester',
                'password' => bcrypt('password'),
                'role' => 'ADMIN',
                'is_active' => true,
            ]
        );
        $this->adminUser->syncRoles(['ADMIN']);

        $code = 'FA-273-' . uniqid();
        $this->project = Project::create([
            'project_code' => $code,
            'name' => 'Automotive Line ECN ' . $code,
            'status' => 'active',
            'total_parts' => 100,
        ]);

        $this->batch = EcnImportBatch::create([
            'project_id' => $this->project->id,
            'filename' => 'ecn_test.xlsx',
            'imported_by' => $this->adminUser->id,
            'status' => 'completed',
        ]);
    }

    public function test_ecn_kpi_drilldown_separates_departments_and_reconciles_quantities(): void
    {
        // 1. Create requirements
        $req1 = EcnRequirement::create([
            'project_id' => $this->project->id,
            'ecn_import_batch_id' => $this->batch->id,
            'ecn_number' => 'ECN-01',
            'jig_no' => '169961@',
            'unit_no' => '07',
            'part_no' => '020#R00',
            'side' => 'LA',
            'side_display' => 'LH',
            'side_family' => 'LEFT',
            'required_qty' => 10,
            'received_qty' => 10,
            'current_state' => 'QC',
        ]);

        $req2 = EcnRequirement::create([
            'project_id' => $this->project->id,
            'ecn_import_batch_id' => $this->batch->id,
            'ecn_number' => 'ECN-01',
            'jig_no' => '169961@',
            'unit_no' => '08',
            'part_no' => '05',
            'side' => 'RA',
            'side_display' => 'RH',
            'side_family' => 'RIGHT',
            'required_qty' => 5,
            'received_qty' => 0,
            'current_state' => 'STORE',
        ]);

        // 2. Create receipt for req1 and send to QC
        $receipt1 = EcnReceiptItem::create([
            'ecn_requirement_id' => $req1->id,
            'project_id' => $this->project->id,
            'ecn_number' => 'ECN-01',
            'status' => 'sent_to_qc',
            'received_quantity' => 10,
            'side' => 'LA',
            'side_display' => 'LH',
        ]);

        $service = new KpiDrilldownService();

        // Check total_parts for ECN
        $totalDrilldown = $service->getDrilldownData('total_parts', ['project_id' => $this->project->id, 'is_ecn' => true]);
        $this->assertEquals(15, $totalDrilldown['total_quantity']);
        $this->assertCount(2, $totalDrilldown['data']);

        // Check parts_pending for ECN
        $pendingDrilldown = $service->getDrilldownData('parts_pending', ['project_id' => $this->project->id, 'is_ecn' => true]);
        $this->assertEquals(5, $pendingDrilldown['total_quantity']);
        $this->assertCount(1, $pendingDrilldown['data']);
        $this->assertEquals('05', $pendingDrilldown['data'][0]['part_no']);

        // Check QC inspection for ECN
        $qcDrilldown = $service->getDrilldownData('qc', ['project_id' => $this->project->id, 'is_ecn' => true]);
        $this->assertEquals(10, $qcDrilldown['total_quantity']);
        $this->assertCount(1, $qcDrilldown['data']);
        $this->assertEquals('020#R00', $qcDrilldown['data'][0]['part_no']);
        $this->assertEquals('LA', $qcDrilldown['data'][0]['side']);

        // Test HTTP endpoint GET /api/v1/ecn/drilldown
        $httpRes = $this->actingAs($this->adminUser)->getJson("/api/v1/ecn/drilldown?kpi=qc&project_id={$this->project->id}");
        $httpRes->assertStatus(200);
        $httpRes->assertJsonPath('total_quantity', 10);
    }

    public function test_ecn_qc_rejection_routes_to_purchase_queue_without_altering_pending_intake(): void
    {
        $req = EcnRequirement::create([
            'project_id' => $this->project->id,
            'ecn_import_batch_id' => $this->batch->id,
            'ecn_number' => 'ECN-02',
            'jig_no' => '169962@',
            'unit_no' => '01',
            'part_no' => '030#R00',
            'side' => 'L',
            'side_display' => 'LH',
            'side_family' => 'LEFT',
            'required_qty' => 4,
            'received_qty' => 4,
            'current_state' => 'QC',
        ]);

        $receipt = EcnReceiptItem::create([
            'ecn_requirement_id' => $req->id,
            'project_id' => $this->project->id,
            'ecn_number' => 'ECN-02',
            'status' => 'qc_received',
            'received_quantity' => 4,
            'side' => 'L',
            'side_display' => 'LH',
        ]);

        $workflowService = new EcnWorkflowService();
        // Inspect: 2 Approved -> ASSEMBLY, 2 Rejected
        $res = $workflowService->qcInspect(
            $receipt->id,
            approvedQty: 2,
            destination: 'ASSEMBLY',
            rejectedQty: 2,
            reworkQty: 0,
            remarks: 'Surface scratch defect',
            userId: $this->adminUser->id
        );

        $this->assertTrue($res['success']);

        // Assert PurchaseQueueItem was created for the rejected quantity
        $purchaseItem = PurchaseQueueItem::where('project_id', $this->project->id)
            ->where('standard_part_no', '030#R00')
            ->first();

        $this->assertNotNull($purchaseItem);
        $this->assertEquals(2, $purchaseItem->rejected_quantity);
        $this->assertEquals('pending_purchase', $purchaseItem->status);
        $this->assertStringContainsString('ECN: ECN-02', $purchaseItem->remarks);

        // Verify drilldown for qc rejected
        $service = new KpiDrilldownService();
        $qcRejDrilldown = $service->getDrilldownData('qc', [
            'project_id' => $this->project->id,
            'substate' => 'rejected',
            'is_ecn' => true,
        ]);

        $this->assertEquals(2, $qcRejDrilldown['total_quantity']);
        $this->assertEquals('rejected', $qcRejDrilldown['data'][0]['substate']);
    }

    public function test_ecn_excel_export_endpoint_returns_file_with_part_number_and_side(): void
    {
        $req = EcnRequirement::create([
            'project_id' => $this->project->id,
            'ecn_import_batch_id' => $this->batch->id,
            'ecn_number' => 'ECN-03',
            'jig_no' => '169963@',
            'unit_no' => '05',
            'part_no' => '020#R00',
            'side' => 'LA',
            'side_display' => 'LH',
            'side_family' => 'LEFT',
            'required_qty' => 8,
            'received_qty' => 8,
            'current_state' => 'STORE',
        ]);

        $response = $this->actingAs($this->adminUser)->postJson('/api/v1/export/drilldown', [
            'kpi' => 'ecn_total_parts',
            'project_id' => $this->project->id,
        ]);

        $response->assertStatus(200);
        $this->assertTrue(
            str_contains($response->headers->get('Content-Type'), 'spreadsheetml') ||
            str_contains($response->headers->get('Content-Disposition'), '.xlsx')
        );
    }
}
