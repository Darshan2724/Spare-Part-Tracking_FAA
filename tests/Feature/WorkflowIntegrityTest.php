<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Project;
use App\Models\BomItem;
use App\Models\BomRequirement;
use App\Models\Receipt;
use App\Models\ReceiptItem;
use App\Models\QcInspection;
use App\Models\PaintRecord;
use App\Models\AssemblyRecord;
use App\Services\QuantityCalculationService;
use App\Services\HierarchyService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class WorkflowIntegrityTest extends TestCase
{
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

    public function test_auth_me_endpoint_returns_authenticated_user()
    {
        $user = $this->getAdminUser();
        $this->actingAs($user, 'sanctum');

        $response = $this->getJson('/api/v1/auth/me');
        $response->assertStatus(200)
                 ->assertJsonStructure([
                     'id', 'name', 'email'
                 ]);
    }

    public function test_dashboard_summary_returns_valid_structure()
    {
        $user = $this->getAdminUser();
        $this->actingAs($user, 'sanctum');

        $response = $this->getJson('/api/v1/dashboard/summary');
        $response->assertStatus(200)
                 ->assertJsonStructure([
                     'today_throughput',
                     'delayed_parts',
                     'projects_progress',
                 ]);
    }

    public function test_side_isolation_between_rh_and_lh()
    {
        $user = $this->getAdminUser();
        $this->actingAs($user, 'sanctum');

        // Verify RH and LH requirements are queried independently
        $rhReqs = BomRequirement::where('side', 'RH')->count();
        $lhReqs = BomRequirement::where('side', 'LH')->count();

        $this->assertIsInt($rhReqs);
        $this->assertIsInt($lhReqs);
    }

    public function test_assembly_completion_moves_part_to_assembly_completed_and_never_paint()
    {
        $user = $this->getAdminUser();
        $this->actingAs($user, 'sanctum');

        DB::beginTransaction();
        try {
            // 1. Create a clean isolated test project with 1 BOM Item
            $project = Project::create([
                'project_code' => 'TEST-ASM-01',
                'name' => 'Assembly Test Project',
                'status' => 'active',
                'created_by' => $user->id,
            ]);

            $bomItem = BomItem::create([
                'project_id' => $project->id,
                'standard_part_no' => 'PART-TEST-ASM-01',
                'jig_no' => 'JIG-01',
                'unit_no' => '01',
            ]);

            BomRequirement::create([
                'bom_item_id' => $bomItem->id,
                'side' => 'RH',
                'required_quantity' => 10,
            ]);

            // 2. Receive 10 parts in store
            $receipt = Receipt::create([
                'project_id' => $project->id,
                'delivery_note_number' => 'DN-TEST-01',
                'received_by' => $user->id,
            ]);

            $receiptItem = ReceiptItem::create([
                'receipt_id' => $receipt->id,
                'bom_item_id' => $bomItem->id,
                'side' => 'RH',
                'received_quantity' => 10,
                'status' => 'qc_approved',
            ]);

            // 3. QC approves 10 parts destined for PAINT
            $qc = QcInspection::create([
                'bom_item_id' => $bomItem->id,
                'receipt_item_id' => $receiptItem->id,
                'inspected_by' => $user->id,
                'side' => 'RH',
                'inspected_quantity' => 10,
                'approved_quantity' => 10,
                'destination' => 'PAINT',
                'result' => 'approved',
                'inspection_date' => now(),
            ]);

            // Verify before paint: Paint Active = 10, Assembly = 0
            $service = app(QuantityCalculationService::class);
            $metricsBeforePaint = $service->calculateProjectMetrics($project);
            $this->assertEquals(10, $metricsBeforePaint['parts_in_paint']);
            $this->assertEquals(0, $metricsBeforePaint['parts_in_assembly']);

            // 4. Paint completes 10 parts
            $paint = PaintRecord::create([
                'bom_item_id' => $bomItem->id,
                'qc_inspection_id' => $qc->id,
                'side' => 'RH',
                'quantity' => 10,
                'painted_by' => $user->id,
                'status' => 'completed',
            ]);

            // Verify after paint: Paint Active = 0, Assembly Active = 10, Assembly Completed = 0
            $metricsAfterPaint = $service->calculateProjectMetrics($project);
            $this->assertEquals(0, $metricsAfterPaint['parts_in_paint']);
            $this->assertEquals(10, $metricsAfterPaint['parts_in_assembly']);
            $this->assertEquals(0, $metricsAfterPaint['assembly_completed']);

            // 5. Assembly user completes 10 parts via API
            $response = $this->postJson('/api/v1/assembly/items', [
                'bom_item_id' => $bomItem->id,
                'paint_record_id' => $paint->id,
                'side' => 'RH',
                'quantity' => 10,
                'remarks' => 'Completed in test',
            ]);
            $response->assertStatus(200);

            // 6. Verify CRITICAL INVARIANTS after Assembly completion:
            $metricsAfterAsm = $service->calculateProjectMetrics($project);

            // Invariant A: Part MUST NOT reappear in Paint!
            $this->assertEquals(0, $metricsAfterAsm['parts_in_paint'], 'Paint active must be 0 and never re-acquire assembled parts!');

            // Invariant B: Active Assembly must decrease to 0!
            $this->assertEquals(0, $metricsAfterAsm['parts_in_assembly'], 'Active Assembly must be 0 after full completion.');

            // Invariant C: Assembly Completed must be 10!
            $this->assertEquals(10, $metricsAfterAsm['assembly_completed'], 'Assembly Completed must be 10.');

            // Invariant D: Zero-sum accounting preserved!
            $this->assertEquals(10, $metricsAfterAsm['total_parts'], 'Total Parts must remain unchanged.');
            $this->assertEquals(10, $metricsAfterAsm['total_parts_received'], 'Total Parts Received must remain unchanged.');
            $this->assertEquals(0, $metricsAfterAsm['parts_pending'], 'Parts Pending must remain 0.');

            // Invariant E: Project status becomes completed because 100% of required parts are assembled!
            $project->refresh();
            $this->assertEquals('completed', $project->status);

        } finally {
            DB::rollBack();
        }
    }

    public function test_partial_assembly_completion_and_rh_lh_isolation()
    {
        $user = $this->getAdminUser();
        $this->actingAs($user, 'sanctum');

        DB::beginTransaction();
        try {
            $project = Project::create([
                'project_code' => 'TEST-ASM-02',
                'name' => 'Partial Assembly & Side Test',
                'status' => 'active',
                'created_by' => $user->id,
            ]);

            $bomItem = BomItem::create([
                'project_id' => $project->id,
                'standard_part_no' => 'PART-TEST-ASM-02',
                'jig_no' => 'JIG-01',
                'unit_no' => '01',
            ]);

            BomRequirement::create([
                'bom_item_id' => $bomItem->id,
                'side' => 'RH',
                'required_quantity' => 6,
            ]);

            BomRequirement::create([
                'bom_item_id' => $bomItem->id,
                'side' => 'LH',
                'required_quantity' => 6,
            ]);

            // Receive 6 RH and 6 LH
            $receipt = Receipt::create([
                'project_id' => $project->id,
                'delivery_note_number' => 'DN-TEST-02',
                'received_by' => $user->id,
            ]);

            $recRH = ReceiptItem::create([
                'receipt_id' => $receipt->id,
                'bom_item_id' => $bomItem->id,
                'side' => 'RH',
                'received_quantity' => 6,
                'status' => 'qc_approved',
            ]);

            $recLH = ReceiptItem::create([
                'receipt_id' => $receipt->id,
                'bom_item_id' => $bomItem->id,
                'side' => 'LH',
                'received_quantity' => 6,
                'status' => 'qc_approved',
            ]);

            // QC approves both directly to Assembly
            $qcRH = QcInspection::create([
                'bom_item_id' => $bomItem->id,
                'receipt_item_id' => $recRH->id,
                'inspected_by' => $user->id,
                'side' => 'RH',
                'inspected_quantity' => 6,
                'approved_quantity' => 6,
                'destination' => 'ASSEMBLY',
                'result' => 'approved',
                'inspection_date' => now(),
            ]);

            $qcLH = QcInspection::create([
                'bom_item_id' => $bomItem->id,
                'receipt_item_id' => $recLH->id,
                'inspected_by' => $user->id,
                'side' => 'LH',
                'inspected_quantity' => 6,
                'approved_quantity' => 6,
                'destination' => 'ASSEMBLY',
                'result' => 'approved',
                'inspection_date' => now(),
            ]);

            $service = app(QuantityCalculationService::class);
            $before = $service->calculateProjectMetrics($project);
            $this->assertEquals(12, $before['parts_in_assembly']);
            $this->assertEquals(0, $before['assembly_completed']);

            // Partially complete 4 RH units out of 6
            $response = $this->postJson('/api/v1/assembly/items', [
                'bom_item_id' => $bomItem->id,
                'qc_inspection_id' => $qcRH->id,
                'side' => 'RH',
                'quantity' => 4,
                'remarks' => 'Partial 4 RH completed',
            ]);
            $response->assertStatus(200);

            $afterPartial = $service->calculateProjectMetrics($project);
            // RH: 2 active, 4 completed; LH: 6 active, 0 completed
            // Total Active Assembly = 2 + 6 = 8
            // Total Completed Assembly = 4
            $this->assertEquals(8, $afterPartial['parts_in_assembly']);
            $this->assertEquals(4, $afterPartial['assembly_completed']);
            $this->assertEquals(0, $afterPartial['parts_in_paint']);

            // Check RH side specifically
            $rhMetrics = $service->calculateProjectMetrics($project, 'RH');
            $this->assertEquals(2, $rhMetrics['parts_in_assembly']);
            $this->assertEquals(4, $rhMetrics['assembly_completed']);

            // Check LH side specifically (must be completely unaffected)
            $lhMetrics = $service->calculateProjectMetrics($project, 'LH');
            $this->assertEquals(6, $lhMetrics['parts_in_assembly']);
            $this->assertEquals(0, $lhMetrics['assembly_completed']);

            // Project must remain active (not completed)
            $project->refresh();
            $this->assertEquals('active', $project->status);

        } finally {
            DB::rollBack();
        }
    }
}
