<?php

namespace Tests\Feature;

use App\Models\BomItem;
use App\Models\BomRequirement;
use App\Models\EcnReceiptItem;
use App\Models\EcnRequirement;
use App\Models\EcnWorkflowRecord;
use App\Models\Project;
use App\Models\User;
use App\Services\HierarchyService;
use App\Services\EcnQuantityCalculationService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class EcnQcInspectionAndCardVisibilityTest extends TestCase
{
    use DatabaseTransactions;

    protected function getAdminUser(): User
    {
        \Spatie\Permission\Models\Role::firstOrCreate(['name' => 'ADMIN', 'guard_name' => 'web']);
        \Spatie\Permission\Models\Role::firstOrCreate(['name' => 'ADMIN', 'guard_name' => 'sanctum']);

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
        }
        $user->syncRoles(['ADMIN']);
        return $user;
    }

    public function test_ecn_qc_inspect_approve_routes_to_assembly_with_exact_quantity(): void
    {
        $user = $this->getAdminUser();

        $code = 'TEST-QC-INSP-' . uniqid();
        $project = Project::create([
            'project_code' => $code,
            'name' => "QC Inspect Project {$code}",
            'status' => 'active',
        ]);

        $ecnReq = EcnRequirement::create([
            'project_id' => $project->id,
            'ecn_number' => 'ECN-901',
            'jig_no' => 'JIG-900',
            'unit_no' => '01',
            'part_no' => 'ECN-PART-901',
            'side' => 'LH',
            'required_qty' => 4,
            'received_qty' => 4,
            'current_state' => 'QC',
        ]);

        $receiptItem = EcnReceiptItem::create([
            'ecn_requirement_id' => $ecnReq->id,
            'project_id' => $project->id,
            'status' => 'qc_received',
            'received_quantity' => 4,
            'received_date' => now(),
        ]);

        // Execute Inspection: Approve 4 to Assembly
        $response = $this->actingAs($user, 'sanctum')->postJson('/api/v1/ecn/qc/inspect', [
            'ecn_receipt_item_id' => $receiptItem->id,
            'ecn_requirement_id' => $ecnReq->id,
            'approved_quantity' => 4,
            'destination' => 'ASSEMBLY',
            'remarks' => 'Approved by QC inspector',
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('success', true);

        // Verify Database
        $this->assertDatabaseHas('ecn_receipt_items', [
            'id' => $receiptItem->id,
            'status' => 'qc_approved',
        ]);

        $this->assertDatabaseHas('ecn_requirements', [
            'id' => $ecnReq->id,
            'current_state' => 'ASSEMBLY',
        ]);

        $this->assertDatabaseHas('ecn_workflow_records', [
            'ecn_receipt_item_id' => $receiptItem->id,
            'department' => 'ASSEMBLY',
            'action' => 'assembly_queued',
            'quantity' => 4,
            'status' => 'in_progress',
        ]);
    }

    public function test_ecn_qc_inspect_split_routing_to_paint_and_assembly(): void
    {
        $user = $this->getAdminUser();

        $code = 'TEST-QC-SPLIT-' . uniqid();
        $project = Project::create([
            'project_code' => $code,
            'name' => "QC Split Project {$code}",
            'status' => 'active',
        ]);

        $ecnReq = EcnRequirement::create([
            'project_id' => $project->id,
            'ecn_number' => 'ECN-902',
            'jig_no' => 'JIG-900',
            'unit_no' => '02',
            'part_no' => 'ECN-PART-902',
            'side' => 'LH',
            'required_qty' => 6,
            'received_qty' => 6,
            'current_state' => 'QC',
        ]);

        $receiptItem = EcnReceiptItem::create([
            'ecn_requirement_id' => $ecnReq->id,
            'project_id' => $project->id,
            'status' => 'qc_received',
            'received_quantity' => 6,
            'received_date' => now(),
        ]);

        // Split: 2 to Paint, 1 to Assembly, 1 to Rework, 2 to Reject
        $response = $this->actingAs($user, 'sanctum')->postJson('/api/v1/ecn/qc/inspect', [
            'ecn_receipt_item_id' => $receiptItem->id,
            'ecn_requirement_id' => $ecnReq->id,
            'paint_quantity' => 2,
            'assembly_quantity' => 1,
            'rework_quantity' => 1,
            'rejected_quantity' => 2,
            'remarks' => 'Split inspection test',
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('success', true);

        // Verify records created
        $this->assertDatabaseHas('ecn_workflow_records', [
            'ecn_receipt_item_id' => $receiptItem->id,
            'department' => 'PAINT',
            'quantity' => 2,
            'status' => 'in_progress',
        ]);

        $this->assertDatabaseHas('ecn_workflow_records', [
            'ecn_receipt_item_id' => $receiptItem->id,
            'department' => 'ASSEMBLY',
            'quantity' => 1,
            'status' => 'in_progress',
        ]);

        $this->assertDatabaseHas('ecn_workflow_records', [
            'ecn_receipt_item_id' => $receiptItem->id,
            'department' => 'REWORK',
            'quantity' => 1,
            'status' => 'in_progress',
        ]);
    }

    public function test_ecn_qc_inspect_fallback_resolves_receipt_item_from_requirement_id(): void
    {
        $user = $this->getAdminUser();

        $code = 'TEST-QC-FALLBACK-' . uniqid();
        $project = Project::create([
            'project_code' => $code,
            'name' => "QC Fallback Project {$code}",
            'status' => 'active',
        ]);

        $ecnReq = EcnRequirement::create([
            'project_id' => $project->id,
            'ecn_number' => 'ECN-903',
            'jig_no' => 'JIG-900',
            'unit_no' => '03',
            'part_no' => 'ECN-PART-903',
            'side' => 'LH',
            'required_qty' => 2,
            'received_qty' => 2,
            'current_state' => 'QC',
        ]);

        $receiptItem = EcnReceiptItem::create([
            'ecn_requirement_id' => $ecnReq->id,
            'project_id' => $project->id,
            'status' => 'qc_received',
            'received_quantity' => 2,
            'received_date' => now(),
        ]);

        // Do not pass ecn_receipt_item_id, pass only ecn_requirement_id
        $response = $this->actingAs($user, 'sanctum')->postJson('/api/v1/ecn/qc/inspect', [
            'ecn_requirement_id' => $ecnReq->id,
            'approved_quantity' => 2,
            'destination' => 'ASSEMBLY',
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('success', true);

        $this->assertDatabaseHas('ecn_receipt_items', [
            'id' => $receiptItem->id,
            'status' => 'qc_approved',
        ]);
    }

    public function test_card_ecn_numbers_display_only_for_applicable_department_scope(): void
    {
        $code = 'TEST-CARD-VIS-' . uniqid();
        $project = Project::create([
            'project_code' => $code,
            'name' => "Card Vis Project {$code}",
            'status' => 'active',
        ]);

        $bomItem = BomItem::create([
            'project_id' => $project->id,
            'standard_part_no' => 'REG-PART-1',
            'item_no' => 'RP1',
            'jig_no' => 'JIG-101',
            'unit_no' => '01',
        ]);
        BomRequirement::create([
            'bom_item_id' => $bomItem->id,
            'side' => 'LH',
            'required_quantity' => 10,
        ]);

        // Create ECN item 1 in QC (active in QC)
        $ecnReq1 = EcnRequirement::create([
            'project_id' => $project->id,
            'ecn_number' => 'ECN-101',
            'jig_no' => 'JIG-101',
            'unit_no' => '01',
            'part_no' => 'ECN-PART-101',
            'side' => 'LH',
            'required_qty' => 3,
            'received_qty' => 3,
            'current_state' => 'QC',
        ]);

        // Create ECN item 2 in PAINT (not in QC)
        $ecnReq2 = EcnRequirement::create([
            'project_id' => $project->id,
            'ecn_number' => 'ECN-102',
            'jig_no' => 'JIG-101',
            'unit_no' => '02',
            'part_no' => 'ECN-PART-102',
            'side' => 'LH',
            'required_qty' => 2,
            'received_qty' => 2,
            'current_state' => 'PAINT',
        ]);

        $hierarchyService = new HierarchyService();

        // 1. Query QC Department Hierarchy
        $qcHierarchy = $hierarchyService->getDepartmentHierarchy('qc', $project->id);
        $this->assertNotEmpty($qcHierarchy['jigs']);
        $qcJig = $qcHierarchy['jigs'][0];

        // QC Jig card must show ECN (3 parts) and ECN-101 in ecn_numbers (and not ECN-102 which is in Paint)
        $this->assertTrue($qcJig['is_ecn_present']);
        $this->assertEquals(['ECN-101'], $qcJig['ecn_numbers']);
        $this->assertEquals('ECN (3 parts)', $qcJig['ecn_number_display']);

        // QC Unit 01 must show ECN (3 parts)
        $unit01 = collect($qcJig['units'])->first(function ($u) {
            return in_array($u['unit_no'], ['01', 'Unit 01']);
        });
        $this->assertNotNull($unit01);
        $this->assertTrue($unit01['is_ecn_present']);
        $this->assertEquals(['ECN-101'], $unit01['ecn_numbers']);
        $this->assertEquals('ECN (3 parts)', $unit01['ecn_number_display']);

        // 2. Query Paint Department Hierarchy
        $paintHierarchy = $hierarchyService->getDepartmentHierarchy('paint', $project->id);
        $this->assertNotEmpty($paintHierarchy['jigs']);
        $paintJig = $paintHierarchy['jigs'][0];

        // Paint Jig card must show ECN (2 parts) and ECN-102 in ecn_numbers
        $this->assertTrue($paintJig['is_ecn_present']);
        $this->assertEquals(['ECN-102'], $paintJig['ecn_numbers']);
        $this->assertEquals('ECN (2 parts)', $paintJig['ecn_number_display']);
    }
}
