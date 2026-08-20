<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\BomItem;
use App\Models\BomRequirement;
use App\Models\Receipt;
use App\Models\ReceiptItem;
use App\Models\Supplier;
use App\Models\User;
use App\Services\QuantityCalculationService;
use App\Services\HierarchyService;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class QuantityCalculationHierarchyTest extends TestCase
{
    protected QuantityCalculationService $quantityService;
    protected HierarchyService $hierarchyService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->quantityService = new QuantityCalculationService();
        $this->hierarchyService = new HierarchyService($this->quantityService);
    }

    protected function getAdminUser(): User
    {
        $role = Role::firstOrCreate(['name' => 'ADMIN', 'guard_name' => 'web']);

        $user = User::where('email', 'admin@sparetrack.internal')->first();
        if (!$user) {
            $user = User::first();
        }
        if (!$user) {
            $user = User::create([
                'name' => 'Admin Test',
                'email' => 'admin@sparetrack.internal',
                'password' => bcrypt('password'),
            ]);
        }
        if (!$user->hasRole('ADMIN')) {
            $user->assignRole($role);
        }
        return $user;
    }

    /**
     * Test mathematical invariants across Project, Jig, Unit, and Part levels.
     */
    public function test_hierarchy_mathematical_invariants_hold_strictly(): void
    {
        $admin = $this->getAdminUser();
        $this->actingAs($admin, 'sanctum');

        $project = Project::create([
            'name' => 'Project-Invariant-Test-' . uniqid(),
            'project_code' => 'PIT-' . rand(1000, 9999),
            'status' => 'active',
            'created_by' => $admin->id,
        ]);

        $supplier = Supplier::firstOrCreate(['name' => 'Test Supplier Canonical'], [
            'code' => 'TSC-' . rand(100, 999),
            'is_active' => true,
        ]);

        // Create 2 Jigs, each with 2 Units, each with 2 Parts (RH & LH)
        $jigs = ['JIG_A', 'JIG_B'];
        $units = ['Unit 01', 'Unit 02'];

        $receipt = Receipt::create([
            'project_id' => $project->id,
            'received_by' => $admin->id,
        ]);

        $expectedProjectReq = 0;
        $expectedProjectRec = 0;

        foreach ($jigs as $jigNo) {
            foreach ($units as $unitNo) {
                // Create Item 1: RH (req: 10, rec: 8)
                $item1 = BomItem::create([
                    'project_id' => $project->id,
                    'supplier_id' => $supplier->id,
                    'standard_part_no' => "PART-{$jigNo}-{$unitNo}-RH-" . uniqid(),
                    'jig_no' => $jigNo,
                    'unit_no' => $unitNo,
                ]);
                BomRequirement::create([
                    'bom_item_id' => $item1->id,
                    'side' => 'RH',
                    'required_quantity' => 10,
                ]);
                ReceiptItem::create([
                    'receipt_id' => $receipt->id,
                    'bom_item_id' => $item1->id,
                    'side' => 'RH',
                    'received_quantity' => 8,
                    'status' => 'received',
                ]);
                $expectedProjectReq += 10;
                $expectedProjectRec += 8;

                // Create Item 2: LH (req: 5, rec: 5)
                $item2 = BomItem::create([
                    'project_id' => $project->id,
                    'supplier_id' => $supplier->id,
                    'standard_part_no' => "PART-{$jigNo}-{$unitNo}-LH-" . uniqid(),
                    'jig_no' => $jigNo,
                    'unit_no' => $unitNo,
                ]);
                BomRequirement::create([
                    'bom_item_id' => $item2->id,
                    'side' => 'LH',
                    'required_quantity' => 5,
                ]);
                ReceiptItem::create([
                    'receipt_id' => $receipt->id,
                    'bom_item_id' => $item2->id,
                    'side' => 'LH',
                    'received_quantity' => 5,
                    'status' => 'received',
                ]);
                $expectedProjectReq += 5;
                $expectedProjectRec += 5;
            }
        }

        // Total created: 4 units * (10 + 5) = 60 required, 4 units * (8 + 5) = 52 received, 8 pending
        $this->assertEquals(60, $expectedProjectReq);
        $this->assertEquals(52, $expectedProjectRec);

        // 1. Check Canonical Service Project Metrics
        $pMetrics = $this->quantityService->calculateProjectMetrics($project);
        $this->assertEquals(60, $pMetrics['required_qty']);
        $this->assertEquals(52, $pMetrics['received_qty']);
        $this->assertEquals(8, $pMetrics['pending_qty']);
        $this->assertEquals(86.7, $pMetrics['completion_pct']); // 52 / 60 = 86.666% -> 86.7%

        // 2. Check Department Hierarchy Tree
        $tree = $this->hierarchyService->getDepartmentHierarchy('store', $project->id);
        $this->assertTrue($tree['is_hierarchical']);
        $this->assertCount(2, $tree['jigs']);

        $sumJigReq = 0;
        $sumJigRec = 0;
        $sumJigPending = 0;

        foreach ($tree['jigs'] as $jig) {
            $sumJigReq += $jig['total_required'];
            $sumJigRec += $jig['total_received'];

            $sumUnitReq = 0;
            $sumUnitRec = 0;
            $sumUnitPending = 0;

            foreach ($jig['units'] as $unit) {
                $sumUnitReq += $unit['total_required'];
                $sumUnitRec += $unit['total_received'];
                $sumUnitPending += $unit['pending_quantity'];

                // Unit Invariant: Unit Pending = max(0, Unit Req - Unit Rec)
                $this->assertEquals($unit['pending_quantity'], max(0, $unit['total_required'] - $unit['total_received']));
            }

            // Jig Invariant: Jig Totals == Sum(Unit Totals)
            $this->assertEquals($jig['total_required'], $sumUnitReq);
            $this->assertEquals($jig['total_received'], $sumUnitRec);

            $sumJigPending += $sumUnitPending;
        }

        // Project Invariant: Project Totals == Sum(Jig Totals)
        $this->assertEquals($pMetrics['required_qty'], $sumJigReq);
        $this->assertEquals($pMetrics['received_qty'], $sumJigRec);
        $this->assertEquals($pMetrics['pending_qty'], $sumJigPending);

        // 3. Check Dashboard Summary API (v1)
        $response = $this->actingAs($admin, 'sanctum')->getJson('/api/v1/dashboard/summary?project_id=' . $project->id);
        $response->assertStatus(200);
        $response->assertJson([
            'summary' => [
                'total_required' => 60,
                'total_received' => 52,
                'pending_store' => 8,
                'completion_pct' => 86.7,
                'excess_received' => 0,
            ]
        ]);
    }

    /**
     * Test exact FA-279 calculation mismatch scenario:
     * Required = 487, Valid Received = 476, Pending = 11, Completion = 97.7%,
     * with 30 units of Over-receipt on an item (Total raw receipts = 506).
     * Verify that Project Received, Jig Received, and Dashboard KPI all show 476 received, 11 pending, 97.7% completion.
     */
    public function test_fa279_scenario_handles_over_receipt_correctly_and_unifies_all_hierarchy_levels(): void
    {
        $admin = $this->getAdminUser();
        $this->actingAs($admin, 'sanctum');

        $project = Project::create([
            'name' => 'FA-279-Test-' . uniqid(),
            'project_code' => 'FA-279-' . uniqid(),
            'status' => 'active',
            'created_by' => $admin->id,
        ]);

        $supplier = Supplier::firstOrCreate(['name' => 'Test Supplier FA279'], [
            'code' => 'TSF-' . rand(100, 999),
            'is_active' => true,
        ]);

        // Part 1: Main bulk of project (Req: 476, Rec: 476)
        $item1 = BomItem::create([
            'project_id' => $project->id,
            'supplier_id' => $supplier->id,
            'standard_part_no' => 'COMPLETED-PART-SET-' . uniqid(),
            'jig_no' => '169961@',
            'unit_no' => 'Unit 01',
        ]);
        BomRequirement::create([
            'bom_item_id' => $item1->id,
            'side' => 'COMMON',
            'required_quantity' => 476,
        ]);

        // Part 2: Pending Part (Req: 11, Rec: 0) -> Total required = 476 + 11 = 487
        $item2 = BomItem::create([
            'project_id' => $project->id,
            'supplier_id' => $supplier->id,
            'standard_part_no' => 'PENDING-PART-SET-' . uniqid(),
            'jig_no' => '169961@',
            'unit_no' => 'Unit 02',
        ]);
        BomRequirement::create([
            'bom_item_id' => $item2->id,
            'side' => 'COMMON',
            'required_quantity' => 11,
        ]);

        $receipt = Receipt::create([
            'project_id' => $project->id,
            'received_by' => $admin->id,
        ]);

        // Receipt 1: Exactly 476 units for Part 1
        ReceiptItem::create([
            'receipt_id' => $receipt->id,
            'bom_item_id' => $item1->id,
            'side' => 'COMMON',
            'received_quantity' => 476,
            'status' => 'received',
        ]);

        // Receipt 2: Over-receipt / duplicate delivery of +30 units on Part 1 (Raw total = 476 + 30 = 506)
        ReceiptItem::create([
            'receipt_id' => $receipt->id,
            'bom_item_id' => $item1->id,
            'side' => 'COMMON',
            'received_quantity' => 30,
            'status' => 'received',
        ]);

        // 1. Check Canonical Service Calculation
        $pMetrics = $this->quantityService->calculateProjectMetrics($project);
        $this->assertEquals(487, $pMetrics['required_qty']);
        $this->assertEquals(476, $pMetrics['received_qty'], 'Project Received must be 476, capped to required quantity');
        $this->assertEquals(506, $pMetrics['raw_received'], 'Raw Received tracks the physical 506 count');
        $this->assertEquals(30, $pMetrics['excess_received'], 'Excess Received must clearly expose the 30 over-receipt');
        $this->assertEquals(11, $pMetrics['pending_qty'], 'Pending must equal 11');
        $this->assertEquals(97.7, $pMetrics['completion_pct'], 'Completion must be 97.7% (476/487)');

        // 2. Check Jig Hierarchy
        $tree = $this->hierarchyService->getDepartmentHierarchy('store', $project->id);
        $this->assertCount(1, $tree['jigs']);
        $jig = $tree['jigs'][0];
        $this->assertEquals('169961@', $jig['jig_name']);
        $this->assertEquals(487, $jig['total_required']);
        $this->assertEquals(476, $jig['total_received'], 'Jig Received must equal 476');
        $this->assertEquals(97.7, $jig['completion_pct'], 'Jig Completion must equal 97.7%');

        // 3. Check Dashboard Summary API (v1)
        $response = $this->actingAs($admin, 'sanctum')->getJson('/api/v1/dashboard/summary?project_id=' . $project->id);
        $response->assertStatus(200);
        $response->assertJson([
            'summary' => [
                'total_required' => 487,
                'total_received' => 476,
                'pending_store' => 11,
                'excess_received' => 30,
                'completion_pct' => 97.7,
            ]
        ]);

        // 4. Check Projects Progress Table
        $projProgress = collect($response->json('projects_progress'))->firstWhere('id', $project->id);
        $this->assertNotNull($projProgress);
        $this->assertEquals(487, $projProgress['required_qty']);
        $this->assertEquals(476, $projProgress['received_qty']);
        $this->assertEquals(11, $projProgress['pending_qty']);
        $this->assertEquals(97.7, $projProgress['progress_percent']);
    }

    /**
     * Test that side isolation (RH, LH, COMMON) is strictly maintained.
     */
    public function test_side_isolation_between_rh_and_lh_in_quantity_calculations(): void
    {
        $admin = $this->getAdminUser();
        $this->actingAs($admin, 'sanctum');

        $project = Project::create([
            'name' => 'Side-Isolation-Test-' . uniqid(),
            'project_code' => 'SIT-' . rand(1000, 9999),
            'status' => 'active',
            'created_by' => $admin->id,
        ]);

        $item = BomItem::create([
            'project_id' => $project->id,
            'standard_part_no' => 'DUAL-SIDE-BRACKET-' . uniqid(),
            'jig_no' => 'JIG-01',
            'unit_no' => 'Unit 01',
        ]);

        BomRequirement::create([
            'bom_item_id' => $item->id,
            'side' => 'RH',
            'required_quantity' => 10,
        ]);
        BomRequirement::create([
            'bom_item_id' => $item->id,
            'side' => 'LH',
            'required_quantity' => 20,
        ]);

        $receipt = Receipt::create([
            'project_id' => $project->id,
            'received_by' => $admin->id,
        ]);

        // Receive 10 units for RH only
        ReceiptItem::create([
            'receipt_id' => $receipt->id,
            'bom_item_id' => $item->id,
            'side' => 'RH',
            'received_quantity' => 10,
            'status' => 'received',
        ]);

        // Query RH metrics only
        $rhMetrics = $this->quantityService->calculateProjectMetrics($project, 'RH');
        $this->assertEquals(10, $rhMetrics['required_qty']);
        $this->assertEquals(10, $rhMetrics['received_qty']);
        $this->assertEquals(0, $rhMetrics['pending_qty']);
        $this->assertEquals(100.0, $rhMetrics['completion_pct']);

        // Query LH metrics only
        $lhMetrics = $this->quantityService->calculateProjectMetrics($project, 'LH');
        $this->assertEquals(20, $lhMetrics['required_qty']);
        $this->assertEquals(0, $lhMetrics['received_qty']);
        $this->assertEquals(20, $lhMetrics['pending_qty']);
        $this->assertEquals(0.0, $lhMetrics['completion_pct']);

        // Combined Project metrics
        $allMetrics = $this->quantityService->calculateProjectMetrics($project);
        $this->assertEquals(30, $allMetrics['required_qty']);
        $this->assertEquals(10, $allMetrics['received_qty']);
        $this->assertEquals(20, $allMetrics['pending_qty']);
        $this->assertEquals(33.3, $allMetrics['completion_pct']);
    }

    /**
     * Test that invalid receipt statuses (reverted, returned_to_vendor, scrapped) are not counted.
     */
    public function test_reverted_scrapped_and_returned_receipts_are_excluded_from_received(): void
    {
        $admin = $this->getAdminUser();
        $this->actingAs($admin, 'sanctum');

        $project = Project::create([
            'name' => 'Status-Filter-Test-' . uniqid(),
            'project_code' => 'SFT-' . rand(1000, 9999),
            'status' => 'active',
            'created_by' => $admin->id,
        ]);

        $item = BomItem::create([
            'project_id' => $project->id,
            'standard_part_no' => 'STATUS-TEST-PART-' . uniqid(),
        ]);

        BomRequirement::create([
            'bom_item_id' => $item->id,
            'side' => 'COMMON',
            'required_quantity' => 50,
        ]);

        $receipt = Receipt::create([
            'project_id' => $project->id,
            'received_by' => $admin->id,
        ]);

        // Valid receipt: 20 units
        ReceiptItem::create([
            'receipt_id' => $receipt->id,
            'bom_item_id' => $item->id,
            'side' => 'COMMON',
            'received_quantity' => 20,
            'status' => 'received',
        ]);

        // Reverted receipt: 10 units (should be ignored)
        ReceiptItem::create([
            'receipt_id' => $receipt->id,
            'bom_item_id' => $item->id,
            'side' => 'COMMON',
            'received_quantity' => 10,
            'status' => 'reverted',
        ]);

        // Scrapped receipt: 5 units (should be ignored)
        ReceiptItem::create([
            'receipt_id' => $receipt->id,
            'bom_item_id' => $item->id,
            'side' => 'COMMON',
            'received_quantity' => 5,
            'status' => 'scrapped',
        ]);

        // Returned to vendor: 8 units (should be ignored)
        ReceiptItem::create([
            'receipt_id' => $receipt->id,
            'bom_item_id' => $item->id,
            'side' => 'COMMON',
            'received_quantity' => 8,
            'status' => 'returned_to_vendor',
        ]);

        $metrics = $this->quantityService->calculateProjectMetrics($project);
        $this->assertEquals(50, $metrics['required_qty']);
        $this->assertEquals(20, $metrics['received_qty'], 'Only the 20 active received units should be counted');
        $this->assertEquals(30, $metrics['pending_qty']);
        $this->assertEquals(40.0, $metrics['completion_pct']);
    }

    /**
     * Test workflow location resident quantities reconciliation invariant:
     * Store + QC + Rework + Paint + Assembly + Assembly_Completed == Total Received.
     */
    public function test_parts_in_location_reconciles_with_total_received(): void
    {
        $admin = $this->getAdminUser();
        $this->actingAs($admin, 'sanctum');

        $project = Project::create([
            'name' => 'Location-Invariant-Test-' . uniqid(),
            'project_code' => 'LIT-' . rand(1000, 9999),
            'status' => 'active',
            'created_by' => $admin->id,
        ]);

        $item = BomItem::create([
            'project_id' => $project->id,
            'standard_part_no' => 'LOCATION-PART-' . uniqid(),
        ]);

        BomRequirement::create([
            'bom_item_id' => $item->id,
            'side' => 'COMMON',
            'required_quantity' => 100,
        ]);

        $receipt = Receipt::create([
            'project_id' => $project->id,
            'received_by' => $admin->id,
        ]);

        // 1. 30 units in Store (status: received)
        ReceiptItem::create([
            'receipt_id' => $receipt->id,
            'bom_item_id' => $item->id,
            'side' => 'COMMON',
            'received_quantity' => 30,
            'status' => 'received',
        ]);

        // 2. 20 units in QC (status: sent_to_qc)
        ReceiptItem::create([
            'receipt_id' => $receipt->id,
            'bom_item_id' => $item->id,
            'side' => 'COMMON',
            'received_quantity' => 20,
            'status' => 'sent_to_qc',
        ]);

        // 3. 10 units in Rework
        $reworkReceipt = ReceiptItem::create([
            'receipt_id' => $receipt->id,
            'bom_item_id' => $item->id,
            'side' => 'COMMON',
            'received_quantity' => 10,
            'status' => 'qc_rework',
        ]);
        $reworkQc = \App\Models\QcInspection::create([
            'bom_item_id' => $item->id,
            'side' => 'COMMON',
            'inspected_quantity' => 10,
            'rework_quantity' => 10,
            'result' => 'rework',
            'inspection_date' => now(),
            'inspected_by' => $admin->id,
        ]);
        \App\Models\ReworkRecord::create([
            'qc_inspection_id' => $reworkQc->id,
            'bom_item_id' => $item->id,
            'side' => 'COMMON',
            'quantity' => 10,
            'status' => 'in_progress',
        ]);

        // 4. 20 units in Paint (QC Approved for Paint)
        ReceiptItem::create([
            'receipt_id' => $receipt->id,
            'bom_item_id' => $item->id,
            'side' => 'COMMON',
            'received_quantity' => 20,
            'status' => 'qc_approved',
        ]);
        \App\Models\QcInspection::create([
            'bom_item_id' => $item->id,
            'side' => 'COMMON',
            'inspected_quantity' => 20,
            'approved_quantity' => 20,
            'destination' => 'PAINT',
            'result' => 'approved',
            'inspection_date' => now(),
            'inspected_by' => $admin->id,
        ]);

        // 5. 10 units in Assembly (Paint completed, ready for assembly)
        ReceiptItem::create([
            'receipt_id' => $receipt->id,
            'bom_item_id' => $item->id,
            'side' => 'COMMON',
            'received_quantity' => 10,
            'status' => 'paint_completed',
        ]);
        $paintQc = \App\Models\QcInspection::create([
            'bom_item_id' => $item->id,
            'side' => 'COMMON',
            'inspected_quantity' => 10,
            'approved_quantity' => 10,
            'destination' => 'PAINT',
            'result' => 'approved',
            'inspection_date' => now(),
            'inspected_by' => $admin->id,
        ]);
        \App\Models\PaintRecord::create([
            'qc_inspection_id' => $paintQc->id,
            'bom_item_id' => $item->id,
            'side' => 'COMMON',
            'quantity' => 10,
            'status' => 'completed',
        ]);

        // 6. 10 units Assembled
        ReceiptItem::create([
            'receipt_id' => $receipt->id,
            'bom_item_id' => $item->id,
            'side' => 'COMMON',
            'received_quantity' => 10,
            'status' => 'assembly_completed',
        ]);
        $asmQc = \App\Models\QcInspection::create([
            'bom_item_id' => $item->id,
            'side' => 'COMMON',
            'inspected_quantity' => 10,
            'approved_quantity' => 10,
            'destination' => 'ASSEMBLY',
            'result' => 'approved',
            'inspection_date' => now(),
            'inspected_by' => $admin->id,
        ]);
        \App\Models\AssemblyRecord::create([
            'qc_inspection_id' => $asmQc->id,
            'bom_item_id' => $item->id,
            'side' => 'COMMON',
            'quantity' => 10,
            'status' => 'completed',
        ]);

        // Total received: 30 + 20 + 10 + 20 + 10 + 10 = 100
        $metrics = $this->quantityService->calculateProjectMetrics($project);

        $this->assertEquals(100, $metrics['required_qty']);
        $this->assertEquals(100, $metrics['received_qty']);
        $this->assertEquals(0, $metrics['pending_qty']);
        $this->assertEquals(30, $metrics['parts_in_store']);
        $this->assertEquals(20, $metrics['parts_in_qc']);
        $this->assertEquals(10, $metrics['parts_in_rework']);
        $this->assertEquals(20, $metrics['parts_in_paint']);
        $this->assertEquals(10, $metrics['parts_in_assembly']);
        $this->assertEquals(10, $metrics['assembly_qty']);

        // Mandatory Reconciliation Invariant
        $reconciledSum = $metrics['parts_in_store'] +
                         $metrics['parts_in_qc'] +
                         $metrics['parts_in_rework'] +
                         $metrics['parts_in_paint'] +
                         $metrics['parts_in_assembly'] +
                         $metrics['assembly_qty'];

        $this->assertEquals($metrics['received_qty'], $reconciledSum);
    }

    /**
     * Test Dashboard API returns all 10 Primary Cards, Top Projects, and Health Distribution.
     */
    public function test_dashboard_api_returns_all_primary_cards_top_projects_and_health_distribution(): void
    {
        $admin = $this->getAdminUser();
        $this->actingAs($admin, 'sanctum');

        $response = $this->getJson('/api/v1/dashboard/summary');
        $response->assertStatus(200);

        // Assert all 10 primary card metrics are present
        $response->assertJsonStructure([
            'summary' => [
                'total_projects',
                'completed_projects',
                'total_required',
                'total_received',
                'total_pending',
                'parts_in_store',
                'parts_in_qc',
                'parts_in_rework',
                'parts_in_paint',
                'parts_in_assembly',
                'completion_pct',
            ],
            'top_projects' => [
                'labels',
                'names',
                'percentages',
                'required',
                'received',
                'pending',
            ],
            'health_distribution' => [
                'counts' => [
                    'near_completion',
                    'on_track',
                    'at_risk',
                    'delayed',
                ],
                'percentages',
                'total_active',
            ]
        ]);
    }
}

