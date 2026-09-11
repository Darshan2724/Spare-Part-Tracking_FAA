<?php

namespace Tests\Feature;

use App\Models\BomImportBatch;
use App\Models\BomItem;
use App\Models\BomRequirement;
use App\Models\Project;
use App\Models\User;
use App\Services\BomImportService;
use App\Services\ProjectIdentityResolver;
use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class BomFilenameTypeRoutingTest extends TestCase
{
    protected BomImportService $bomImportService;
    protected ProjectIdentityResolver $projectResolver;
    protected array $tempFiles = [];
    protected array $createdProjectIds = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->bomImportService = app(BomImportService::class);
        $this->projectResolver = app(ProjectIdentityResolver::class);
    }

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $path) {
            if (file_exists($path)) {
                @unlink($path);
            }
        }

        if (!empty($this->createdProjectIds)) {
            $batches = BomImportBatch::whereIn('project_id', $this->createdProjectIds)->get();
            $items = BomItem::whereIn('project_id', $this->createdProjectIds)->get();
            $itemIds = $items->pluck('id')->toArray();

            if (!empty($itemIds)) {
                BomRequirement::whereIn('bom_item_id', $itemIds)->delete();
                BomItem::whereIn('id', $itemIds)->forceDelete();
            }

            foreach ($batches as $b) {
                $b->delete();
            }

            Project::whereIn('id', $this->createdProjectIds)->forceDelete();
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

    protected function createExcelFile(array $rows, string $projectHeader = 'Project Code', string $partHeader = 'Part No'): string
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('BOM');

        $sheet->setCellValue('A1', $projectHeader);
        $sheet->setCellValue('B1', 'Jig No');
        $sheet->setCellValue('C1', 'Unit No');
        $sheet->setCellValue('D1', $partHeader);
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

        $tempPath = tempnam(sys_get_temp_dir(), 'bom_routing_test_') . '.xlsx';
        $writer = new Xlsx($spreadsheet);
        $writer->save($tempPath);

        $this->tempFiles[] = $tempPath;
        return $tempPath;
    }

    public function test_filename_token_detection_unit(): void
    {
        // Valid single tokens with delimiters
        $this->assertEquals(['valid' => true, 'type' => 'BOP', 'error' => null], $this->bomImportService->detectBomTypeFromFilename('FA-273_BOP.xlsx'));
        $this->assertEquals(['valid' => true, 'type' => 'BOP', 'error' => null], $this->bomImportService->detectBomTypeFromFilename('FA-273-bop.xlsx'));
        $this->assertEquals(['valid' => true, 'type' => 'BOP', 'error' => null], $this->bomImportService->detectBomTypeFromFilename('FA-273 BOP (1).xlsx'));
        $this->assertEquals(['valid' => true, 'type' => 'BOP', 'error' => null], $this->bomImportService->detectBomTypeFromFilename('FA-273[Bop].xlsx'));
        $this->assertEquals(['valid' => true, 'type' => 'BOP', 'error' => null], $this->bomImportService->detectBomTypeFromFilename('FA-273.BOP.revised.xlsx'));
        $this->assertEquals(['valid' => true, 'type' => 'STD', 'error' => null], $this->bomImportService->detectBomTypeFromFilename('FA-273_STD.xlsx'));
        $this->assertEquals(['valid' => true, 'type' => 'STD', 'error' => null], $this->bomImportService->detectBomTypeFromFilename('FA-273_std_final.xlsx'));
        $this->assertEquals(['valid' => true, 'type' => 'MFG', 'error' => null], $this->bomImportService->detectBomTypeFromFilename('FA-273_MFG.xlsx'));
        $this->assertEquals(['valid' => true, 'type' => 'MFG', 'error' => null], $this->bomImportService->detectBomTypeFromFilename('FA-273 NEW MFG BOM.xlsx'));

        // Multiple identical tokens are valid
        $this->assertEquals(['valid' => true, 'type' => 'BOP', 'error' => null], $this->bomImportService->detectBomTypeFromFilename('BOP_FA-273_BOP.xlsx'));

        // Missing tokens are rejected
        $missingRes = $this->bomImportService->detectBomTypeFromFilename('FA-273.xlsx');
        $this->assertFalse($missingRes['valid']);
        $this->assertStringContainsString('Filename must contain MFG, BOP, or STD', $missingRes['error']);

        // Substrings must NOT match (lookaround boundaries)
        $this->assertFalse($this->bomImportService->detectBomTypeFromFilename('BOPP_Tape.xlsx')['valid']);
        $this->assertFalse($this->bomImportService->detectBomTypeFromFilename('Standard_Parts.xlsx')['valid']);
        $this->assertFalse($this->bomImportService->detectBomTypeFromFilename('suboptimal_solution.xlsx')['valid']);
        $this->assertFalse($this->bomImportService->detectBomTypeFromFilename('manufacturing_sheet.xlsx')['valid']);

        // Ambiguous tokens are rejected
        $ambigRes = $this->bomImportService->detectBomTypeFromFilename('FA-273_BOP_STD.xlsx');
        $this->assertFalse($ambigRes['valid']);
        $this->assertStringContainsString('Ambiguous BOM type', $ambigRes['error']);
    }

    public function test_missing_filename_token_is_rejected_in_preview_and_import(): void
    {
        $admin = $this->getAdminUser();
        $this->actingAs($admin, 'sanctum');

        $rows = [
            ['project_code' => 'FA-273', 'jig_no' => 'JIG-01', 'unit_no' => '01', 'part_no' => 'PART-X', 'side' => 'RH', 'qty' => 5],
        ];
        $filePath = $this->createExcelFile($rows);
        $file = new UploadedFile($filePath, 'FA-273.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);

        // Preview rejected
        $previewRes = $this->postJson('/api/v1/bom/preview', ['file' => $file]);
        $previewRes->assertStatus(200);
        $this->assertFalse($previewRes->json('success'));
        $this->assertTrue($previewRes->json('is_invalid_filename'));
        $this->assertStringContainsString('Filename must contain MFG, BOP, or STD', $previewRes->json('message'));

        // Import rejected
        $importRes = $this->postJson('/api/v1/bom/import', ['file' => $file]);
        $importRes->assertStatus(200);
        $this->assertFalse($importRes->json('success'));
        $this->assertTrue($importRes->json('is_invalid_filename'));
        $this->assertStringContainsString('Filename must contain MFG, BOP, or STD', $importRes->json('message'));
    }

    public function test_ambiguous_filename_tokens_are_rejected(): void
    {
        $admin = $this->getAdminUser();
        $this->actingAs($admin, 'sanctum');

        $rows = [
            ['project_code' => 'FA-273', 'jig_no' => 'JIG-01', 'unit_no' => '01', 'part_no' => 'PART-X', 'side' => 'RH', 'qty' => 5],
        ];
        $filePath = $this->createExcelFile($rows);
        $file = new UploadedFile($filePath, 'FA-273_MFG_BOP.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);

        $previewRes = $this->postJson('/api/v1/bom/preview', ['file' => $file]);
        $previewRes->assertStatus(200);
        $this->assertFalse($previewRes->json('success'));
        $this->assertStringContainsString('Ambiguous BOM type', $previewRes->json('message'));

        $importRes = $this->postJson('/api/v1/bom/import', ['file' => $file]);
        $importRes->assertStatus(200);
        $this->assertFalse($importRes->json('success'));
        $this->assertStringContainsString('Ambiguous BOM type', $importRes->json('message'));
    }

    public function test_project_name_read_strictly_from_inside_excel_not_filename(): void
    {
        $admin = $this->getAdminUser();
        $this->actingAs($admin, 'sanctum');

        $projInside = 'TEST-PROJ-INSIDE-' . uniqid();

        // Filename has MISLEADING project prefix "FA-MISLEADING-999", but inside workbook is $projInside
        $rows = [
            ['project_code' => $projInside, 'jig_no' => 'JIG-01', 'unit_no' => '01', 'part_no' => 'BOP-VALVE-01', 'side' => 'RH', 'qty' => 4],
        ];
        $filePath = $this->createExcelFile($rows);
        $file = new UploadedFile($filePath, 'FA-MISLEADING-999_BOP.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);

        $previewRes = $this->postJson('/api/v1/bom/preview', ['file' => $file]);
        $previewRes->assertStatus(200);
        $this->assertTrue($previewRes->json('success'));
        $this->assertEquals('BOP', $previewRes->json('bom_type'));
        $this->assertEquals($projInside, $previewRes->json('matched_projects.0.project_code'));

        $importRes = $this->postJson('/api/v1/bom/import', ['file' => $file]);
        $importRes->assertStatus(200);
        $this->assertTrue($importRes->json('success'));
        $this->assertEquals('BOP', $importRes->json('bom_type'));

        // Confirm database project was created with $projInside, NOT FA-MISLEADING-999!
        $project = Project::where('project_code', $projInside)->first();
        $this->assertNotNull($project);
        $this->createdProjectIds[] = $project->id;

        $misleadingProject = Project::where('project_code', 'FA-MISLEADING-999')->first();
        $this->assertNull($misleadingProject);

        // Confirm BomItem created with BOP part_type
        $item = BomItem::where('project_id', $project->id)->first();
        $this->assertNotNull($item);
        $this->assertEquals('BOP', $item->part_type);
        $this->assertEquals('BOP-VALVE-01', $item->standard_part_no);
    }

    public function test_incremental_revision_for_remaining_parts_skips_unchanged_and_adds_new(): void
    {
        $admin = $this->getAdminUser();
        $this->actingAs($admin, 'sanctum');

        $projCode = 'TEST-REVISION-' . uniqid();

        // 1. Initial BOP intake: Part A (qty 5)
        $rows1 = [
            ['project_code' => $projCode, 'jig_no' => 'JIG-01', 'unit_no' => '01', 'part_no' => 'BOP-PART-A', 'side' => 'COMMON', 'qty' => 5],
        ];
        $filePath1 = $this->createExcelFile($rows1);
        $file1 = new UploadedFile($filePath1, "{$projCode}_BOP.xlsx", 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);

        $import1 = $this->postJson('/api/v1/bom/import', ['file' => $file1]);
        $import1->assertStatus(200);
        $this->assertTrue($import1->json('success'));
        $this->assertEquals('BOP', $import1->json('bom_type'));

        $project = Project::where('project_code', $projCode)->first();
        $this->assertNotNull($project);
        $this->createdProjectIds[] = $project->id;
        $this->assertEquals(1, BomItem::where('project_id', $project->id)->where('part_type', 'BOP')->count());

        // 2. Uploading EXACT same filename is rejected (user rule: existing file name needs to be modified a little bit at the end)
        $fileExactSameName = new UploadedFile($filePath1, "{$projCode}_BOP.xlsx", 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
        $dupRes = $this->postJson('/api/v1/bom/preview', ['file' => $fileExactSameName]);
        $dupRes->assertStatus(200);
        $this->assertTrue($dupRes->json('is_duplicate_filename'));

        // 3. Upload remaining parts with slightly modified filename e.g. _rev1
        // Contains unchanged Part A (qty 5) + newly added Part B (qty 8)
        $rows2 = [
            ['project_code' => $projCode, 'jig_no' => 'JIG-01', 'unit_no' => '01', 'part_no' => 'BOP-PART-A', 'side' => 'COMMON', 'qty' => 5],
            ['project_code' => $projCode, 'jig_no' => 'JIG-01', 'unit_no' => '01', 'part_no' => 'BOP-PART-B', 'side' => 'COMMON', 'qty' => 8],
        ];
        $filePath2 = $this->createExcelFile($rows2);
        $file2 = new UploadedFile($filePath2, "{$projCode}_BOP_rev1.xlsx", 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);

        $preview2 = $this->postJson('/api/v1/bom/preview', ['file' => $file2]);
        $preview2->assertStatus(200);
        $this->assertTrue($preview2->json('success'));
        $this->assertEquals('BOP', $preview2->json('bom_type'));
        $this->assertTrue($preview2->json('is_revision'));
        $this->assertEquals(1, $preview2->json('reconciliation.unchanged_requirements_count')); // Part A unchanged
        $this->assertEquals(1, $preview2->json('reconciliation.new_requirements_count'));       // Part B new

        $import2 = $this->postJson('/api/v1/bom/import', ['file' => $file2]);
        $import2->assertStatus(200);
        $this->assertTrue($import2->json('success'));
        $this->assertEquals('BOP', $import2->json('bom_type'));

        // Confirm database has exactly 2 BOP items (no duplicate Part A!)
        $bopItems = BomItem::where('project_id', $project->id)->where('part_type', 'BOP')->get();
        $this->assertCount(2, $bopItems);
        $this->assertEquals(['BOP-PART-A', 'BOP-PART-B'], $bopItems->pluck('standard_part_no')->sort()->values()->toArray());
    }

    public function test_std_and_mfg_routing_and_isolation(): void
    {
        $admin = $this->getAdminUser();
        $this->actingAs($admin, 'sanctum');

        $projCode = 'TEST-TYPES-' . uniqid();

        // 1. Upload STD file
        $stdRows = [
            ['project_code' => $projCode, 'jig_no' => 'JIG-01', 'unit_no' => '01', 'part_no' => 'HEX-BOLT-M8', 'side' => 'COMMON', 'qty' => 20],
        ];
        $stdPath = $this->createExcelFile($stdRows);
        $stdFile = new UploadedFile($stdPath, "{$projCode}_STD.xlsx", 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);

        $stdImport = $this->postJson('/api/v1/bom/import', ['file' => $stdFile]);
        $stdImport->assertStatus(200);
        $this->assertTrue($stdImport->json('success'));
        $this->assertEquals('STD', $stdImport->json('bom_type'));

        $project = Project::where('project_code', $projCode)->first();
        $this->assertNotNull($project);
        $this->createdProjectIds[] = $project->id;

        $stdItem = BomItem::where('project_id', $project->id)->first();
        $this->assertEquals('STD', $stdItem->part_type);

        // 2. Upload MFG file for same project
        $mfgRows = [
            ['project_code' => $projCode, 'jig_no' => 'JIG-01', 'unit_no' => '01', 'part_no' => 'BASE-PLATE-01', 'side' => 'COMMON', 'qty' => 2],
        ];
        $mfgPath = $this->createExcelFile($mfgRows);
        $mfgFile = new UploadedFile($mfgPath, "{$projCode}_MFG.xlsx", 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);

        $mfgImport = $this->postJson('/api/v1/bom/import', ['file' => $mfgFile]);
        $mfgImport->assertStatus(200);
        $this->assertTrue($mfgImport->json('success'));
        $this->assertEquals('MFG', $mfgImport->json('bom_type'));

        // Both items exist under the same project cleanly isolated
        $this->assertEquals(1, BomItem::where('project_id', $project->id)->where('part_type', 'STD')->count());
        $this->assertEquals(1, BomItem::where('project_id', $project->id)->where('part_type', 'MFG')->count());
    }
}
