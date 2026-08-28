<?php

namespace Tests\Feature;

use App\Models\EcnImportBatch;
use App\Models\EcnRequirement;
use App\Models\Project;
use App\Models\User;
use App\Services\EcnImportService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class EcnImportTest extends TestCase
{
    use DatabaseTransactions;

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

    protected function createEcnSpreadsheet(array $rows, int $blankRowsBefore = 3, int $blankColsBefore = 2): string
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();

        // Header row
        $headerRow = $blankRowsBefore + 1;
        $headers = ['Project Code', 'ECN NO.', 'Jig', 'Unit No.', 'Part No.', 'Side', 'Qty'];

        for ($i = 0; $i < count($headers); $i++) {
            $col = $blankColsBefore + 1 + $i;
            $sheet->setCellValueByColumnAndRow($col, $headerRow, $headers[$i]);
        }

        // Data rows
        $currentRow = $headerRow + 1;
        foreach ($rows as $row) {
            for ($i = 0; $i < count($row); $i++) {
                $col = $blankColsBefore + 1 + $i;
                $sheet->setCellValueByColumnAndRow($col, $currentRow, $row[$i]);
            }
            $currentRow++;
        }

        $tempFile = tempnam(sys_get_temp_dir(), 'ecn_test_') . '.xlsx';
        $writer = new Xlsx($spreadsheet);
        $writer->save($tempFile);

        return $tempFile;
    }

    public function test_valid_ecn_workbook_preview_and_import()
    {
        $user = $this->getAdminUser();
        $this->actingAs($user, 'sanctum');

        $code = 'ECNPROJ-' . uniqid();
        $project = Project::create(
            ['project_code' => $code, 'name' => "Project {$code}", 'status' => 'active']
        );

        $dataRows = [
            [$code, 'ECN-1', 'LIMOFD20', '07', '05', 'LA', 2],
            [$code, 'ECN-1', 'LIMOFD20', '08', '08', 'RA', 3],
            [$code, 'ECN-2', 'LIMORD10', '08', '02', 'L', 1],
            [$code, 'ECN-2', 'LIMORD10', '09', '02', 'R', 1],
        ];

        $uniqueName = 'ECN_TEST_' . uniqid() . '.xlsx';
        $filePath = $this->createEcnSpreadsheet($dataRows);
        $file = new UploadedFile($filePath, $uniqueName, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);

        // 1. Preview
        $previewRes = $this->postJson('/api/v1/ecn/preview', ['file' => $file]);
        $previewRes->assertStatus(200);
        $this->assertTrue($previewRes->json('success'));
        $this->assertCount(4, $previewRes->json('rows'));
        $this->assertEquals(4, $previewRes->json('summary.valid_rows'));

        // 2. Import
        $fileForImport = new UploadedFile($filePath, $uniqueName, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
        $importRes = $this->postJson('/api/v1/ecn/import', ['file' => $fileForImport]);
        $importRes->assertStatus(200);
        $this->assertTrue($importRes->json('success'));
        $this->assertEquals(4, $importRes->json('added_count'));

        // Verify in database
        $this->assertDatabaseHas('ecn_requirements', [
            'project_id' => $project->id,
            'ecn_number' => 'ECN-1',
            'jig_no' => 'LIMOFD20',
            'unit_no' => '07',
            'part_no' => '05',
            'side' => 'LA',
            'side_display' => 'LH',
            'required_qty' => 2,
        ]);

        $this->assertDatabaseHas('ecn_requirements', [
            'project_id' => $project->id,
            'ecn_number' => 'ECN-1',
            'jig_no' => 'LIMOFD20',
            'unit_no' => '08',
            'part_no' => '08',
            'side' => 'RA',
            'side_display' => 'RH',
            'required_qty' => 3,
        ]);

        if (file_exists($filePath)) {
            unlink($filePath);
        }
    }

    public function test_missing_project_rejects_import()
    {
        $user = $this->getAdminUser();
        $this->actingAs($user, 'sanctum');

        $dataRows = [
            ['NON_EXISTENT_PROJECT_999', 'ECN-1', 'JIG1', '01', '01', 'LA', 1],
        ];

        $filePath = $this->createEcnSpreadsheet($dataRows);
        $file = new UploadedFile($filePath, 'ECN_NON_EXISTENT.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);

        $previewRes = $this->postJson('/api/v1/ecn/preview', ['file' => $file]);
        $previewRes->assertStatus(200);
        $this->assertFalse($previewRes->json('success'));
        $this->assertStringContainsString('not found', $previewRes->json('errors.0'));

        if (file_exists($filePath)) {
            unlink($filePath);
        }
    }

    public function test_invalid_side_rejects_import()
    {
        $user = $this->getAdminUser();
        $this->actingAs($user, 'sanctum');

        Project::firstOrCreate(
            ['project_code' => 'FA-273'],
            ['name' => 'Project FA-273', 'status' => 'active']
        );

        $dataRows = [
            ['FA-273', 'ECN-1', 'JIG1', '01', '01', 'INVALID_SIDE', 1],
        ];

        $filePath = $this->createEcnSpreadsheet($dataRows);
        $file = new UploadedFile($filePath, 'ECN_INVALID_SIDE.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);

        $previewRes = $this->postJson('/api/v1/ecn/preview', ['file' => $file]);
        $previewRes->assertStatus(200);
        $this->assertFalse($previewRes->json('success'));
        $this->assertStringContainsString('Invalid Side', $previewRes->json('errors.0'));

        if (file_exists($filePath)) {
            unlink($filePath);
        }
    }

    public function test_all_six_side_values_accepted_and_mapped_correctly()
    {
        $this->assertEquals('LH', EcnImportService::mapSideDisplay('LA'));
        $this->assertEquals('LH', EcnImportService::mapSideDisplay('AL'));
        $this->assertEquals('LH', EcnImportService::mapSideDisplay('L'));
        $this->assertEquals('RH', EcnImportService::mapSideDisplay('RA'));
        $this->assertEquals('RH', EcnImportService::mapSideDisplay('AR'));
        $this->assertEquals('RH', EcnImportService::mapSideDisplay('R'));

        $this->assertEquals('LEFT', EcnImportService::mapSideFamily('LA'));
        $this->assertEquals('LEFT', EcnImportService::mapSideFamily('AL'));
        $this->assertEquals('LEFT', EcnImportService::mapSideFamily('L'));
        $this->assertEquals('RIGHT', EcnImportService::mapSideFamily('RA'));
        $this->assertEquals('RIGHT', EcnImportService::mapSideFamily('AR'));
        $this->assertEquals('RIGHT', EcnImportService::mapSideFamily('R'));
    }

    public function test_actual_mfg_ecn_master_sheet_parsing()
    {
        $user = $this->getAdminUser();
        $this->actingAs($user, 'sanctum');

        $actualFilePath = base_path('BOM/MFG ECN MASTER SHEET.xlsx');
        if (!file_exists($actualFilePath)) {
            $this->markTestSkipped('BOM/MFG ECN MASTER SHEET.xlsx file not present.');
        }

        Project::firstOrCreate(
            ['project_code' => 'FA-273'],
            ['name' => 'Project FA-273', 'status' => 'active']
        );

        $service = new EcnImportService();
        $extracted = $service->extractAndValidateRows($actualFilePath, 'MFG ECN MASTER SHEET.xlsx');

        $this->assertTrue($extracted['success']);
        $this->assertEquals(94, $extracted['summary']['valid_rows']);
        $this->assertEquals(94, $extracted['summary']['total_qty']);
        $this->assertEquals(['ECN-1', 'ECN-3', 'ECN-17', 'ECN-40'], $extracted['summary']['unique_ecn_numbers']);
    }
}
