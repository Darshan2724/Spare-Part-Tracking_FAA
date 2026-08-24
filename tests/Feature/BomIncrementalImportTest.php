<?php

namespace Tests\Feature;

use App\Models\BomImportBatch;
use App\Models\BomItem;
use App\Models\BomRequirement;
use App\Models\Project;
use App\Models\Receipt;
use App\Models\ReceiptItem;
use App\Models\QcInspection;
use App\Models\PaintRecord;
use App\Models\User;
use App\Services\QuantityCalculationService;
use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class BomIncrementalImportTest extends TestCase
{
    protected QuantityCalculationService $quantityService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->quantityService = app(QuantityCalculationService::class);
    }

    protected function getAdminUser(): User
    {
        $role = \Spatie\Permission\Models\Role::firstOrCreate(['name' => 'ADMIN', 'guard_name' => 'web']);
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

    protected function createTestExcelFile(array $rows, string $sheetName = 'BOM'): string
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle($sheetName);

        // Header Row
        $sheet->setCellValue('A1', 'Project Code');
        $sheet->setCellValue('B1', 'Jig No');
        $sheet->setCellValue('C1', 'Unit No');
        $sheet->setCellValue('D1', 'Part No');
        $sheet->setCellValue('E1', 'Side');
        $sheet->setCellValue('F1', 'Qty');

        $rowIdx = 2;
        foreach ($rows as $r) {
            $sheet->setCellValue('A' . $rowIdx, $r['project_code']);
            $sheet->setCellValue('B' . $rowIdx, $r['jig_no']);
            $sheet->setCellValue('C' . $rowIdx, $r['unit_no']);
            $sheet->setCellValue('D' . $rowIdx, $r['part_no']);
            $sheet->setCellValue('E' . $rowIdx, $r['side']);
            $sheet->setCellValue('F' . $rowIdx, $r['qty']);
            $rowIdx++;
        }

        $tempPath = tempnam(sys_get_temp_dir(), 'bom_test_') . '.xlsx';
        $writer = new Xlsx($spreadsheet);
        $writer->save($tempPath);

        return $tempPath;
    }

    public function test_scenario_a_new_project_initial_import()
    {
        $admin = $this->getAdminUser();
        $this->actingAs($admin, 'sanctum');

        $projCode = 'TEST-FA279-' . uniqid();

        $rows = [
            ['project_code' => $projCode, 'jig_no' => 'JIG-01', 'unit_no' => '01', 'part_no' => 'PART-A', 'side' => 'RH', 'qty' => 3],
            ['project_code' => $projCode, 'jig_no' => 'JIG-02', 'unit_no' => '01', 'part_no' => 'PART-B', 'side' => 'LH', 'qty' => 4],
            ['project_code' => $projCode, 'jig_no' => 'JIG-03', 'unit_no' => '02', 'part_no' => 'PART-C', 'side' => 'COMMON', 'qty' => 2],
        ];

        $filePath = $this->createTestExcelFile($rows);
        $file = new UploadedFile($filePath, "{$projCode} NEW MFG BOM.xlsx", 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);

        // 1. Preview
        $previewRes = $this->postJson('/api/v1/bom/preview', ['file' => $file]);
        $previewRes->assertStatus(200);
        $previewRes->assertJson([
            'success' => true,
            'is_revision' => false,
        ]);
        $this->assertEquals(3, $previewRes->json('reconciliation.new_jigs_count'));
        $this->assertEquals(3, $previewRes->json('reconciliation.new_requirements_count'));

        // 2. Import
        $importRes = $this->postJson('/api/v1/bom/import', ['file' => $file]);
        $importRes->assertStatus(200);
        $importRes->assertJson([
            'success' => true,
            'import_type' => 'initial',
        ]);

        $project = Project::where('project_code', $projCode)->first();
        $this->assertNotNull($project);
        $this->assertEquals(3, BomItem::where('project_id', $project->id)->count());
        $this->assertEquals(9, BomRequirement::whereIn('bom_item_id', BomItem::where('project_id', $project->id)->pluck('id'))->sum('required_quantity'));

        @unlink($filePath);
    }

    public function test_scenario_b_missing_jigs_added_later_reuses_project()
    {
        $admin = $this->getAdminUser();
        $this->actingAs($admin, 'sanctum');

        $projCode = 'TEST-FA279-' . uniqid();

        // 1. Initial import with JIG-01, JIG-02, JIG-03
        $initialRows = [
            ['project_code' => $projCode, 'jig_no' => 'JIG-01', 'unit_no' => '01', 'part_no' => 'PART-A', 'side' => 'RH', 'qty' => 3],
            ['project_code' => $projCode, 'jig_no' => 'JIG-02', 'unit_no' => '01', 'part_no' => 'PART-B', 'side' => 'LH', 'qty' => 4],
            ['project_code' => $projCode, 'jig_no' => 'JIG-03', 'unit_no' => '02', 'part_no' => 'PART-C', 'side' => 'COMMON', 'qty' => 2],
        ];

        $filePath1 = $this->createTestExcelFile($initialRows);
        $file1 = new UploadedFile($filePath1, "{$projCode} NEW MFG BOM.xlsx", 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
        $this->postJson('/api/v1/bom/import', ['file' => $file1])->assertStatus(200);
        @unlink($filePath1);

        $project = Project::where('project_code', $projCode)->first();
        $this->assertNotNull($project);
        $initialProjectId = $project->id;

        // 2. Later import with JIG-04 and JIG-05 (filename with (1) suffix)
        $laterRows = [
            ['project_code' => $projCode, 'jig_no' => 'JIG-04', 'unit_no' => '01', 'part_no' => 'PART-D', 'side' => 'RH', 'qty' => 5],
            ['project_code' => $projCode, 'jig_no' => 'JIG-05', 'unit_no' => '01', 'part_no' => 'PART-E', 'side' => 'LH', 'qty' => 2],
        ];

        $filePath2 = $this->createTestExcelFile($laterRows);
        $file2 = new UploadedFile($filePath2, "{$projCode} NEW MFG(1) BOM.xlsx", 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);

        $previewRes = $this->postJson('/api/v1/bom/preview', ['file' => $file2]);
        $previewRes->assertStatus(200);
        $previewRes->assertJson([
            'success' => true,
            'is_revision' => true,
        ]);
        $this->assertEquals(2, $previewRes->json('reconciliation.new_jigs_count'));
        $this->assertEquals(2, $previewRes->json('reconciliation.new_requirements_count'));

        $importRes = $this->postJson('/api/v1/bom/import', ['file' => $file2]);
        $importRes->assertStatus(200);
        $importRes->assertJson([
            'success' => true,
            'import_type' => 'revision',
        ]);

        // Assert only ONE project exists with this code
        $this->assertEquals(1, Project::where('project_code', $projCode)->count());
        $this->assertEquals($initialProjectId, $project->fresh()->id);

        // Assert all 5 JIGs exist
        $jigs = BomItem::where('project_id', $initialProjectId)->distinct('jig_no')->pluck('jig_no')->sort()->values()->toArray();
        $this->assertEquals(['JIG-01', 'JIG-02', 'JIG-03', 'JIG-04', 'JIG-05'], $jigs);

        @unlink($filePath2);
    }

    public function test_scenario_c_exact_duplicate_file_is_blocked()
    {
        $admin = $this->getAdminUser();
        $this->actingAs($admin, 'sanctum');

        $projCode = 'TEST-DUP-' . uniqid();
        $filename = "{$projCode}_BOM.xlsx";
        $rows1 = [
            ['project_code' => $projCode, 'jig_no' => 'JIG-01', 'unit_no' => '01', 'part_no' => 'PART-A', 'side' => 'RH', 'qty' => 3],
        ];

        $filePath1 = $this->createTestExcelFile($rows1);
        $file1 = new UploadedFile($filePath1, $filename, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);

        // 1. First upload succeeds
        $res1 = $this->postJson('/api/v1/bom/import', ['file' => $file1]);
        $res1->assertStatus(200);
        $res1->assertJson(['success' => true]);

        // 2. Exact same file uploaded again -> Rejected
        $file2 = new UploadedFile($filePath1, $filename, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
        $res2 = $this->postJson('/api/v1/bom/preview', ['file' => $file2]);
        $res2->assertStatus(200);
        $res2->assertJson([
            'is_duplicate' => true,
            'is_duplicate_filename' => true,
        ]);
        $this->assertEquals('Duplicate Filename', $res2->json('error_title'));

        $res3 = $this->postJson('/api/v1/bom/import', ['file' => $file2]);
        $res3->assertStatus(200);
        $res3->assertJson([
            'is_duplicate' => true,
            'is_duplicate_filename' => true,
        ]);

        // 3. Different content but SAME FILENAME -> Strictly Rejected
        $rows2 = [
            ['project_code' => $projCode, 'jig_no' => 'JIG-01', 'unit_no' => '01', 'part_no' => 'PART-A', 'side' => 'RH', 'qty' => 6],
            ['project_code' => $projCode, 'jig_no' => 'JIG-02', 'unit_no' => '01', 'part_no' => 'PART-NEW', 'side' => 'RH', 'qty' => 10],
        ];
        $filePath2 = $this->createTestExcelFile($rows2, 'DifferentContentSheet');
        $file3 = new UploadedFile($filePath2, $filename, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);

        $res4 = $this->postJson('/api/v1/bom/preview', ['file' => $file3]);
        $res4->assertStatus(200);
        $res4->assertJson([
            'is_duplicate' => true,
            'is_duplicate_filename' => true,
        ]);
        $this->assertEquals('Duplicate Filename', $res4->json('error_title'));

        $res5 = $this->postJson('/api/v1/bom/import', ['file' => $file3]);
        $res5->assertStatus(200);
        $res5->assertJson([
            'is_duplicate' => true,
            'is_duplicate_filename' => true,
        ]);

        // Verify database data was NOT modified by the rejected duplicate-filename upload
        $project = Project::where('project_code', $projCode)->first();
        $this->assertEquals(1, BomItem::where('project_id', $project->id)->count());
        $req = BomRequirement::whereIn('bom_item_id', BomItem::where('project_id', $project->id)->pluck('id'))->first();
        $this->assertEquals(3, $req->required_quantity); // Remains 3, not modified to 6

        @unlink($filePath1);
        @unlink($filePath2);
    }

    public function test_scenario_d_renamed_file_with_same_content_skips_unchanged_rows()
    {
        $admin = $this->getAdminUser();
        $this->actingAs($admin, 'sanctum');

        $projCode = 'TEST-SKIP-' . uniqid();
        $rows = [
            ['project_code' => $projCode, 'jig_no' => 'JIG-01', 'unit_no' => '01', 'part_no' => 'PART-A', 'side' => 'RH', 'qty' => 3],
            ['project_code' => $projCode, 'jig_no' => 'JIG-01', 'unit_no' => '01', 'part_no' => 'PART-B', 'side' => 'LH', 'qty' => 4],
        ];

        $path1 = $this->createTestExcelFile($rows);
        $file1 = new UploadedFile($path1, "{$projCode}_original.xlsx", 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
        $this->postJson('/api/v1/bom/import', ['file' => $file1])->assertStatus(200);
        @unlink($path1);

        // Second file created (different timestamp/hash), but same content
        $path2 = $this->createTestExcelFile($rows, 'Sheet1');
        $file2 = new UploadedFile($path2, "{$projCode}(1)_copy.xlsx", 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);

        $preview = $this->postJson('/api/v1/bom/preview', ['file' => $file2]);
        $preview->assertStatus(200);
        $preview->assertJson([
            'success' => true,
            'is_revision' => true,
        ]);
        $this->assertEquals(2, $preview->json('reconciliation.unchanged_requirements_count'));
        $this->assertEquals(0, $preview->json('reconciliation.new_requirements_count'));
        $this->assertEquals(0, $preview->json('reconciliation.updated_requirements_count'));

        $import = $this->postJson('/api/v1/bom/import', ['file' => $file2]);
        $import->assertStatus(200);

        $project = Project::where('project_code', $projCode)->first();
        $this->assertEquals(2, BomItem::where('project_id', $project->id)->count());

        @unlink($path2);
    }

    public function test_scenario_e_existing_part_quantity_increased_updates_to_new_total()
    {
        $admin = $this->getAdminUser();
        $this->actingAs($admin, 'sanctum');

        $projCode = 'TEST-QTY-' . uniqid();

        // 1. Initial requirement: 3 units
        $rows1 = [
            ['project_code' => $projCode, 'jig_no' => 'JIG-01', 'unit_no' => '01', 'part_no' => '020#R00', 'side' => 'RH', 'qty' => 3],
        ];
        $path1 = $this->createTestExcelFile($rows1);
        $file1 = new UploadedFile($path1, "{$projCode} BOM.xlsx", 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
        $this->postJson('/api/v1/bom/import', ['file' => $file1])->assertStatus(200);
        @unlink($path1);

        $project = Project::where('project_code', $projCode)->first();
        $item = BomItem::where('project_id', $project->id)->first();
        $req = BomRequirement::where('bom_item_id', $item->id)->first();
        $this->assertEquals(3, $req->required_quantity);

        // 2. Revised BOM increases requirement to 6 units
        $rows2 = [
            ['project_code' => $projCode, 'jig_no' => 'JIG-01', 'unit_no' => '01', 'part_no' => '020#R00', 'side' => 'RH', 'qty' => 6],
        ];
        $path2 = $this->createTestExcelFile($rows2);
        $file2 = new UploadedFile($path2, "{$projCode}(1) BOM.xlsx", 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);

        $preview = $this->postJson('/api/v1/bom/preview', ['file' => $file2]);
        $preview->assertStatus(200);
        $this->assertEquals(1, $preview->json('reconciliation.updated_requirements_count'));
        $this->assertEquals(3, $preview->json('reconciliation.quantity_delta'));

        $import = $this->postJson('/api/v1/bom/import', ['file' => $file2]);
        $import->assertStatus(200);

        // CRITICAL INVARIANT: Required quantity is 6, NOT 3 + 6 = 9!
        $this->assertEquals(6, $req->fresh()->required_quantity);

        @unlink($path2);
    }

    public function test_scenario_f_quantity_decrease_below_received_triggers_conflict()
    {
        $admin = $this->getAdminUser();
        $this->actingAs($admin, 'sanctum');

        $projCode = 'TEST-CONFLICT-' . uniqid();

        // 1. Initial requirement: 6 units
        $rows1 = [
            ['project_code' => $projCode, 'jig_no' => 'JIG-01', 'unit_no' => '01', 'part_no' => '020#R00', 'side' => 'RH', 'qty' => 6],
        ];
        $path1 = $this->createTestExcelFile($rows1);
        $file1 = new UploadedFile($path1, "{$projCode}.xlsx", 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
        $this->postJson('/api/v1/bom/import', ['file' => $file1])->assertStatus(200);
        @unlink($path1);

        $project = Project::where('project_code', $projCode)->first();
        $item = BomItem::where('project_id', $project->id)->first();

        // Simulate 4 units received in Store
        $receipt = Receipt::create([
            'project_id' => $project->id,
            'delivery_note_number' => 'DN-' . uniqid(),
            'received_by' => $admin->id,
            'status' => 'completed',
        ]);
        ReceiptItem::create([
            'receipt_id' => $receipt->id,
            'bom_item_id' => $item->id,
            'side' => 'RH',
            'received_quantity' => 4,
            'status' => 'received',
        ]);

        // 2. Revised BOM attempts to reduce required quantity to 3 (less than 4 received!)
        $rows2 = [
            ['project_code' => $projCode, 'jig_no' => 'JIG-01', 'unit_no' => '01', 'part_no' => '020#R00', 'side' => 'RH', 'qty' => 3],
        ];
        $path2 = $this->createTestExcelFile($rows2);
        $file2 = new UploadedFile($path2, "{$projCode}_revised.xlsx", 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);

        $preview = $this->postJson('/api/v1/bom/preview', ['file' => $file2]);
        $preview->assertStatus(200);
        $this->assertEquals(1, $preview->json('reconciliation.conflict_count'));
        $this->assertFalse($preview->json('reconciliation.can_apply'));

        // Attempting to import is rejected
        $import = $this->postJson('/api/v1/bom/import', ['file' => $file2]);
        $import->assertStatus(200);
        $import->assertJson([
            'success' => false,
            'has_conflicts' => true,
        ]);

        // Requirement remains 6
        $req = BomRequirement::where('bom_item_id', $item->id)->first();
        $this->assertEquals(6, $req->required_quantity);

        @unlink($path2);
    }

    public function test_scenario_g_workflow_history_and_downstream_metrics_preserved()
    {
        $admin = $this->getAdminUser();
        $this->actingAs($admin, 'sanctum');

        $projCode = 'TEST-WORKFLOW-' . uniqid();

        // 1. Initial requirement: 3 units
        $rows1 = [
            ['project_code' => $projCode, 'jig_no' => 'JIG-01', 'unit_no' => '01', 'part_no' => 'PART-WF-01', 'side' => 'RH', 'qty' => 3],
        ];
        $path1 = $this->createTestExcelFile($rows1);
        $file1 = new UploadedFile($path1, "{$projCode}.xlsx", 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
        $this->postJson('/api/v1/bom/import', ['file' => $file1])->assertStatus(200);
        @unlink($path1);

        $project = Project::where('project_code', $projCode)->first();
        $item = BomItem::where('project_id', $project->id)->first();

        // Receive 2 units and advance through QC and Paint
        $receipt = Receipt::create([
            'project_id' => $project->id,
            'delivery_note_number' => 'DN-WF-01',
            'received_by' => $admin->id,
            'status' => 'completed',
        ]);
        $receiptItem = ReceiptItem::create([
            'receipt_id' => $receipt->id,
            'bom_item_id' => $item->id,
            'side' => 'RH',
            'received_quantity' => 2,
            'status' => 'paint_completed',
        ]);
        $inspection = QcInspection::create([
            'receipt_item_id' => $receiptItem->id,
            'bom_item_id' => $item->id,
            'inspected_by' => $admin->id,
            'side' => 'RH',
            'result' => 'approved',
            'destination' => 'PAINT',
            'inspected_quantity' => 2,
            'approved_quantity' => 2,
            'inspection_date' => now()->toDateString(),
        ]);
        $paint = PaintRecord::create([
            'bom_item_id' => $item->id,
            'qc_inspection_id' => $inspection->id,
            'side' => 'RH',
            'quantity' => 2,
            'painted_by' => $admin->id,
            'status' => 'completed',
            'completed_at' => now(),
        ]);

        // Prior metrics
        $metricsBefore = $this->quantityService->calculateProjectMetrics($project, 'RH');
        $this->assertEquals(3, $metricsBefore['total_required']);
        $this->assertEquals(2, $metricsBefore['total_received']);
        $this->assertEquals(1, $metricsBefore['parts_pending']);

        // 2. Revised BOM increases requirement to 6
        $rows2 = [
            ['project_code' => $projCode, 'jig_no' => 'JIG-01', 'unit_no' => '01', 'part_no' => 'PART-WF-01', 'side' => 'RH', 'qty' => 6],
        ];
        $path2 = $this->createTestExcelFile($rows2);
        $file2 = new UploadedFile($path2, "{$projCode}(1).xlsx", 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
        $this->postJson('/api/v1/bom/import', ['file' => $file2])->assertStatus(200);
        @unlink($path2);

        // Updated metrics
        $metricsAfter = $this->quantityService->calculateProjectMetrics($project, 'RH');
        $this->assertEquals(6, $metricsAfter['total_required']);
        $this->assertEquals(2, $metricsAfter['total_received']);
        $this->assertEquals(4, $metricsAfter['parts_pending']); // 6 - 2 = 4
        $this->assertEquals(2, $metricsAfter['assembly_ready']); // Paint completed feeds assembly ready!

        // Assert physical records untouched
        $this->assertEquals('paint_completed', $receiptItem->fresh()->status);
        $this->assertEquals(2, $paint->fresh()->quantity);
    }

    public function test_scenario_h_rh_lh_same_part_number_maintains_strict_side_isolation()
    {
        $admin = $this->getAdminUser();
        $this->actingAs($admin, 'sanctum');

        $projCode = 'TEST-SIDE-' . uniqid();

        // Part PART-SYM-01 exists on BOTH RH (qty 5) and LH (qty 10)
        $rows = [
            ['project_code' => $projCode, 'jig_no' => 'JIG-01', 'unit_no' => '01', 'part_no' => 'PART-SYM-01', 'side' => 'RH', 'qty' => 5],
            ['project_code' => $projCode, 'jig_no' => 'JIG-01', 'unit_no' => '01', 'part_no' => 'PART-SYM-01', 'side' => 'LH', 'qty' => 10],
        ];

        $filePath = $this->createTestExcelFile($rows);
        $file = new UploadedFile($filePath, "{$projCode}.xlsx", 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
        $res = $this->postJson('/api/v1/bom/import', ['file' => $file]);
        $res->assertStatus(200);
        @unlink($filePath);

        $project = Project::where('project_code', $projCode)->first();
        $item = BomItem::where('project_id', $project->id)->where('standard_part_no', 'PART-SYM-01')->first();
        $this->assertNotNull($item);

        $rhReq = BomRequirement::where('bom_item_id', $item->id)->where('side', 'RH')->first();
        $lhReq = BomRequirement::where('bom_item_id', $item->id)->where('side', 'LH')->first();

        $this->assertNotNull($rhReq);
        $this->assertNotNull($lhReq);
        $this->assertEquals(5, $rhReq->required_quantity);
        $this->assertEquals(10, $lhReq->required_quantity);

        $rhMetrics = $this->quantityService->calculateProjectMetrics($project, 'RH');
        $lhMetrics = $this->quantityService->calculateProjectMetrics($project, 'LH');

        $this->assertEquals(5, $rhMetrics['total_required']);
        $this->assertEquals(10, $lhMetrics['total_required']);
    }

    public function test_scenario_i_incremental_new_part_and_new_unit_added_to_existing_jig()
    {
        $admin = $this->getAdminUser();
        $this->actingAs($admin, 'sanctum');

        $projCode = 'TEST-UNIT-' . uniqid();

        // 1. Initial import with Unit 01
        $rows1 = [
            ['project_code' => $projCode, 'jig_no' => 'JIG-01', 'unit_no' => '01', 'part_no' => 'PART-01', 'side' => 'RH', 'qty' => 2],
        ];
        $path1 = $this->createTestExcelFile($rows1);
        $file1 = new UploadedFile($path1, "{$projCode}.xlsx", 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
        $this->postJson('/api/v1/bom/import', ['file' => $file1])->assertStatus(200);
        @unlink($path1);

        $project = Project::where('project_code', $projCode)->first();
        $this->assertEquals(1, BomItem::where('project_id', $project->id)->count());

        // 2. Later import adds Unit 02 and new part PART-02 to the same JIG-01
        $rows2 = [
            ['project_code' => $projCode, 'jig_no' => 'JIG-01', 'unit_no' => '01', 'part_no' => 'PART-01', 'side' => 'RH', 'qty' => 2],
            ['project_code' => $projCode, 'jig_no' => 'JIG-01', 'unit_no' => '02', 'part_no' => 'PART-02', 'side' => 'RH', 'qty' => 4],
        ];
        $path2 = $this->createTestExcelFile($rows2);
        $file2 = new UploadedFile($path2, "{$projCode}(1).xlsx", 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);

        $preview = $this->postJson('/api/v1/bom/preview', ['file' => $file2]);
        $preview->assertStatus(200);
        $this->assertEquals(1, $preview->json('reconciliation.new_requirements_count'));
        $this->assertEquals(1, $preview->json('reconciliation.unchanged_requirements_count'));
        $this->assertEquals(1, $preview->json('reconciliation.new_units_count'));

        $this->postJson('/api/v1/bom/import', ['file' => $file2])->assertStatus(200);
        @unlink($path2);

        $items = BomItem::where('project_id', $project->id)->get();
        $this->assertCount(2, $items);
        $this->assertEquals(['01', '02'], $items->pluck('unit_no')->sort()->values()->toArray());
    }

    public function test_scenario_j_safe_quantity_reduction_above_received_succeeds()
    {
        $admin = $this->getAdminUser();
        $this->actingAs($admin, 'sanctum');

        $projCode = 'TEST-SAFE-REDUCE-' . uniqid();

        // Initial required: 10
        $rows1 = [
            ['project_code' => $projCode, 'jig_no' => 'JIG-01', 'unit_no' => '01', 'part_no' => 'PART-RED-01', 'side' => 'RH', 'qty' => 10],
        ];
        $path1 = $this->createTestExcelFile($rows1);
        $file1 = new UploadedFile($path1, "{$projCode}.xlsx", 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
        $this->postJson('/api/v1/bom/import', ['file' => $file1])->assertStatus(200);
        @unlink($path1);

        $project = Project::where('project_code', $projCode)->first();
        $item = BomItem::where('project_id', $project->id)->first();

        // Store received: 3
        $receipt = Receipt::create([
            'project_id' => $project->id,
            'delivery_note_number' => 'DN-RED-01',
            'received_by' => $admin->id,
            'status' => 'completed',
        ]);
        ReceiptItem::create([
            'receipt_id' => $receipt->id,
            'bom_item_id' => $item->id,
            'side' => 'RH',
            'received_quantity' => 3,
            'status' => 'received',
        ]);

        // Revised requirement reduced to 7 (safe because 3 received <= 7 new required)
        $rows2 = [
            ['project_code' => $projCode, 'jig_no' => 'JIG-01', 'unit_no' => '01', 'part_no' => 'PART-RED-01', 'side' => 'RH', 'qty' => 7],
        ];
        $path2 = $this->createTestExcelFile($rows2);
        $file2 = new UploadedFile($path2, "{$projCode}(1).xlsx", 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);

        $preview = $this->postJson('/api/v1/bom/preview', ['file' => $file2]);
        $preview->assertStatus(200);
        $this->assertEquals(0, $preview->json('reconciliation.conflict_count'));
        $this->assertTrue($preview->json('reconciliation.can_apply'));

        $this->postJson('/api/v1/bom/import', ['file' => $file2])->assertStatus(200);
        @unlink($path2);

        $req = BomRequirement::where('bom_item_id', $item->id)->first();
        $this->assertEquals(7, $req->required_quantity);

        $metrics = $this->quantityService->calculateProjectMetrics($project, 'RH');
        $this->assertEquals(7, $metrics['total_required']);
        $this->assertEquals(3, $metrics['total_received']);
        $this->assertEquals(4, $metrics['parts_pending']); // 7 - 3 = 4
    }
}
