<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\User;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

class ProjectJigExcelExportTest extends TestCase
{
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

    public function test_unauthenticated_request_is_rejected()
    {
        $response = $this->getJson('/api/v1/export/project-jigs?project_id=1');
        $response->assertStatus(401);
    }

    public function test_missing_project_id_returns_validation_error()
    {
        $user = $this->getAdminUser();
        $this->actingAs($user, 'sanctum');

        $response = $this->getJson('/api/v1/export/project-jigs');
        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['project_id']);
    }

    public function test_project_jigs_excel_export_structure_and_blank_handling()
    {
        $user = $this->getAdminUser();
        $this->actingAs($user, 'sanctum');

        $project = Project::where('status', 'active')->has('bomItems')->first() ?? Project::first();
        $this->assertNotNull($project, 'At least one project should exist.');

        $response = $this->getJson('/api/v1/export/project-jigs?project_id=' . $project->id);
        $response->assertStatus(200);

        $contentType = $response->headers->get('content-type', '');
        $this->assertTrue(
            str_contains($contentType, 'spreadsheetml') || str_contains($contentType, 'application/vnd.openxmlformats'),
            "Content-Type should be xlsx spreadsheet. Got: {$contentType}"
        );

        $contentDisposition = $response->headers->get('content-disposition', '');
        $this->assertTrue(
            str_contains($contentDisposition, "{$project->project_code}-Jig-Material-Status.xlsx"),
            "Disposition should contain expected filename. Got: {$contentDisposition}"
        );

        // Capture streamed content and parse with PhpSpreadsheet
        $streamedContent = $response->streamedContent();
        $this->assertNotEmpty($streamedContent);

        $tempFile = tempnam(sys_get_temp_dir(), 'jig_export_') . '.xlsx';
        file_put_contents($tempFile, $streamedContent);

        try {
            $spreadsheet = IOFactory::load($tempFile);
            
            // Exactly 1 sheet
            $this->assertEquals(1, $spreadsheet->getSheetCount(), 'Excel should contain exactly 1 sheet.');
            
            $sheet = $spreadsheet->getActiveSheet();
            
            // Validate row 1 is the Jig Name banner (e.g. non-empty string)
            $jigBanner1 = $sheet->getCell('A1')->getValue();
            $this->assertNotEmpty($jigBanner1, 'Row 1 should be the Jig Name banner on top.');

            // Validate row 2 headers matching reference layout
            $expectedHeaders = [
                'A2' => 'Fix No.',
                'B2' => 'Design Release date',
                'C2' => 'Supplier Name',
                'D2' => 'Mfg Receipt Date',
                'E2' => 'Total',
                'F2' => 'Received',
                'G2' => 'Pending',
                'H2' => 'Quality',
                'I2' => 'Rework',
                'J2' => 'Paintshop',
                'K2' => 'Assembly',
                'L2' => 'ECN',
            ];

            foreach ($expectedHeaders as $cell => $expectedText) {
                $this->assertEquals($expectedText, $sheet->getCell($cell)->getValue(), "Header mismatch at {$cell}");
            }

            // Find all TOTAL rows and verify per-Jig arithmetic
            $highestRow = $sheet->getHighestRow();
            $totalRowsFound = 0;
            $currentRow = 1;

            while ($currentRow <= $highestRow) {
                // Find next TOTAL row
                if ($sheet->getCell("A{$currentRow}")->getValue() === 'TOTAL') {
                    $totalRowsFound++;
                    $totalRow = $currentRow;

                    // Trace upward to find the start of this Jig's data rows
                    $r = $totalRow - 1;
                    $dataRows = [];
                    while ($r >= 1 && $sheet->getCell("A{$r}")->getValue() !== 'Fix No.') {
                        $val = $sheet->getCell("A{$r}")->getValue();
                        if (!empty($val) && $val !== 'TOTAL') {
                            $dataRows[] = $r;
                        }
                        $r--;
                    }

                    // Verify per-Jig column sums for E..L
                    $cols = ['E', 'F', 'G', 'H', 'I', 'J', 'K', 'L'];
                    foreach ($cols as $col) {
                        $expectedSum = 0;
                        foreach ($dataRows as $dr) {
                            $expectedSum += (int) $sheet->getCell("{$col}{$dr}")->getValue();
                        }
                        $actualSum = (int) $sheet->getCell("{$col}{$totalRow}")->getValue();
                        $this->assertEquals($expectedSum, $actualSum, "TOTAL mismatch at row {$totalRow} column {$col}");
                    }
                }
                $currentRow++;
            }

            $this->assertGreaterThanOrEqual(1, $totalRowsFound, 'Should find at least 1 Jig TOTAL row.');
        } finally {
            if (file_exists($tempFile)) {
                unlink($tempFile);
            }
        }
    }
}
