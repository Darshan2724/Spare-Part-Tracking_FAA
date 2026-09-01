<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\BomItem;
use App\Models\BomRequirement;
use App\Models\EcnRequirement;
use App\Models\EcnReceiptItem;
use App\Models\EcnWorkflowRecord;
use App\Models\User;
use App\Services\EcnQuantityCalculationService;
use App\Services\HierarchyService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class EcnDataReconciliationAndBranchGovernanceTest extends TestCase
{
    protected function getAdminUser(): User
    {
        return User::firstOrCreate(
            ['email' => 'admin@faithautomation.com'],
            [
                'name' => 'Admin User',
                'password' => bcrypt('password123'),
                'role' => 'ADMIN',
            ]
        );
    }

    /**
     * Test Parts 1, 2, 6: Store Department ECN reconciliation between Hierarchy API and Website Summary.
     */
    public function test_store_ecn_reconciliation_between_hierarchy_and_website_summary()
    {
        $user = $this->getAdminUser();
        $this->actingAs($user, 'sanctum');

        $code = 'TEST-RECON-' . uniqid();
        $project = Project::create([
            'project_code' => $code,
            'name' => "Reconciliation Project {$code}",
            'status' => 'active',
        ]);

        // Regular BOM item
        $bomItem = BomItem::create([
            'project_id' => $project->id,
            'standard_part_no' => 'REG-100',
            'item_no' => 'R1',
            'jig_no' => 'JIG-A',
            'unit_no' => '01',
        ]);
        BomRequirement::create([
            'bom_item_id' => $bomItem->id,
            'side' => 'LH',
            'required_quantity' => 20,
        ]);

        // 10 ECN requirements total:
        // 4 in STORE (received in store)
        // 2 in QC (sent to QC)
        // 1 in REWORK (rework queued)
        // 1 in ASSEMBLY (assembly queued)
        // 2 in PENDING (not yet received)
        // Total parts = 10, Total received = 8, Total pending = 2
        for ($i = 1; $i <= 10; $i++) {
            $state = match (true) {
                $i <= 4 => 'STORE',
                $i <= 6 => 'QC',
                $i === 7 => 'REWORK',
                $i === 8 => 'ASSEMBLY',
                default => 'PENDING',
            };
            $recQty = $state === 'PENDING' ? 0 : 1;

            $req = EcnRequirement::create([
                'project_id' => $project->id,
                'ecn_number' => 'ECN-999',
                'jig_no' => 'JIG-A',
                'unit_no' => '01',
                'part_no' => "ECN-PART-{$i}",
                'side' => 'LH',
                'required_qty' => 1,
                'received_qty' => $recQty,
                'current_state' => $state,
            ]);

            if ($recQty > 0) {
                $status = match ($state) {
                    'STORE' => 'received',
                    'QC' => 'qc_received',
                    'REWORK' => 'qc_rework',
                    'ASSEMBLY' => 'qc_approved',
                    default => 'received',
                };
                $receiptItem = EcnReceiptItem::create([
                    'ecn_requirement_id' => $req->id,
                    'project_id' => $project->id,
                    'ecn_number' => 'ECN-999',
                    'side' => 'LH',
                    'side_display' => 'LH',
                    'received_quantity' => 1,
                    'status' => $status,
                ]);

                if ($state === 'REWORK') {
                    EcnWorkflowRecord::create([
                        'ecn_receipt_item_id' => $receiptItem->id,
                        'ecn_requirement_id' => $req->id,
                        'project_id' => $project->id,
                        'ecn_number' => 'ECN-999',
                        'department' => 'REWORK',
                        'action' => 'rework_queued',
                        'side' => 'LH',
                        'quantity' => 1,
                        'status' => 'in_progress',
                    ]);
                } elseif ($state === 'ASSEMBLY') {
                    EcnWorkflowRecord::create([
                        'ecn_receipt_item_id' => $receiptItem->id,
                        'ecn_requirement_id' => $req->id,
                        'project_id' => $project->id,
                        'ecn_number' => 'ECN-999',
                        'department' => 'ASSEMBLY',
                        'action' => 'assembly_queued',
                        'side' => 'LH',
                        'quantity' => 1,
                        'status' => 'in_progress',
                    ]);
                }
            }
        }

        $service = new EcnQuantityCalculationService();
        $summary = $service->calculateEcnDashboardSummary(['project_id' => $project->id]);

        // Website Summary assertions
        $this->assertEquals(10, $summary['total_parts']);
        $this->assertEquals(8, $summary['total_received']);
        $this->assertEquals(2, $summary['parts_pending']);
        $this->assertEquals(4, $summary['parts_in_store']);
        $this->assertEquals(2, $summary['parts_in_qc']);
        $this->assertEquals(1, $summary['parts_in_rework']);
        $this->assertEquals(0, $summary['parts_in_paint']);
        $this->assertEquals(1, $summary['parts_in_assembly']);

        // Hierarchy API calls for each department
        $hierarchyService = new HierarchyService();

        // 1. Store Hierarchy API: Shows Pending Intake (2 parts pending)
        $storeHierarchy = $hierarchyService->getDepartmentHierarchy('store', $project->id);
        $this->assertEquals(2, $storeHierarchy['project']['ecn_parts']);
        $this->assertEquals('ECN (2 parts)', $storeHierarchy['project']['ecn_number_display']);
        $this->assertEquals(2, $storeHierarchy['jigs'][0]['ecn_parts']);
        $this->assertEquals('ECN (2 parts)', $storeHierarchy['jigs'][0]['ecn_number_display']);
        $this->assertEquals(2, $storeHierarchy['jigs'][0]['units'][0]['ecn_parts']);
        $this->assertEquals('ECN (2 parts)', $storeHierarchy['jigs'][0]['units'][0]['ecn_number_display']);

        // 2. QC Hierarchy API
        $qcHierarchy = $hierarchyService->getDepartmentHierarchy('qc', $project->id);
        $this->assertEquals(2, $qcHierarchy['project']['ecn_parts']);
        $this->assertEquals('ECN (2 parts)', $qcHierarchy['project']['ecn_number_display']);
        $this->assertEquals(2, $qcHierarchy['jigs'][0]['ecn_parts']);
        $this->assertEquals(2, $qcHierarchy['jigs'][0]['units'][0]['ecn_parts']);

        // 3. Rework Hierarchy API
        $reworkHierarchy = $hierarchyService->getDepartmentHierarchy('rework', $project->id);
        $this->assertEquals(1, $reworkHierarchy['project']['ecn_parts']);
        $this->assertEquals('ECN (1 part)', $reworkHierarchy['project']['ecn_number_display']);
        $this->assertEquals(1, $reworkHierarchy['jigs'][0]['ecn_parts']);
        $this->assertEquals(1, $reworkHierarchy['jigs'][0]['units'][0]['ecn_parts']);

        // 4. Paint Hierarchy API
        $paintHierarchy = $hierarchyService->getDepartmentHierarchy('paint', $project->id);
        $this->assertEquals(0, $paintHierarchy['project']['ecn_parts']);
        $this->assertNull($paintHierarchy['project']['ecn_number_display']);

        // 5. Assembly Hierarchy API
        $asmHierarchy = $hierarchyService->getDepartmentHierarchy('assembly', $project->id);
        $this->assertEquals(1, $asmHierarchy['project']['ecn_parts']);
        $this->assertEquals('ECN (1 part)', $asmHierarchy['project']['ecn_number_display']);
        $this->assertEquals(1, $asmHierarchy['jigs'][0]['ecn_parts']);
        $this->assertEquals(1, $asmHierarchy['jigs'][0]['units'][0]['ecn_parts']);

        // 6. Manager Overview Hierarchy API (Total ECN parts)
        $mgrHierarchy = $hierarchyService->getDepartmentHierarchy('manager', $project->id);
        $this->assertEquals(10, $mgrHierarchy['project']['ecn_parts']);
        $this->assertEquals('ECN (10 parts)', $mgrHierarchy['project']['ecn_number_display']);
        $this->assertEquals(10, $mgrHierarchy['jigs'][0]['ecn_parts']);
        $this->assertEquals(10, $mgrHierarchy['jigs'][0]['units'][0]['ecn_parts']);
    }

    /**
     * Test Parts 3, 4, 11: QC Project = SUM(Jig) = SUM(Unit) and Project Card ECN indicator presence.
     */
    public function test_qc_project_jig_unit_exact_reconciliation_and_visibility()
    {
        $user = $this->getAdminUser();
        $this->actingAs($user, 'sanctum');

        $code = 'TEST-QC-RECON-' . uniqid();
        $project = Project::create([
            'project_code' => $code,
            'name' => "QC Reconciliation Project {$code}",
            'status' => 'active',
        ]);

        // Regular BOM across 2 Jigs, 2 Units each
        foreach (['JIG-1', 'JIG-2'] as $jigNo) {
            foreach (['01', '02'] as $unitNo) {
                $bItem = BomItem::create([
                    'project_id' => $project->id,
                    'standard_part_no' => "REG-{$jigNo}-{$unitNo}",
                    'item_no' => "R-{$jigNo}-{$unitNo}",
                    'jig_no' => $jigNo,
                    'unit_no' => $unitNo,
                ]);
                BomRequirement::create([
                    'bom_item_id' => $bItem->id,
                    'side' => 'LH',
                    'required_quantity' => 10,
                ]);
            }
        }

        // Case A: 0 ECN parts in QC -> Project card, Jig cards, Unit cards all have ecn_parts = 0
        $hierarchyService = new HierarchyService();
        $hZero = $hierarchyService->getDepartmentHierarchy('qc', $project->id);
        $this->assertEquals(0, $hZero['project']['ecn_parts']);
        $this->assertNull($hZero['project']['ecn_number_display']);
        foreach ($hZero['jigs'] as $j) {
            $this->assertEquals(0, $j['ecn_parts']);
            $this->assertNull($j['ecn_number_display']);
            foreach ($j['units'] as $u) {
                $this->assertEquals(0, $u['ecn_parts']);
                $this->assertNull($u['ecn_number_display']);
            }
        }

        // Case B: Add ECN parts in QC:
        // JIG-1: Unit 01 has 3 parts in QC, Unit 02 has 2 parts in QC (Total JIG-1 = 5)
        // JIG-2: Unit 01 has 1 part in QC, Unit 02 has 0 parts in QC (Total JIG-2 = 1)
        // Total Project = 5 + 1 = 6 parts in QC
        $reqsData = [
            ['jig' => 'JIG-1', 'unit' => '01', 'qty' => 3],
            ['jig' => 'JIG-1', 'unit' => '02', 'qty' => 2],
            ['jig' => 'JIG-2', 'unit' => '01', 'qty' => 1],
        ];

        foreach ($reqsData as $idx => $rd) {
            for ($k = 1; $k <= $rd['qty']; $k++) {
                $req = EcnRequirement::create([
                    'project_id' => $project->id,
                    'ecn_number' => "ECN-{$idx}",
                    'jig_no' => $rd['jig'],
                    'unit_no' => $rd['unit'],
                    'part_no' => "ECN-P-{$rd['jig']}-{$rd['unit']}-{$k}",
                    'side' => 'LH',
                    'required_qty' => 1,
                    'received_qty' => 1,
                    'current_state' => 'QC',
                ]);
                EcnReceiptItem::create([
                    'ecn_requirement_id' => $req->id,
                    'project_id' => $project->id,
                    'ecn_number' => "ECN-{$idx}",
                    'side' => 'LH',
                    'received_quantity' => 1,
                    'status' => 'qc_received',
                ]);
            }
        }

        // Query QC hierarchy
        $hQc = $hierarchyService->getDepartmentHierarchy('qc', $project->id);

        // Project ECN
        $this->assertEquals(6, $hQc['project']['ecn_parts']);
        $this->assertEquals('ECN (6 parts)', $hQc['project']['ecn_number_display']);

        // Check Jigs
        $jig1 = collect($hQc['jigs'])->firstWhere('jig_name', 'JIG-1');
        $jig2 = collect($hQc['jigs'])->firstWhere('jig_name', 'JIG-2');

        $this->assertNotNull($jig1);
        $this->assertNotNull($jig2);

        $this->assertEquals(5, $jig1['ecn_parts']);
        $this->assertEquals('ECN (5 parts)', $jig1['ecn_number_display']);

        $this->assertEquals(1, $jig2['ecn_parts']);
        $this->assertEquals('ECN (1 part)', $jig2['ecn_number_display']);

        // Check Units in JIG-1
        $j1u1 = collect($jig1['units'])->firstWhere('unit_no', 'Unit 01');
        $j1u2 = collect($jig1['units'])->firstWhere('unit_no', 'Unit 02');
        $this->assertEquals(3, $j1u1['ecn_parts']);
        $this->assertEquals('ECN (3 parts)', $j1u1['ecn_number_display']);
        $this->assertEquals(2, $j1u2['ecn_parts']);
        $this->assertEquals('ECN (2 parts)', $j1u2['ecn_number_display']);

        // Check Units in JIG-2
        $j2u1 = collect($jig2['units'])->firstWhere('unit_no', 'Unit 01');
        $j2u2 = collect($jig2['units'])->firstWhere('unit_no', 'Unit 02');
        $this->assertEquals(1, $j2u1['ecn_parts']);
        $this->assertEquals('ECN (1 part)', $j2u1['ecn_number_display']);
        $this->assertEquals(0, $j2u2['ecn_parts']);
        $this->assertNull($j2u2['ecn_number_display']);

        // Mathematical invariant assertions
        $sumJigs = array_sum(array_column($hQc['jigs'], 'ecn_parts'));
        $this->assertEquals($hQc['project']['ecn_parts'], $sumJigs, 'Project ECN must equal sum of Jig ECN counts');

        $sumUnitsJ1 = array_sum(array_column($jig1['units'], 'ecn_parts'));
        $this->assertEquals($jig1['ecn_parts'], $sumUnitsJ1, 'JIG-1 ECN must equal sum of its Unit ECN counts');

        $sumUnitsJ2 = array_sum(array_column($jig2['units'], 'ecn_parts'));
        $this->assertEquals($jig2['ecn_parts'], $sumUnitsJ2, 'JIG-2 ECN must equal sum of its Unit ECN counts');
    }

    /**
     * Test Parts 7, 13, 14: Side Normalization, No Duplicate Counting, Regular BOM isolation.
     */
    public function test_side_normalization_and_no_duplicate_counting()
    {
        $code = 'TEST-SIDE-' . uniqid();
        $project = Project::create([
            'project_code' => $code,
            'name' => "Side Project {$code}",
            'status' => 'active',
        ]);

        // Create 2 ECN requirements with non-standard side formats: 'LA' (LH) and 'RA' (RH)
        $reqL = EcnRequirement::create([
            'project_id' => $project->id,
            'ecn_number' => 'ECN-SIDE',
            'jig_no' => 'JIG-S',
            'unit_no' => '01',
            'part_no' => 'ECN-L1',
            'side' => 'LA',
            'required_qty' => 3,
            'received_qty' => 0,
            'current_state' => 'PENDING',
        ]);

        $reqR = EcnRequirement::create([
            'project_id' => $project->id,
            'ecn_number' => 'ECN-SIDE',
            'jig_no' => 'JIG-S',
            'unit_no' => '01',
            'part_no' => 'ECN-R1',
            'side' => 'RA',
            'required_qty' => 2,
            'received_qty' => 0,
            'current_state' => 'PENDING',
        ]);

        $service = new EcnQuantityCalculationService();
        $map = $service->preloadProjectDepartmentEcnMap($project->id, 'store');

        // Total should be exactly 3 + 2 = 5 (no double counting from alias keys)
        $this->assertEquals(5, $map['project_total']);
        $this->assertEquals(5, $map['jigs']['JIG-S']);
        $this->assertEquals(5, $map['units']['JIG-S|01']);
        $this->assertEquals(3, $map['sides']['JIG-S|01|LH']);
        $this->assertEquals(2, $map['sides']['JIG-S|01|RH']);
    }

    /**
     * Test Part 15: Full Workflow Transitions and State Consistency.
     */
    public function test_full_workflow_reconciliation_across_all_stages()
    {
        $user = $this->getAdminUser();
        $this->actingAs($user, 'sanctum');

        $project = Project::create([
            'name' => 'Full Workflow ECN Project',
            'project_code' => 'PROJ-FULL-' . uniqid(),
            'status' => 'active',
        ]);

        $calc = new EcnQuantityCalculationService();
        $hService = new HierarchyService();

        // 1. Initial Requirement: 5 pcs PENDING
        $ecnReq = EcnRequirement::create([
            'project_id' => $project->id,
            'ecn_number' => 'ECN-FULL',
            'jig_no' => 'JIG-01',
            'unit_no' => '01',
            'part_no' => 'P-FULL',
            'side' => 'LH',
            'side_display' => 'LH',
            'required_qty' => 5,
            'received_qty' => 0,
            'current_state' => 'PENDING',
        ]);

        $s1 = $calc->calculateEcnDashboardSummary(['project_id' => $project->id]);
        $this->assertEquals(5, $s1['parts_pending']);
        $this->assertEquals(0, $s1['parts_in_store']);

        // 2. Store Receive: 5 pcs
        $this->postJson('/api/v1/ecn/store/receive', [
            'ecn_requirement_id' => $ecnReq->id,
            'quantity' => 5,
            'remarks' => 'Initial intake',
        ])->assertStatus(200);

        $s2 = $calc->calculateEcnDashboardSummary(['project_id' => $project->id]);
        $this->assertEquals(0, $s2['parts_pending']);
        $this->assertEquals(5, $s2['parts_in_store']);
        $this->assertEquals(0, $s2['parts_in_qc']);

        $hStore = $hService->getDepartmentHierarchy('store', $project->id);
        $this->assertEquals(0, $hStore['project']['ecn_parts'], 'Store Pending ECN drops to 0 after full receipt');

        // 3. Store Send to QC
        $receiptItem = EcnReceiptItem::where('ecn_requirement_id', $ecnReq->id)->first();
        $this->postJson('/api/v1/ecn/store/send-to-qc', [
            'ecn_requirement_id' => $ecnReq->id,
            'ecn_receipt_item_id' => $receiptItem->id,
            'quantity' => 5,
        ])->assertStatus(200);

        $s3 = $calc->calculateEcnDashboardSummary(['project_id' => $project->id]);
        $this->assertEquals(0, $s3['parts_in_store']);
        $this->assertEquals(5, $s3['parts_in_qc']);

        $hStoreAfterSend = $hService->getDepartmentHierarchy('store', $project->id);
        $this->assertEquals(0, $hStoreAfterSend['project']['ecn_parts']);

        $hQcAfterSend = $hService->getDepartmentHierarchy('qc', $project->id);
        $this->assertEquals(5, $hQcAfterSend['project']['ecn_parts']);
        $this->assertEquals('ECN (5 parts)', $hQcAfterSend['project']['ecn_number_display']);

        // 4. QC Inspection: Split 2 Approved (Assembly), 2 Rework, 1 Rejected (Purchase)
        $this->postJson('/api/v1/ecn/qc/inspect', [
            'ecn_receipt_item_id' => $receiptItem->id,
            'approved_quantity' => 2,
            'destination' => 'ASSEMBLY',
            'rework_quantity' => 2,
            'rejected_quantity' => 1,
            'remarks' => 'Split inspection',
        ])->assertStatus(200);

        $s4 = $calc->calculateEcnDashboardSummary(['project_id' => $project->id]);
        $this->assertEquals(2, $s4['parts_in_assembly']);
        $this->assertEquals(2, $s4['parts_in_rework']);
        $this->assertEquals(1, $s4['qc_rejected']);

        $hAsm = $hService->getDepartmentHierarchy('assembly', $project->id);
        $this->assertEquals(2, $hAsm['project']['ecn_parts']);

        $hRew = $hService->getDepartmentHierarchy('rework', $project->id);
        $this->assertEquals(2, $hRew['project']['ecn_parts']);

        // 5. Complete Rework: 2 pcs returned to QC
        $reworkRecord = EcnWorkflowRecord::where('ecn_requirement_id', $ecnReq->id)
            ->where('department', 'REWORK')
            ->where('status', 'in_progress')
            ->first();

        $this->postJson('/api/v1/ecn/rework/complete', [
            'ecn_workflow_record_id' => $reworkRecord->id,
            'quantity' => 2,
        ])->assertStatus(200);

        $s5 = $calc->calculateEcnDashboardSummary(['project_id' => $project->id]);
        $this->assertEquals(0, $s5['parts_in_rework']);

        // 6. Complete Assembly: 2 pcs
        $asmRecord = EcnWorkflowRecord::where('ecn_requirement_id', $ecnReq->id)
            ->where('department', 'ASSEMBLY')
            ->where('status', 'in_progress')
            ->first();

        $this->postJson('/api/v1/ecn/assembly/complete', [
            'ecn_workflow_record_id' => $asmRecord->id,
            'quantity' => 2,
        ])->assertStatus(200);

        $s6 = $calc->calculateEcnDashboardSummary(['project_id' => $project->id]);
        $this->assertEquals(2, $s6['assembly_completed']);
    }
}
