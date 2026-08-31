<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\BomItem;
use App\Models\BomRequirement;
use App\Models\Receipt;
use App\Models\ReceiptItem;
use App\Models\QcInspection;
use App\Models\ReworkRecord;
use App\Models\PaintRecord;
use App\Models\AssemblyRecord;
use App\Models\EcnRequirement;
use App\Models\EcnReceiptItem;
use App\Models\EcnWorkflowRecord;
use App\Models\User;
use App\Services\QuantityCalculationService;
use App\Services\EcnQuantityCalculationService;
use App\Services\CanonicalCurrentStateService;
use App\Services\HierarchyService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * WorkflowDisplayRealignmentTest
 * 
 * Validates the critical workflow display realignment between Mobile Department Queues
 * and Website Dashboard KPIs while ensuring strict isolation and formula integrity.
 */
class WorkflowDisplayRealignmentTest extends TestCase
{
    protected function getAdminUser(): User
    {
        \Spatie\Permission\Models\Role::firstOrCreate(['name' => 'ADMIN', 'guard_name' => 'sanctum']);
        \Spatie\Permission\Models\Role::firstOrCreate(['name' => 'ADMIN', 'guard_name' => 'web']);

        $user = User::firstOrCreate(
            ['email' => 'admin@faithautomation.com'],
            [
                'name' => 'Admin User',
                'password' => bcrypt('password123'),
                'is_active' => true,
            ]
        );

        if (!$user->hasRole('ADMIN')) {
            $user->assignRole('ADMIN');
        }

        return $user;
    }

    /**
     * Test Parts 1, 2, 3, 4, 5, 9, 10:
     * - Store Mobile shows Pending Intake (Regular Pending + ECN Pending)
     * - QC Arrival shows parts represented by Website Store KPI
     * - QC Inspection shows parts represented by Website QC KPI
     * - Main Website Dashboard has ONLY regular parts
     * - ECN section has ONLY ECN parts
     */
    public function test_workflow_display_realignment_lifecycle()
    {
        $user = $this->getAdminUser();
        $this->actingAs($user, 'sanctum');

        $testCode = 'TEST-REALIGN-' . uniqid();
        $project = Project::create([
            'project_code' => $testCode,
            'name' => "Workflow Realignment Test Project {$testCode}",
            'status' => 'active',
        ]);

        try {
            // 1. Create Regular BOM: 10 required
            $bomItem = BomItem::create([
                'project_id' => $project->id,
                'standard_part_no' => 'REG-REALIGN-01',
                'item_no' => 'R01',
                'jig_no' => 'JIG-01',
                'unit_no' => '01',
            ]);
            BomRequirement::create([
                'bom_item_id' => $bomItem->id,
                'side' => 'LH',
                'required_quantity' => 10,
            ]);

            // 2. Create ECN BOM: 5 required
            $ecnReq = EcnRequirement::create([
                'project_id' => $project->id,
                'ecn_number' => 'ECN-REALIGN-01',
                'part_no' => 'ECN-PART-01',
                'jig_no' => 'JIG-01',
                'unit_no' => '01',
                'side' => 'LH',
                'side_display' => 'LH',
                'required_qty' => 5,
                'received_qty' => 0,
                'current_state' => 'PENDING',
            ]);

            $qtyService = app(QuantityCalculationService::class);
            $ecnQtyService = app(EcnQuantityCalculationService::class);
            $hierarchyService = app(HierarchyService::class);
            $canonicalService = app(CanonicalCurrentStateService::class);

            // --- STAGE 0: Initial State (All Pending) ---
            // Main Dashboard (Website): Regular ONLY
            $webMetrics = $qtyService->calculateProjectMetrics($project->id);
            $this->assertEquals(10, $webMetrics['total_required']);
            $this->assertEquals(0, $webMetrics['total_received']);
            $this->assertEquals(10, $webMetrics['total_pending'], 'Website Main Dashboard total_pending must be strictly Regular (10)');
            $this->assertEquals(0, $webMetrics['parts_in_store'], 'Website Store KPI must be 0 initially');
            $this->assertEquals(0, $webMetrics['parts_in_qc'], 'Website QC KPI must be 0 initially');

            // ECN Dashboard (Website): ECN ONLY
            $ecnSummary = $ecnQtyService->calculateEcnDashboardSummary(['project_id' => $project->id]);
            $this->assertEquals(5, $ecnSummary['total_parts']);
            $this->assertEquals(5, $ecnSummary['parts_pending'], 'Website ECN Summary parts_pending must be strictly ECN (5)');

            // Mobile Store Hierarchy: Pending Intake (Regular Pending + ECN Pending)
            $storeHierarchy = $hierarchyService->getDepartmentHierarchy('store', $project->id);
            $storeProjCard = $hierarchyService->formatProjectOverviewStatsFromMetrics($project, 'store', $webMetrics);
            $this->assertEquals(10, $storeProjCard['pending_qty'], 'Store mobile project card pending_qty must show Regular pending (10)');
            $this->assertEquals(5, $storeProjCard['ecn_count'], 'Store mobile project card ecn_count is 5 for pending ECN parts');
            $this->assertEquals(5, $storeHierarchy['project']['ecn_parts']);
            $this->assertEquals(5, $storeHierarchy['jigs'][0]['ecn_parts']);
            $this->assertEquals(5, $storeHierarchy['jigs'][0]['units'][0]['ecn_parts']);

            // Mobile QC Hierarchy
            $qcHierarchy = $hierarchyService->getDepartmentHierarchy('qc', $project->id);
            $qcProjCard = $hierarchyService->formatProjectOverviewStatsFromMetrics($project, 'qc', $webMetrics);
            $this->assertEquals(0, $qcProjCard['eligible_qty'], 'QC mobile card must have 0 eligible initially');
            $this->assertEquals(0, $qcHierarchy['project']['ecn_parts'], 'QC mobile card must have 0 ECN parts initially');

            // --- STAGE 1: Store Receipt (Partial Intake) ---
            // Receive 6 Regular items into Store
            $receipt = Receipt::create([
                'project_id' => $project->id,
                'receipt_number' => 'REC-' . uniqid(),
                'received_by' => $user->id,
                'status' => 'completed',
            ]);
            $recItem = ReceiptItem::create([
                'receipt_id' => $receipt->id,
                'bom_item_id' => $bomItem->id,
                'side' => 'LH',
                'received_quantity' => 6,
                'status' => 'received',
            ]);

            // Receive 2 ECN items into Store
            $ecnRecItem = EcnReceiptItem::create([
                'ecn_requirement_id' => $ecnReq->id,
                'received_quantity' => 2,
                'received_by' => $user->id,
                'status' => 'received',
            ]);
            $ecnReq->update([
                'received_qty' => 2,
                'current_state' => 'STORE',
            ]);

            // Assert Stage 1:
            // Web Main Dashboard:
            $webMetrics1 = $qtyService->calculateProjectMetrics($project->id);
            $this->assertEquals(10, $webMetrics1['total_required']);
            $this->assertEquals(6, $webMetrics1['total_received']);
            $this->assertEquals(4, $webMetrics1['total_pending'], 'Regular pending decreased to 4');
            $this->assertEquals(6, $webMetrics1['parts_in_store'], 'Website Store KPI represents parts received awaiting QC acceptance (6)');
            $this->assertEquals(0, $webMetrics1['parts_in_qc'], 'Website QC KPI is 0 (not accepted into QC inspection yet)');

            // Canonical Service QC Arrival:
            $qcArrival = $canonicalService->getQcArrivalQuantities($project->id);
            $this->assertEquals(6, $qcArrival['regular_arrival'], 'QC Arrival regular count matches Website Store KPI (6)');
            $this->assertEquals(2, $qcArrival['ecn_arrival'], 'QC Arrival ECN count matches received ECN in store (2)');

            // QC Arrival API Queue:
            $qcQueueRes = $this->getJson("/api/v1/qc/queue?project_id={$project->id}&stage=arrival");
            $qcQueueRes->assertStatus(200);
            $arrivalItems = $qcQueueRes->json('data') ?? $qcQueueRes->json();
            $this->assertEquals(6, collect($arrivalItems)->sum('received_quantity'), 'QC Arrival mobile queue returns exact received quantity (6)');

            // --- STAGE 2: QC Arrival Acceptance (Move from Arrival to QC Inspection) ---
            // Accept 4 of the 6 Regular items into QC Inspection
            $acceptRes = $this->postJson('/api/v1/qc/receive', [
                'receipt_item_id' => $recItem->id,
                'quantity' => 4,
            ]);
            $acceptRes->assertStatus(200);

            // Accept 2 ECN items into QC Inspection
            $ecnAcceptRes = $this->postJson('/api/v1/qc/receive', [
                'is_ecn' => true,
                'ecn_requirement_id' => $ecnReq->id,
                'ecn_receipt_item_id' => $ecnRecItem->id,
                'quantity' => 2,
            ]);
            $ecnAcceptRes->assertStatus(200);

            // Assert Stage 2:
            // Web Main Dashboard:
            $webMetrics2 = $qtyService->calculateProjectMetrics($project->id);
            $this->assertEquals(2, $webMetrics2['parts_in_store'], 'Website Store KPI decreased from 6 to 2 (4 accepted into QC)');
            $this->assertEquals(4, $webMetrics2['parts_in_qc'], 'Website QC KPI increased to 4 (in active inspection)');

            // QC Arrival Mobile:
            $qcArrival2 = $canonicalService->getQcArrivalQuantities($project->id);
            $this->assertEquals(2, $qcArrival2['regular_arrival'], 'QC Arrival mobile matches Website Store KPI (2)');

            // QC Inspection Mobile:
            $qcInspection2 = $canonicalService->getQcInspectionQuantities($project->id);
            $this->assertEquals(4, $qcInspection2['regular_inspection'], 'QC Inspection mobile matches Website QC KPI (4)');
            $this->assertEquals(2, $qcInspection2['ecn_inspection'], 'QC Inspection ECN count is 2');

            // --- STAGE 3: QC Inspection to Rework (1 item) & Paint (2 items) & Assembly (1 item) ---
            $activeQcItem = ReceiptItem::where('bom_item_id', $bomItem->id)->where('status', 'qc_received')->first();
            $this->assertNotNull($activeQcItem);

            // Inspect active QC item: 1 rework, 2 approved to Paint, 1 direct approved to Assembly
            $qcInspRework = QcInspection::create([
                'bom_item_id' => $bomItem->id,
                'receipt_item_id' => $activeQcItem->id,
                'side' => 'LH',
                'inspected_by' => $user->id,
                'inspected_quantity' => 3,
                'approved_quantity' => 2,
                'rejected_quantity' => 0,
                'rework_quantity' => 1,
                'result' => 'approved',
                'destination' => 'PAINT',
                'inspection_date' => now(),
            ]);

            QcInspection::create([
                'bom_item_id' => $bomItem->id,
                'receipt_item_id' => $activeQcItem->id,
                'side' => 'LH',
                'inspected_by' => $user->id,
                'inspected_quantity' => 1,
                'approved_quantity' => 1,
                'rejected_quantity' => 0,
                'rework_quantity' => 0,
                'result' => 'approved',
                'destination' => 'ASSEMBLY',
                'inspection_date' => now(),
            ]);

            // Assert Stage 3:
            $webMetrics3 = $qtyService->calculateProjectMetrics($project->id);
            $this->assertEquals(2, $webMetrics3['parts_in_store'], 'Website Store KPI remains 2');
            $this->assertEquals(0, $webMetrics3['parts_in_qc'], 'Website QC KPI is 0 (all 4 inspected)');
            $this->assertEquals(1, $webMetrics3['parts_in_rework'], 'Website Rework KPI is 1');
            $this->assertEquals(2, $webMetrics3['parts_in_paint'], 'Website Paint KPI is 2');
            $this->assertEquals(1, $webMetrics3['parts_in_assembly'], 'Website Assembly KPI is 1');

            // Rework mobile
            $reworkQuantities = $canonicalService->getReworkQuantities($project->id);
            $this->assertEquals(1, $reworkQuantities['regular_rework'], 'Rework mobile matches Website Rework KPI (1)');

            // Paint mobile
            $paintQuantities = $canonicalService->getPaintQuantities($project->id);
            $this->assertEquals(2, $paintQuantities['regular_paint'], 'Paint mobile matches Website Paint KPI (2)');

            // Assembly mobile
            $asmQuantities = $canonicalService->getAssemblyQuantities($project->id);
            $this->assertEquals(1, $asmQuantities['regular_assembly'], 'Assembly mobile matches Website Assembly KPI (1)');

            // --- STAGE 4: Rework Completion (returns to QC Inspection) ---
            ReworkRecord::create([
                'qc_inspection_id' => $qcInspRework->id,
                'bom_item_id' => $bomItem->id,
                'side' => 'LH',
                'assigned_to' => $user->id,
                'quantity' => 1,
                'status' => 'completed',
            ]);

            $webMetrics4 = $qtyService->calculateProjectMetrics($project->id);
            $this->assertEquals(0, $webMetrics4['parts_in_rework'], 'Website Rework KPI decreased to 0');
            $this->assertEquals(1, $webMetrics4['parts_in_qc'], 'Website QC KPI increased to 1 (rework item returned to QC inspection)');

            // Conservation of Parts Check:
            $totalResident = $webMetrics4['parts_in_store'] + $webMetrics4['parts_in_qc'] + $webMetrics4['parts_in_rework'] + $webMetrics4['parts_in_paint'] + $webMetrics4['parts_in_assembly'];
            $this->assertEquals($webMetrics4['total_received'], $totalResident, 'Sum of department resident balances must equal total received (6)');

        } finally {
            // --- STRICT CLEANUP: Delete ONLY disposable TEST-* project ---
            $this->cleanupTestProject($project->id);
        }
    }

    /**
     * Test Part 6: QC Rejected Handling:
     * - Rejected quantity must NOT be added to active QC inspection
     * - Rejected quantity must NOT become Parts Pending
     * - Rejected quantity remains strictly accountable
     */
    public function test_qc_rejected_parts_isolation()
    {
        $user = $this->getAdminUser();
        $this->actingAs($user, 'sanctum');

        $testCode = 'TEST-REJECT-' . uniqid();
        $project = Project::create([
            'project_code' => $testCode,
            'name' => "QC Reject Test Project {$testCode}",
            'status' => 'active',
        ]);

        try {
            $bomItem = BomItem::create([
                'project_id' => $project->id,
                'standard_part_no' => 'REG-REJ-01',
                'item_no' => 'REJ01',
                'jig_no' => 'JIG-01',
                'unit_no' => '01',
            ]);
            BomRequirement::create([
                'bom_item_id' => $bomItem->id,
                'side' => 'LH',
                'required_quantity' => 10,
            ]);

            $receipt = Receipt::create([
                'project_id' => $project->id,
                'receipt_number' => 'REC-' . uniqid(),
                'received_by' => $user->id,
                'status' => 'completed',
            ]);
            $recItem = ReceiptItem::create([
                'receipt_id' => $receipt->id,
                'bom_item_id' => $bomItem->id,
                'side' => 'LH',
                'received_quantity' => 5,
                'status' => 'qc_received',
            ]);

            // Reject 2 parts, approve 3 parts
            QcInspection::create([
                'bom_item_id' => $bomItem->id,
                'receipt_item_id' => $recItem->id,
                'side' => 'LH',
                'inspected_by' => $user->id,
                'inspected_quantity' => 5,
                'approved_quantity' => 3,
                'rejected_quantity' => 2,
                'rework_quantity' => 0,
                'result' => 'rejected',
                'destination' => 'PAINT',
                'rejection_reason' => 'Defective dimension',
                'inspection_date' => now(),
            ]);

            $qtyService = app(QuantityCalculationService::class);
            $metrics = $qtyService->calculateProjectMetrics($project->id);

            $this->assertEquals(10, $metrics['total_required']);
            $this->assertEquals(5, $metrics['total_received']);
            $this->assertEquals(5, $metrics['total_pending'], 'Pending parts remains 5 (rejected parts do not revert to pending)');
            $this->assertEquals(2, $metrics['qc_rejected'], 'Rejected count is 2');
            $this->assertEquals(0, $metrics['parts_in_qc'], 'Active QC inspection is 0 (all 5 accounted for)');
            $this->assertEquals(3, $metrics['parts_in_paint'], 'Paint active is 3');

        } finally {
            $this->cleanupTestProject($project->id);
        }
    }

    /**
     * Test ECN Hierarchical Reconciliation & Department Isolation:
     * - Project ECN = SUM(Jig ECN) = SUM(Unit ECN)
     * - Store shows Pending ECN
     * - QC shows active QC inspection ECN
     * - Rework, Paint, Assembly show active department ECN
     * - Strict isolation between stages
     */
    public function test_ecn_hierarchical_reconciliation_and_department_isolation()
    {
        $user = $this->getAdminUser();
        $this->actingAs($user, 'sanctum');

        $testCode = 'TEST-HIER-ECN-' . uniqid();
        $project = Project::create([
            'project_code' => $testCode,
            'name' => "Hierarchy ECN Test Project {$testCode}",
            'status' => 'active',
        ]);

        try {
            // Create ECN items across 2 Jigs and 3 Units
            // Jig-01 / Unit-01 (4 parts)
            $ecn1 = EcnRequirement::create([
                'project_id' => $project->id,
                'ecn_number' => 'ECN-001',
                'part_no' => 'P01',
                'jig_no' => 'JIG-01',
                'unit_no' => '01',
                'side' => 'LH',
                'side_display' => 'LH',
                'required_qty' => 4,
                'received_qty' => 0,
                'current_state' => 'PENDING',
            ]);

            // Jig-01 / Unit-02 (2 parts)
            $ecn2 = EcnRequirement::create([
                'project_id' => $project->id,
                'ecn_number' => 'ECN-002',
                'part_no' => 'P02',
                'jig_no' => 'JIG-01',
                'unit_no' => '02',
                'side' => 'RH',
                'side_display' => 'RH',
                'required_qty' => 2,
                'received_qty' => 0,
                'current_state' => 'PENDING',
            ]);

            // Jig-02 / Unit-01 (3 parts)
            $ecn3 = EcnRequirement::create([
                'project_id' => $project->id,
                'ecn_number' => 'ECN-003',
                'part_no' => 'P03',
                'jig_no' => 'JIG-02',
                'unit_no' => '01',
                'side' => 'COMMON',
                'side_display' => 'COMMON',
                'required_qty' => 3,
                'received_qty' => 0,
                'current_state' => 'PENDING',
            ]);

            $hierarchyService = app(HierarchyService::class);

            // 1. Initial Store Pending state: Total ECN Pending = 4 + 2 + 3 = 9
            $storeH = $hierarchyService->getDepartmentHierarchy('store', $project->id);
            $this->assertEquals(9, $storeH['project']['ecn_parts'], 'Project Store Pending ECN must be 9');
            $this->assertEquals('ECN (9 parts)', $storeH['project']['ecn_number_display']);
            
            $jig1 = collect($storeH['jigs'])->firstWhere('jig_name', 'JIG-01');
            $this->assertNotNull($jig1);
            $this->assertEquals(6, $jig1['ecn_parts'], 'JIG-01 Store Pending ECN must be 4 + 2 = 6');
            $this->assertEquals('ECN (6 parts)', $jig1['ecn_number_display']);

            $jig2 = collect($storeH['jigs'])->firstWhere('jig_name', 'JIG-02');
            $this->assertNotNull($jig2);
            $this->assertEquals(3, $jig2['ecn_parts'], 'JIG-02 Store Pending ECN must be 3');

            // Hierarchical check: Project == sum(Jigs)
            $sumJigs = array_sum(array_column($storeH['jigs'], 'ecn_parts'));
            $this->assertEquals($storeH['project']['ecn_parts'], $sumJigs, 'Project ECN must equal sum of Jig ECNs');

            // 2. Initial QC state: 0 items in QC
            $qcH = $hierarchyService->getDepartmentHierarchy('qc', $project->id);
            $this->assertEquals(0, $qcH['project']['ecn_parts'], 'QC Project ECN must be 0 when nothing in QC');
            $this->assertFalse($qcH['project']['is_ecn_present']);
            foreach ($qcH['jigs'] as $j) {
                $this->assertEquals(0, $j['ecn_parts']);
                $this->assertFalse($j['is_ecn_present']);
            }

            // 3. Receive 4 parts of ECN-001 into Store (Now in Store, awaiting QC Arrival)
            $rec1 = EcnReceiptItem::create([
                'ecn_requirement_id' => $ecn1->id,
                'received_quantity' => 4,
                'received_by' => $user->id,
                'status' => 'received',
            ]);
            $ecn1->update(['received_qty' => 4, 'current_state' => 'STORE']);

            // Test QC Arrival Hierarchy: Should report 4 parts in Arrival
            $qcArrivalH = $hierarchyService->getDepartmentHierarchy('qc', $project->id, ['stage' => 'arrival']);
            $this->assertEquals(4, $qcArrivalH['project']['ecn_parts'], 'QC Arrival Project ECN must be 4');
            $this->assertEquals('ECN (4 parts)', $qcArrivalH['project']['ecn_number_display']);
            $qcArrJig1 = collect($qcArrivalH['jigs'])->firstWhere('jig_name', 'JIG-01');
            $this->assertNotNull($qcArrJig1);
            $this->assertEquals(4, $qcArrJig1['ecn_parts']);
            $this->assertEquals('ECN (4 parts)', $qcArrJig1['ecn_number_display']);

            // Test QC Inspection Hierarchy: Should report 0 parts in active Inspection before acceptance
            $qcInspBeforeH = $hierarchyService->getDepartmentHierarchy('qc', $project->id, ['stage' => 'inspection']);
            $this->assertEquals(0, $qcInspBeforeH['project']['ecn_parts'], 'QC Inspection Project ECN must be 0 before acceptance');

            // Accept 4 parts of ECN-001 into QC Inspection
            $acceptRes = $this->postJson('/api/v1/qc/receive', [
                'is_ecn' => true,
                'ecn_requirement_id' => $ecn1->id,
                'ecn_receipt_item_id' => $rec1->id,
                'quantity' => 4,
            ]);
            $acceptRes->assertStatus(200);

            // Now in QC: ECN-001 (4 parts) is in QC Inspection
            $qcH2 = $hierarchyService->getDepartmentHierarchy('qc', $project->id, ['stage' => 'inspection']);
            $this->assertEquals(4, $qcH2['project']['ecn_parts'], 'QC Project ECN must now be 4');
            $this->assertEquals('ECN (4 parts)', $qcH2['project']['ecn_number_display']);
            $this->assertTrue($qcH2['project']['is_ecn_present']);

            $qcJig1 = collect($qcH2['jigs'])->firstWhere('jig_name', 'JIG-01');
            $this->assertNotNull($qcJig1);
            $this->assertEquals(4, $qcJig1['ecn_parts']);
            $this->assertEquals('ECN (4 parts)', $qcJig1['ecn_number_display']);
            $this->assertTrue($qcJig1['is_ecn_present']);

            $qcJig2 = collect($qcH2['jigs'])->firstWhere('jig_name', 'JIG-02');
            $this->assertNull($qcJig2, 'JIG-02 has 0 QC parts and is cleanly excluded from QC active queue');

            // Hierarchical check in QC: Project == sum(Jigs)
            $sumQcJigs = array_sum(array_column($qcH2['jigs'], 'ecn_parts'));
            $this->assertEquals($qcH2['project']['ecn_parts'], $sumQcJigs, 'QC Project ECN must equal sum of QC Jig ECNs');

        } finally {
            $this->cleanupTestProject($project->id);
        }
    }

    /**
     * Clean up disposable test project records safely.
     */
    protected function cleanupTestProject(int $projectId): void
    {
        DB::table('receipt_items')->whereIn('bom_item_id', function ($q) use ($projectId) {
            $q->select('id')->from('bom_items')->where('project_id', $projectId);
        })->delete();
        DB::table('receipts')->where('project_id', $projectId)->delete();
        DB::table('qc_inspections')->whereIn('bom_item_id', function ($q) use ($projectId) {
            $q->select('id')->from('bom_items')->where('project_id', $projectId);
        })->delete();
        DB::table('rework_records')->whereIn('bom_item_id', function ($q) use ($projectId) {
            $q->select('id')->from('bom_items')->where('project_id', $projectId);
        })->delete();
        DB::table('paint_records')->whereIn('bom_item_id', function ($q) use ($projectId) {
            $q->select('id')->from('bom_items')->where('project_id', $projectId);
        })->delete();
        DB::table('assembly_records')->whereIn('bom_item_id', function ($q) use ($projectId) {
            $q->select('id')->from('bom_items')->where('project_id', $projectId);
        })->delete();
        DB::table('bom_requirements')->whereIn('bom_item_id', function ($q) use ($projectId) {
            $q->select('id')->from('bom_items')->where('project_id', $projectId);
        })->delete();
        DB::table('bom_items')->where('project_id', $projectId)->delete();
        DB::table('ecn_receipt_items')->whereIn('ecn_requirement_id', function ($q) use ($projectId) {
            $q->select('id')->from('ecn_requirements')->where('project_id', $projectId);
        })->delete();
        DB::table('ecn_workflow_records')->whereIn('ecn_requirement_id', function ($q) use ($projectId) {
            $q->select('id')->from('ecn_requirements')->where('project_id', $projectId);
        })->delete();
        DB::table('ecn_requirements')->where('project_id', $projectId)->delete();
        Project::where('id', $projectId)->delete();
    }
}
