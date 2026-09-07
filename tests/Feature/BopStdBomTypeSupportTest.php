<?php

namespace Tests\Feature;

use App\Models\BomImportBatch;
use App\Models\BomItem;
use App\Models\BomRequirement;
use App\Models\Project;
use App\Models\ReceiptItem;
use App\Models\User;
use App\Services\BomImportService;
use App\Services\QuantityCalculationService;
use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class BopStdBomTypeSupportTest extends TestCase
{
    protected BomImportService $bomImportService;
    protected QuantityCalculationService $quantityService;
    protected string $testProjectCode = 'TEST_BOP_STD_99';
    protected array $tempFiles = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->bomImportService = app(BomImportService::class);
        $this->quantityService = app(QuantityCalculationService::class);
    }

    protected function tearDown(): void
    {
        // Clean up temporary test files
        foreach ($this->tempFiles as $f) {
            if (file_exists($f)) {
                @unlink($f);
            }
        }

        // Clean up test data ONLY (prefix TEST_)
        $proj = Project::where('project_code', $this->testProjectCode)->first();
        if ($proj) {
            $batches = BomImportBatch::where('project_id', $proj->id)->get();
            $items = BomItem::where('project_id', $proj->id)->get();
            $itemIds = $items->pluck('id')->toArray();

            if (!empty($itemIds)) {
                ReceiptItem::whereIn('bom_item_id', $itemIds)->delete();
                BomRequirement::whereIn('bom_item_id', $itemIds)->delete();
                BomItem::whereIn('id', $itemIds)->forceDelete();
            }

            foreach ($batches as $b) {
                $b->delete();
            }

            $proj->forceDelete();
        }

        parent::tearDown();
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

    protected function createExcelFile(string $partHeader, array $rows, string $sheetName = 'Sheet1'): string
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle($sheetName);

        // Header Row at Row 1
        $sheet->setCellValue('A1', 'PROJECT CODE');
        $sheet->setCellValue('B1', 'JIG NO');
        $sheet->setCellValue('C1', 'UNIT NO');
        $sheet->setCellValue('D1', $partHeader);
        $sheet->setCellValue('E1', 'QUANTITY');
        $sheet->setCellValue('F1', 'SIDE');

        $rowIdx = 2;
        foreach ($rows as $r) {
            $sheet->setCellValue('A' . $rowIdx, $r['project_code']);
            $sheet->setCellValue('B' . $rowIdx, $r['jig_no']);
            $sheet->setCellValue('C' . $rowIdx, $r['unit_no']);
            $sheet->setCellValue('D' . $rowIdx, $r['part_no']);
            $sheet->setCellValue('E' . $rowIdx, $r['qty']);
            $sheet->setCellValue('F' . $rowIdx, $r['side']);
            $rowIdx++;
        }

        $tempPath = tempnam(sys_get_temp_dir(), 'bop_std_test_') . '.xlsx';
        $writer = new Xlsx($spreadsheet);
        $writer->save($tempPath);

        $this->tempFiles[] = $tempPath;
        return $tempPath;
    }

    public function test_bop_and_std_header_detection_and_parsing(): void
    {
        // 1. BOP File
        $bopPath = $this->createExcelFile('BOP PART NO', [
            ['project_code' => $this->testProjectCode, 'jig_no' => 'JIG01', 'unit_no' => 'U01', 'part_no' => 'BOP-BUSH-01', 'qty' => 5, 'side' => 'L'],
            ['project_code' => $this->testProjectCode, 'jig_no' => 'JIG01', 'unit_no' => 'U01', 'part_no' => 'BOP-BUSH-02', 'qty' => 3, 'side' => 'R'],
        ]);

        $bopPreview = $this->bomImportService->previewFromPath($bopPath, 'TEST_BOP_Sample.xlsx');
        $this->assertTrue($bopPreview['success']);
        $this->assertEquals('BOP', $bopPreview['bom_type']);
        $this->assertCount(2, $bopPreview['rows']);
        $this->assertEquals('LH', $bopPreview['rows'][0]['side']); // Normalized L -> LH
        $this->assertEquals('RH', $bopPreview['rows'][1]['side']); // Normalized R -> RH

        // 2. STD File
        $stdPath = $this->createExcelFile('STD PART NO', [
            ['project_code' => $this->testProjectCode, 'jig_no' => 'JIG01', 'unit_no' => 'U02', 'part_no' => 'STD-PIN-01', 'qty' => 12, 'side' => 'L'],
            ['project_code' => $this->testProjectCode, 'jig_no' => 'JIG01', 'unit_no' => 'U02', 'part_no' => 'STD-PIN-02', 'qty' => 8, 'side' => 'R'],
        ]);

        $stdPreview = $this->bomImportService->previewFromPath($stdPath, 'TEST_STD_Sample.xlsx');
        $this->assertTrue($stdPreview['success']);
        $this->assertEquals('STD', $stdPreview['bom_type']);
        $this->assertCount(2, $stdPreview['rows']);
        $this->assertEquals('LH', $stdPreview['rows'][0]['side']);
        $this->assertEquals('RH', $stdPreview['rows'][1]['side']);
    }

    public function test_duplicate_rows_in_bop_and_std_are_skipped_never_summed_with_warning(): void
    {
        // Reference user comment: "this reapeat parts where added by mistake skip them only take one part any of them and skip rest of it and aslo give an warning while uploading repeat parts are there by deafult skipping them"
        // "never sum them up if similar project jig unit part side is there skip them and give an warning while uploading similar parts are been skipped"

        $bopPath = $this->createExcelFile('BOP PART NO', [
            ['project_code' => $this->testProjectCode, 'jig_no' => 'JIG01', 'unit_no' => 'U01', 'part_no' => 'DU-BUSH-12', 'qty' => 10, 'side' => 'L'],
            // Exact same combo repeated: should be SKIPPED, not summed to 35, and only 10 retained!
            ['project_code' => $this->testProjectCode, 'jig_no' => 'JIG01', 'unit_no' => 'U01', 'part_no' => 'DU-BUSH-12', 'qty' => 25, 'side' => 'L'],
        ]);

        $res = $this->bomImportService->previewFromPath($bopPath, 'TEST_BOP_Duplicates.xlsx');

        $this->assertTrue($res['success']);
        $this->assertEquals('BOP', $res['bom_type']);
        $this->assertEquals(1, $res['summary']['total_rows']); // Only 1 row kept
        $this->assertEquals(10, $res['summary']['total_required_quantity']); // NOT 35!
        $this->assertEquals(1, $res['summary']['duplicate_skipped_count']);
        $this->assertNotEmpty($res['warnings']);

        // Check warning content
        $joinedWarnings = implode(' ', $res['warnings']);
        $this->assertStringContainsString('duplicate', strtolower($joinedWarnings));
        $this->assertStringContainsString('skipped', strtolower($joinedWarnings));
    }

    public function test_bop_and_std_import_creates_batches_and_items_with_correct_types(): void
    {
        $user = $this->getAdminUser();

        // 1. Import BOP
        $bopPath = $this->createExcelFile('BOP PART NO', [
            ['project_code' => $this->testProjectCode, 'jig_no' => 'JIG01', 'unit_no' => 'U01', 'part_no' => 'BOP-PART-A', 'qty' => 4, 'side' => 'LH'],
            ['project_code' => $this->testProjectCode, 'jig_no' => 'JIG01', 'unit_no' => 'U01', 'part_no' => 'BOP-PART-B', 'qty' => 6, 'side' => 'RH'],
        ]);

        $bopImport = $this->bomImportService->importFromPath($bopPath, ['filename' => 'TEST_BOP_Import_01.xlsx'], $user->id);
        $this->assertTrue($bopImport['success']);
        $this->assertEquals('BOP', $bopImport['bom_type']);

        $bopBatch = BomImportBatch::where('original_filename', 'TEST_BOP_Import_01.xlsx')->first();
        $this->assertNotNull($bopBatch);
        $this->assertEquals('BOP', $bopBatch->bom_type);

        $bopItems = BomItem::where('import_batch_id', $bopBatch->id)->get();
        $this->assertCount(2, $bopItems);
        foreach ($bopItems as $item) {
            $this->assertEquals('BOP', $item->part_type);
        }

        // 2. Import STD under same project
        $stdPath = $this->createExcelFile('STD PART NO', [
            ['project_code' => $this->testProjectCode, 'jig_no' => 'JIG01', 'unit_no' => 'U01', 'part_no' => 'STD-PART-X', 'qty' => 8, 'side' => 'LH'],
        ]);

        $stdImport = $this->bomImportService->importFromPath($stdPath, ['filename' => 'TEST_STD_Import_01.xlsx'], $user->id);
        $this->assertTrue($stdImport['success']);
        $this->assertEquals('STD', $stdImport['bom_type']);

        $stdBatch = BomImportBatch::where('original_filename', 'TEST_STD_Import_01.xlsx')->first();
        $this->assertNotNull($stdBatch);
        $this->assertEquals('STD', $stdBatch->bom_type);

        $stdItems = BomItem::where('import_batch_id', $stdBatch->id)->get();
        $this->assertCount(1, $stdItems);
        $this->assertEquals('STD', $stdItems->first()->part_type);
    }

    public function test_mfg_bop_and_std_coexist_under_same_project_without_collision(): void
    {
        $user = $this->getAdminUser();
        $proj = Project::firstOrCreate(
            ['project_code' => $this->testProjectCode],
            ['name' => 'BOP STD Test Project', 'status' => 'active', 'created_by' => $user->id]
        );

        // Same standard_part_no 'UNIVERSAL-PIN-01' across MFG, BOP, and STD in same Jig and Unit
        $mfgItem = BomItem::create([
            'project_id' => $proj->id,
            'jig_no' => 'JIG01',
            'unit_no' => 'U01',
            'standard_part_no' => 'UNIVERSAL-PIN-01',
            'part_type' => 'MFG',
        ]);
        BomRequirement::create(['bom_item_id' => $mfgItem->id, 'side' => 'LH', 'required_quantity' => 10]);

        $bopItem = BomItem::create([
            'project_id' => $proj->id,
            'jig_no' => 'JIG01',
            'unit_no' => 'U01',
            'standard_part_no' => 'UNIVERSAL-PIN-01',
            'part_type' => 'BOP',
        ]);
        BomRequirement::create(['bom_item_id' => $bopItem->id, 'side' => 'LH', 'required_quantity' => 20]);

        $stdItem = BomItem::create([
            'project_id' => $proj->id,
            'jig_no' => 'JIG01',
            'unit_no' => 'U01',
            'standard_part_no' => 'UNIVERSAL-PIN-01',
            'part_type' => 'STD',
        ]);
        BomRequirement::create(['bom_item_id' => $stdItem->id, 'side' => 'LH', 'required_quantity' => 30]);

        // Verify all 3 distinct items exist in database
        $this->assertEquals(3, BomItem::where('project_id', $proj->id)->where('standard_part_no', 'UNIVERSAL-PIN-01')->count());
        $this->assertEquals(1, BomItem::where('project_id', $proj->id)->where('part_type', 'MFG')->count());
        $this->assertEquals(1, BomItem::where('project_id', $proj->id)->where('part_type', 'BOP')->count());
        $this->assertEquals(1, BomItem::where('project_id', $proj->id)->where('part_type', 'STD')->count());
    }

    public function test_dashboard_summary_returns_separated_mfg_bop_std_kpi_groups(): void
    {
        $user = $this->getAdminUser();
        $proj = Project::firstOrCreate(
            ['project_code' => $this->testProjectCode],
            ['name' => 'BOP STD Test Project', 'status' => 'active', 'created_by' => $user->id]
        );

        // MFG item: 10 required
        $mfgItem = BomItem::create([
            'project_id' => $proj->id,
            'jig_no' => 'JIG01',
            'unit_no' => 'U01',
            'standard_part_no' => 'MFG-PART-1',
            'part_type' => 'MFG',
        ]);
        BomRequirement::create(['bom_item_id' => $mfgItem->id, 'side' => 'LH', 'required_quantity' => 10]);

        // BOP item: 20 required
        $bopItem = BomItem::create([
            'project_id' => $proj->id,
            'jig_no' => 'JIG01',
            'unit_no' => 'U01',
            'standard_part_no' => 'BOP-PART-1',
            'part_type' => 'BOP',
        ]);
        BomRequirement::create(['bom_item_id' => $bopItem->id, 'side' => 'LH', 'required_quantity' => 20]);

        // STD item: 30 required
        $stdItem = BomItem::create([
            'project_id' => $proj->id,
            'jig_no' => 'JIG01',
            'unit_no' => 'U01',
            'standard_part_no' => 'STD-PART-1',
            'part_type' => 'STD',
        ]);
        BomRequirement::create(['bom_item_id' => $stdItem->id, 'side' => 'LH', 'required_quantity' => 30]);

        $response = $this->actingAs($user)->getJson("/api/v1/dashboard/summary?project_id={$proj->id}");
        $response->assertOk();

        $summary = $response->json('summary');
        $this->assertArrayHasKey('mfg', $summary);
        $this->assertArrayHasKey('bop', $summary);
        $this->assertArrayHasKey('std', $summary);

        $this->assertEquals(10, $summary['mfg']['total_required']);
        $this->assertEquals(20, $summary['bop']['total_required']);
        $this->assertEquals(30, $summary['std']['total_required']);

        // Assert all 9 canonical keys exist in each sub-object
        foreach (['mfg', 'bop', 'std'] as $typeKey) {
            $this->assertArrayHasKey('total_required', $summary[$typeKey]);
            $this->assertArrayHasKey('total_received', $summary[$typeKey]);
            $this->assertArrayHasKey('total_pending', $summary[$typeKey]);
            $this->assertArrayHasKey('parts_in_store', $summary[$typeKey]);
            $this->assertArrayHasKey('parts_in_qc', $summary[$typeKey]);
            $this->assertArrayHasKey('parts_in_rework', $summary[$typeKey]);
            $this->assertArrayHasKey('parts_in_paint', $summary[$typeKey]);
            $this->assertArrayHasKey('assembly_completed', $summary[$typeKey]);
            $this->assertArrayHasKey('qc_rejected', $summary[$typeKey]);
        }
    }

    public function test_dashboard_project_hierarchy_supports_part_type_filtering_and_sections(): void
    {
        $user = $this->getAdminUser();
        $proj = Project::firstOrCreate(
            ['project_code' => $this->testProjectCode],
            ['name' => 'BOP STD Test Project', 'status' => 'active', 'created_by' => $user->id]
        );

        $mfgItem = BomItem::create([
            'project_id' => $proj->id,
            'jig_no' => 'JIG-MFG',
            'unit_no' => 'U01',
            'standard_part_no' => 'MFG-ONLY-01',
            'part_type' => 'MFG',
        ]);
        BomRequirement::create(['bom_item_id' => $mfgItem->id, 'side' => 'LH', 'required_quantity' => 5]);

        $bopItem = BomItem::create([
            'project_id' => $proj->id,
            'jig_no' => 'JIG-BOP',
            'unit_no' => 'U01',
            'standard_part_no' => 'BOP-ONLY-01',
            'part_type' => 'BOP',
        ]);
        BomRequirement::create(['bom_item_id' => $bopItem->id, 'side' => 'LH', 'required_quantity' => 15]);

        // 1. Test ?part_type=BOP returns only BOP
        $resBop = $this->actingAs($user)->getJson("/api/v1/dashboard/project-hierarchy?project_id={$proj->id}&part_type=BOP");
        $resBop->assertOk();
        $jigsBop = $resBop->json('jigs') ?? [];
        $jigNamesBop = array_column($jigsBop, 'jig_name');
        $this->assertContains('JIG-BOP', $jigNamesBop);
        $this->assertNotContains('JIG-MFG', $jigNamesBop);

        // 2. Test without part_type returns sections
        $resAll = $this->actingAs($user)->getJson("/api/v1/dashboard/project-hierarchy?project_id={$proj->id}");
        $resAll->assertOk();
        $this->assertArrayHasKey('mfg_section', $resAll->json());
        $this->assertArrayHasKey('bop_section', $resAll->json());
        $this->assertArrayHasKey('std_section', $resAll->json());
    }

    public function test_kpi_drilldown_supports_part_type_filter(): void
    {
        $user = $this->getAdminUser();
        $proj = Project::firstOrCreate(
            ['project_code' => $this->testProjectCode],
            ['name' => 'BOP STD Test Project', 'status' => 'active', 'created_by' => $user->id]
        );

        $mfgItem = BomItem::create([
            'project_id' => $proj->id,
            'jig_no' => 'JIG01',
            'unit_no' => 'U01',
            'standard_part_no' => 'MFG-DRILL-01',
            'part_type' => 'MFG',
        ]);
        BomRequirement::create(['bom_item_id' => $mfgItem->id, 'side' => 'LH', 'required_quantity' => 7]);

        $bopItem = BomItem::create([
            'project_id' => $proj->id,
            'jig_no' => 'JIG01',
            'unit_no' => 'U01',
            'standard_part_no' => 'BOP-DRILL-01',
            'part_type' => 'BOP',
        ]);
        BomRequirement::create(['bom_item_id' => $bopItem->id, 'side' => 'LH', 'required_quantity' => 14]);

        $res = $this->actingAs($user)->getJson("/api/v1/dashboard/kpi-drilldown?kpi=total_parts&project_id={$proj->id}&part_type=BOP");
        $res->assertOk();

        $rows = $res->json('data');
        $this->assertCount(1, $rows);
        $this->assertEquals('BOP-DRILL-01', $rows[0]['part_no']);
        $this->assertEquals('BOP', $rows[0]['part_type']);
    }

    public function test_bop_batch_deletion_preserves_mfg_items(): void
    {
        $user = $this->getAdminUser();
        $proj = Project::firstOrCreate(
            ['project_code' => $this->testProjectCode],
            ['name' => 'BOP STD Test Project', 'status' => 'active', 'created_by' => $user->id]
        );

        $mfgBatch = BomImportBatch::create([
            'project_id' => $proj->id,
            'filename' => 'TEST_MFG_BATCH.xlsx',
            'original_filename' => 'TEST_MFG_BATCH.xlsx',
            'total_rows' => 1,
            'successful_rows' => 1,
            'status' => 'completed',
            'bom_type' => 'MFG',
        ]);

        $mfgItem = BomItem::create([
            'project_id' => $proj->id,
            'jig_no' => 'JIG01',
            'unit_no' => 'U01',
            'standard_part_no' => 'MFG-KEEP-01',
            'part_type' => 'MFG',
            'import_batch_id' => $mfgBatch->id,
        ]);
        BomRequirement::create(['bom_item_id' => $mfgItem->id, 'side' => 'LH', 'required_quantity' => 5]);

        $bopBatch = BomImportBatch::create([
            'project_id' => $proj->id,
            'filename' => 'TEST_BOP_BATCH.xlsx',
            'original_filename' => 'TEST_BOP_BATCH.xlsx',
            'total_rows' => 1,
            'successful_rows' => 1,
            'status' => 'completed',
            'bom_type' => 'BOP',
        ]);

        $bopItem = BomItem::create([
            'project_id' => $proj->id,
            'jig_no' => 'JIG01',
            'unit_no' => 'U01',
            'standard_part_no' => 'BOP-DELETE-01',
            'part_type' => 'BOP',
            'import_batch_id' => $bopBatch->id,
        ]);
        BomRequirement::create(['bom_item_id' => $bopItem->id, 'side' => 'LH', 'required_quantity' => 10]);

        // Delete the BOP batch
        $deleteRes = $this->actingAs($user)->deleteJson("/api/v1/bom/history/{$bopBatch->id}");
        $deleteRes->assertOk();

        // Verify BOP item deleted, MFG item untouched
        $this->assertNull(BomItem::find($bopItem->id));
        $this->assertNotNull(BomItem::find($mfgItem->id));
        $this->assertNotNull(Project::find($proj->id));
    }
}
