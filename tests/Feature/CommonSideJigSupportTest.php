<?php

namespace Tests\Feature;

use App\Models\AssemblyRecord;
use App\Models\BomItem;
use App\Models\BomRequirement;
use App\Models\PaintRecord;
use App\Models\Project;
use App\Models\QcInspection;
use App\Models\Receipt;
use App\Models\ReceiptItem;
use App\Models\ReworkRecord;
use App\Models\Supplier;
use App\Models\User;
use App\Services\BomImportService;
use App\Services\HierarchyService;
use App\Services\KpiDrilldownService;
use App\Services\QuantityCalculationService;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class CommonSideJigSupportTest extends TestCase
{
    protected QuantityCalculationService $quantityService;
    protected HierarchyService $hierarchyService;
    protected KpiDrilldownService $drilldownService;
    protected BomImportService $bomImportService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->quantityService = new QuantityCalculationService();
        $this->hierarchyService = new HierarchyService($this->quantityService);
        $this->drilldownService = new KpiDrilldownService($this->quantityService);
        $this->bomImportService = app(BomImportService::class);
    }

    protected function getAdminUser(): User
    {
        $role = Role::firstOrCreate(['name' => 'ADMIN', 'guard_name' => 'web']);

        $user = User::where('email', 'admin@sparetrack.internal')->first();
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
     * Test 1: BOM Requirement creation and side normalization for COMMON
     */
    public function test_bom_import_normalizes_blank_and_common_sides_to_common(): void
    {
        $admin = $this->getAdminUser();
        $this->actingAs($admin, 'sanctum');

        $project = Project::create([
            'name' => 'Test Project Common Normalization ' . uniqid(),
            'project_code' => 'TPCN-' . rand(1000, 9999),
            'status' => 'active',
            'created_by' => $admin->id,
        ]);

        $supplier = Supplier::firstOrCreate(['name' => 'Test Supplier Canonical'], [
            'code' => 'TSC-' . rand(100, 999),
            'is_active' => true,
        ]);

        // Create item with COMMON side requirement
        $bomItem = BomItem::create([
            'project_id' => $project->id,
            'supplier_id' => $supplier->id,
            'standard_part_no' => 'COMMON-PART-' . uniqid(),
            'jig_no' => 'JIG_COMMON_01',
            'unit_no' => 'Unit 01',
        ]);

        $req = BomRequirement::create([
            'bom_item_id' => $bomItem->id,
            'side' => 'COMMON',
            'required_quantity' => 12,
        ]);

        $this->assertEquals('COMMON', $req->side);
        $this->assertEquals(12, $req->required_quantity);
    }

    /**
     * Test 2: Hierarchy Service builds single 'COMMON' branch without LH/RH duplication
     */
    public function test_hierarchy_service_builds_single_common_branch_without_duplication(): void
    {
        $admin = $this->getAdminUser();
        $this->actingAs($admin, 'sanctum');

        $project = Project::create([
            'name' => 'Project Common Hierarchy Test ' . uniqid(),
            'project_code' => 'PCHT-' . rand(1000, 9999),
            'status' => 'active',
            'created_by' => $admin->id,
        ]);

        $supplier = Supplier::firstOrCreate(['name' => 'Test Supplier Canonical'], [
            'code' => 'TSC-' . rand(100, 999),
            'is_active' => true,
        ]);

        // Create Common Jig parts
        $item1 = BomItem::create([
            'project_id' => $project->id,
            'supplier_id' => $supplier->id,
            'standard_part_no' => 'PART-COM-1-' . uniqid(),
            'jig_no' => 'JIG_COMM',
            'unit_no' => 'Unit 01',
        ]);
        BomRequirement::create([
            'bom_item_id' => $item1->id,
            'side' => 'COMMON',
            'required_quantity' => 5,
        ]);

        $item2 = BomItem::create([
            'project_id' => $project->id,
            'supplier_id' => $supplier->id,
            'standard_part_no' => 'PART-COM-2-' . uniqid(),
            'jig_no' => 'JIG_COMM',
            'unit_no' => 'Unit 01',
        ]);
        BomRequirement::create([
            'bom_item_id' => $item2->id,
            'side' => 'COMMON',
            'required_quantity' => 7,
        ]);

        // Also create a Side-Specific Jig (LH/RH) for comparison
        $item3 = BomItem::create([
            'project_id' => $project->id,
            'supplier_id' => $supplier->id,
            'standard_part_no' => 'PART-SPEC-1-' . uniqid(),
            'jig_no' => 'JIG_SPEC',
            'unit_no' => 'Unit 02',
        ]);
        BomRequirement::create([
            'bom_item_id' => $item3->id,
            'side' => 'LH',
            'required_quantity' => 4,
        ]);
        BomRequirement::create([
            'bom_item_id' => $item3->id,
            'side' => 'RH',
            'required_quantity' => 4,
        ]);

        $hierarchy = $this->hierarchyService->getDepartmentHierarchy('store', $project->id);

        $this->assertCount(2, $hierarchy['jigs']);

        // Find Common Jig
        $commonJig = collect($hierarchy['jigs'])->firstWhere('jig_name', 'JIG_COMM');
        $this->assertNotNull($commonJig);
        $this->assertEquals('COMMON', $commonJig['jig_type']);
        $this->assertEquals(12, $commonJig['total_required']);

        $commonUnit = $commonJig['units'][0];
        $this->assertTrue($commonUnit['has_common']);
        $this->assertFalse($commonUnit['has_lh']);
        $this->assertFalse($commonUnit['has_rh']);

        // Sides array must have ONLY 'COMMON' and NOT 'LH' or 'RH'
        $this->assertArrayHasKey('COMMON', $commonUnit['sides']);
        $this->assertArrayNotHasKey('LH', $commonUnit['sides']);
        $this->assertArrayNotHasKey('RH', $commonUnit['sides']);
        $this->assertEquals(12, $commonUnit['sides']['COMMON']['total_required']);

        // Find Side-Specific Jig
        $specJig = collect($hierarchy['jigs'])->firstWhere('jig_name', 'JIG_SPEC');
        $this->assertNotNull($specJig);
        $this->assertEquals('SIDE_SPECIFIC', $specJig['jig_type']);
        $this->assertEquals(8, $specJig['total_required']);

        $specUnit = $specJig['units'][0];
        $this->assertTrue($specUnit['has_lh']);
        $this->assertTrue($specUnit['has_rh']);
        $this->assertFalse($specUnit['has_common']);
        $this->assertArrayHasKey('LH', $specUnit['sides']);
        $this->assertArrayHasKey('RH', $specUnit['sides']);
        $this->assertArrayNotHasKey('COMMON', $specUnit['sides']);
    }

    /**
     * Test 3: Quantity Calculation Service isolates Common parts from LH/RH side filters
     */
    public function test_quantity_calculation_service_isolates_common_from_lh_rh_filters(): void
    {
        $admin = $this->getAdminUser();
        $this->actingAs($admin, 'sanctum');

        $project = Project::create([
            'name' => 'Project Side Isolation Test ' . uniqid(),
            'project_code' => 'PSIT-' . rand(1000, 9999),
            'status' => 'active',
            'created_by' => $admin->id,
        ]);

        $supplier = Supplier::firstOrCreate(['name' => 'Test Supplier Canonical'], [
            'code' => 'TSC-' . rand(100, 999),
            'is_active' => true,
        ]);

        $itemCommon = BomItem::create([
            'project_id' => $project->id,
            'supplier_id' => $supplier->id,
            'standard_part_no' => 'PART-COM-' . uniqid(),
            'jig_no' => 'JIG_C',
            'unit_no' => 'Unit 01',
        ]);
        BomRequirement::create([
            'bom_item_id' => $itemCommon->id,
            'side' => 'COMMON',
            'required_quantity' => 10,
        ]);

        $itemLhRh = BomItem::create([
            'project_id' => $project->id,
            'supplier_id' => $supplier->id,
            'standard_part_no' => 'PART-LR-' . uniqid(),
            'jig_no' => 'JIG_LR',
            'unit_no' => 'Unit 02',
        ]);
        BomRequirement::create([
            'bom_item_id' => $itemLhRh->id,
            'side' => 'LH',
            'required_quantity' => 6,
        ]);
        BomRequirement::create([
            'bom_item_id' => $itemLhRh->id,
            'side' => 'RH',
            'required_quantity' => 4,
        ]);

        // Summary with no side filter (All sides) -> 10 (common) + 6 (LH) + 4 (RH) = 20
        $allSummary = $this->quantityService->calculateProjectMetrics($project);
        $this->assertEquals(20, $allSummary['required_qty']);

        // Summary with LH filter -> only 6 (LH), Common is NOT absorbed
        $lhSummary = $this->quantityService->calculateProjectMetrics($project, 'LH');
        $this->assertEquals(6, $lhSummary['required_qty']);

        // Summary with RH filter -> only 4 (RH), Common is NOT absorbed
        $rhSummary = $this->quantityService->calculateProjectMetrics($project, 'RH');
        $this->assertEquals(4, $rhSummary['required_qty']);

        // Summary with COMMON filter -> only 10 (COMMON)
        $commonSummary = $this->quantityService->calculateProjectMetrics($project, 'COMMON');
        $this->assertEquals(10, $commonSummary['required_qty']);
    }

    /**
     * Test 4: Full operational workflow across Store -> QC -> Paint -> Assembly for a COMMON part
     */
    public function test_full_operational_workflow_for_common_part(): void
    {
        $admin = $this->getAdminUser();
        $this->actingAs($admin, 'sanctum');

        $project = Project::create([
            'name' => 'Project Full Workflow Common ' . uniqid(),
            'project_code' => 'PFWC-' . rand(1000, 9999),
            'status' => 'active',
            'created_by' => $admin->id,
        ]);

        $supplier = Supplier::firstOrCreate(['name' => 'Test Supplier Canonical'], [
            'code' => 'TSC-' . rand(100, 999),
            'is_active' => true,
        ]);

        $item = BomItem::create([
            'project_id' => $project->id,
            'supplier_id' => $supplier->id,
            'standard_part_no' => 'PART-FLOW-COMMON-' . uniqid(),
            'jig_no' => 'JIG_COMM_01',
            'unit_no' => 'Unit 01',
        ]);
        BomRequirement::create([
            'bom_item_id' => $item->id,
            'side' => 'COMMON',
            'required_quantity' => 10,
        ]);

        // Step 1: Store Receipt (Receive 10 pcs with side = 'COMMON')
        $response = $this->postJson('/api/v1/store/receipts', [
            'project_id' => $project->id,
            'delivery_note_number' => 'DN-COM-001',
            'items' => [
                [
                    'bom_item_id' => $item->id,
                    'side' => 'COMMON',
                    'received_quantity' => 10,
                ]
            ]
        ]);
        $response->assertStatus(200);

        $receiptItem = ReceiptItem::where('bom_item_id', $item->id)->first();
        $this->assertNotNull($receiptItem);
        $this->assertEquals('COMMON', $receiptItem->side);
        $this->assertEquals(10, $receiptItem->received_quantity);

        // Step 2: QC Physical Arrival (Receive 10 pcs)
        $response = $this->postJson('/api/v1/qc/receive', [
            'receipt_item_id' => $receiptItem->id,
            'quantity' => 10,
            'side' => 'COMMON',
        ]);
        $response->assertStatus(200);

        $receiptItem->refresh();
        $this->assertEquals('qc_received', $receiptItem->status);

        // Step 3: QC Inspection (Approve 10 pcs to PAINT with side = 'COMMON')
        $response = $this->postJson('/api/v1/qc/inspect', [
            'receipt_item_id' => $receiptItem->id,
            'result' => 'approved',
            'approved_quantity' => 10,
            'paint_quantity' => 10,
            'assembly_quantity' => 0,
            'rework_quantity' => 0,
            'rejected_quantity' => 0,
            'destination' => 'PAINT',
            'side' => 'COMMON',
        ]);
        $response->assertStatus(200);

        $inspection = QcInspection::where('receipt_item_id', $receiptItem->id)->first();
        $this->assertNotNull($inspection);
        $this->assertEquals('COMMON', $inspection->side);
        $this->assertEquals(10, $inspection->approved_quantity);

        // Step 4: Paint Process (Complete paint for 10 pcs with side = 'COMMON')
        $response = $this->postJson('/api/v1/paint/items', [
            'bom_item_id' => $item->id,
            'qc_inspection_id' => $inspection->id,
            'quantity' => 10,
            'side' => 'COMMON',
        ]);
        $response->assertStatus(200);

        $paintRecord = PaintRecord::where('qc_inspection_id', $inspection->id)->first();
        $this->assertNotNull($paintRecord);
        $this->assertEquals('COMMON', $paintRecord->side);
        $this->assertEquals(10, $paintRecord->quantity);

        // Step 5: Assembly Process (Complete assembly for 10 pcs with side = 'COMMON')
        $response = $this->postJson('/api/v1/assembly/items', [
            'bom_item_id' => $item->id,
            'quantity' => 10,
            'side' => 'COMMON',
        ]);
        $response->assertStatus(200);

        $assemblyRecord = AssemblyRecord::where('bom_item_id', $item->id)->first();
        $this->assertNotNull($assemblyRecord);
        $this->assertEquals('COMMON', $assemblyRecord->side);
        $this->assertEquals(10, $assemblyRecord->quantity);

        // Check project completion
        $projectSummary = $this->quantityService->calculateProjectMetrics($project);
        $this->assertEquals(10, $projectSummary['assembly_completed']);
        $this->assertEquals(100.0, $projectSummary['completion_pct']);
    }

    /**
     * Test 5: Revert workflow for a COMMON part
     */
    public function test_revert_workflow_for_common_part(): void
    {
        $admin = $this->getAdminUser();
        $this->actingAs($admin, 'sanctum');

        $project = Project::create([
            'name' => 'Project Revert Common ' . uniqid(),
            'project_code' => 'PRC-' . rand(1000, 9999),
            'status' => 'active',
            'created_by' => $admin->id,
        ]);

        $supplier = Supplier::firstOrCreate(['name' => 'Test Supplier Canonical'], [
            'code' => 'TSC-' . rand(100, 999),
            'is_active' => true,
        ]);

        $item = BomItem::create([
            'project_id' => $project->id,
            'supplier_id' => $supplier->id,
            'standard_part_no' => 'PART-REV-COM-' . uniqid(),
            'jig_no' => 'JIG_COMM_01',
            'unit_no' => 'Unit 01',
        ]);
        BomRequirement::create([
            'bom_item_id' => $item->id,
            'side' => 'COMMON',
            'required_quantity' => 5,
        ]);

        $receipt = Receipt::create([
            'project_id' => $project->id,
            'received_by' => $admin->id,
        ]);

        $receiptItem = ReceiptItem::create([
            'receipt_id' => $receipt->id,
            'bom_item_id' => $item->id,
            'side' => 'COMMON',
            'received_quantity' => 5,
            'status' => 'received',
        ]);

        // Revert store receipt
        $response = $this->postJson('/api/v1/workflow/revert', [
            'department' => 'store',
            'bom_item_id' => $item->id,
            'record_id' => $receiptItem->id,
            'source_id' => $receiptItem->id,
            'source_type' => 'receipt_item',
            'side' => 'COMMON',
            'quantity' => 5,
            'reason' => 'Defective batch received in store',
        ]);
        $response->assertStatus(200);

        $receiptItem->refresh();
        $this->assertEquals('reverted', $receiptItem->status);
    }

    /**
     * Test 6: KPI Drilldown Service omits side suffix for COMMON parts
     */
    public function test_kpi_drilldown_omits_side_suffix_for_common_parts(): void
    {
        $admin = $this->getAdminUser();
        $this->actingAs($admin, 'sanctum');

        $project = Project::create([
            'name' => 'Project KPI Drilldown Common ' . uniqid(),
            'project_code' => 'PKDC-' . rand(1000, 9999),
            'status' => 'active',
            'created_by' => $admin->id,
        ]);

        $supplier = Supplier::firstOrCreate(['name' => 'Test Supplier Canonical'], [
            'code' => 'TSC-' . rand(100, 999),
            'is_active' => true,
        ]);

        $itemCommon = BomItem::create([
            'project_id' => $project->id,
            'supplier_id' => $supplier->id,
            'standard_part_no' => 'PN-COMMON-100',
            'jig_no' => 'JIG_COMM',
            'unit_no' => 'Unit 01',
        ]);
        BomRequirement::create([
            'bom_item_id' => $itemCommon->id,
            'side' => 'COMMON',
            'required_quantity' => 2,
        ]);

        $itemLh = BomItem::create([
            'project_id' => $project->id,
            'supplier_id' => $supplier->id,
            'standard_part_no' => 'PN-LH-200',
            'jig_no' => 'JIG_SPEC',
            'unit_no' => 'Unit 02',
        ]);
        BomRequirement::create([
            'bom_item_id' => $itemLh->id,
            'side' => 'LH',
            'required_quantity' => 3,
        ]);

        $drilldown = $this->drilldownService->getDrilldownData('total_parts', ['project_id' => $project->id]);

        $rows = $drilldown['data'];
        $commonPart = collect($rows)->firstWhere('part_no', 'PN-COMMON-100');
        $lhPart = collect($rows)->firstWhere('part_no', 'PN-LH-200');

        $this->assertNotNull($commonPart);
        $this->assertNotNull($lhPart);

        // Common part should NOT have L or R appended in excel_part_number
        $this->assertEquals('JIG_COMMUnit 01PN-COMMON-100', $commonPart['excel_part_number']);
        $this->assertNull($commonPart['source_side']);

        // LH part should have L appended
        $this->assertEquals('JIG_SPECUnit 02PN-LH-200L', $lhPart['excel_part_number']);
        $this->assertEquals('LH', $lhPart['source_side']);
    }

    /**
     * Test 7: Reconcile Import Rows detects structural conflict if incoming revision mixes Common with Side-Specific on same Jig
     */
    public function test_reconcile_import_rows_detects_conflict_when_mixing_common_and_side_specific_jig(): void
    {
        $admin = $this->getAdminUser();
        $this->actingAs($admin, 'sanctum');

        $projectCode = 'PCONF-' . rand(1000, 9999);
        $project = Project::create([
            'name' => 'Project Conflict Test ' . uniqid(),
            'project_code' => $projectCode,
            'status' => 'active',
            'created_by' => $admin->id,
        ]);

        $supplier = Supplier::firstOrCreate(['name' => 'Test Supplier Canonical'], [
            'code' => 'TSC-' . rand(100, 999),
            'is_active' => true,
        ]);

        // Existing Jig in database is SIDE_SPECIFIC (has LH/RH)
        $existingItem = BomItem::create([
            'project_id' => $project->id,
            'supplier_id' => $supplier->id,
            'standard_part_no' => 'PART-EXISTING-LH',
            'jig_no' => 'JIG_ST01',
            'unit_no' => 'Unit 01',
        ]);
        BomRequirement::create([
            'bom_item_id' => $existingItem->id,
            'side' => 'LH',
            'required_quantity' => 5,
        ]);

        // Incoming file attempts to provide COMMON parts for JIG_ST01
        $incomingRows = [
            [
                'project_code' => $projectCode,
                'jig_no' => 'JIG_ST01',
                'unit_no' => 'Unit 01',
                'part_no' => 'PART-CONFLICT-COM',
                'side' => 'COMMON',
                'quantity' => 10,
                'supplier_name' => 'Test Supplier Canonical',
                'item_no' => '1',
                'part_description' => 'Conflicting Part',
            ]
        ];

        $reconciliation = $this->bomImportService->reconcileImportRows($incomingRows, 'test_conflict.xlsx');

        $this->assertNotEmpty($reconciliation['conflicts']);
        $conflict = $reconciliation['conflicts'][0];
        $this->assertStringContainsString('Structural Conflict', $conflict['reason']);
        $this->assertStringContainsString('Mixed side models on the same Jig are forbidden', $conflict['reason']);
    }

    /**
     * Test 8: Reconcile Import Rows allows clean common addition to existing project on new Jig
     */
    public function test_reconcile_import_rows_allows_clean_common_addition_to_existing_project(): void
    {
        $admin = $this->getAdminUser();
        $this->actingAs($admin, 'sanctum');

        $projectCode = 'PCLEAN-' . rand(1000, 9999);
        $project = Project::create([
            'name' => 'Project Clean Addition ' . uniqid(),
            'project_code' => $projectCode,
            'status' => 'active',
            'created_by' => $admin->id,
        ]);

        $supplier = Supplier::firstOrCreate(['name' => 'Test Supplier Canonical'], [
            'code' => 'TSC-' . rand(100, 999),
            'is_active' => true,
        ]);

        // Existing Jig in database is JIG_01 (LH/RH)
        $existingItem = BomItem::create([
            'project_id' => $project->id,
            'supplier_id' => $supplier->id,
            'standard_part_no' => 'PART-EXISTING-LH',
            'jig_no' => 'JIG_01',
            'unit_no' => 'Unit 01',
        ]);
        BomRequirement::create([
            'bom_item_id' => $existingItem->id,
            'side' => 'LH',
            'required_quantity' => 5,
        ]);

        // Incoming file provides a NEW JIG_COMMON which is exclusively COMMON
        $incomingRows = [
            [
                'project_code' => $projectCode,
                'jig_no' => 'JIG_COMMON_NEW',
                'unit_no' => 'Unit 01',
                'part_no' => 'PART-CLEAN-COM',
                'side' => 'COMMON',
                'qty' => 10,
                'supplier_name' => 'Test Supplier Canonical',
                'item_no' => '1',
                'part_description' => 'Clean Common Part',
            ]
        ];

        $reconciliation = $this->bomImportService->reconcileImportRows($incomingRows, 'test_clean.xlsx');

        $this->assertEmpty($reconciliation['conflicts']);
        $this->assertEquals(1, $reconciliation['summary']['new_jigs_count']);
        $this->assertEquals(1, $reconciliation['summary']['new_parts_count']);
        $this->assertEquals(1, $reconciliation['summary']['new_requirements_count']);
    }
}
