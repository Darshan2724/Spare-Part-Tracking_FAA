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

        $project = Project::where('status', 'active')->first() ?? Project::first();
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
            
            // Validate row 5 headers
            $expectedHeaders = [
                'A5' => 'Fix No.',
                'B5' => 'Design Release Date',
                'C5' => 'Supplier Name',
                'D5' => 'Mfg Receipt Date',
                'E5' => 'Total',
                'F5' => 'Received',
                'G5' => 'Pending',
                'H5' => 'Quality',
                'I5' => 'Rework',
                'J5' => 'Paintshop',
                'K5' => 'Assembly',
                'L5' => 'ECN',
            ];

            foreach ($expectedHeaders as $cell => $expectedText) {
                $this->assertEquals($expectedText, $sheet->getCell($cell)->getValue(), "Header mismatch at {$cell}");
            }

            // Find last row (which should be TOTAL row)
            $highestRow = $sheet->getHighestRow();
            $this->assertGreaterThanOrEqual(6, $highestRow, 'Should have at least 1 data row or TOTAL row.');

            $totalCellVal = $sheet->getCell("A{$highestRow}")->getValue();
            $this->assertEquals('TOTAL', $totalCellVal, 'Final row must be labeled TOTAL.');

            // Verify TOTAL row values equal sum of columns
            $cols = ['E', 'F', 'G', 'H', 'I', 'J', 'K', 'L'];
            foreach ($cols as $col) {
                $colSum = 0;
                for ($r = 6; $r < $highestRow; $r++) {
                    $val = (int) $sheet->getCell("{$col}{$r}")->getValue();
                    $colSum += $val;
                }
                $totalRowVal = (int) $sheet->getCell("{$col}{$highestRow}")->getValue();
                $this->assertEquals($colSum, $totalRowVal, "TOTAL sum mismatch for column {$col}");
            }
        } finally {
            if (file_exists($tempFile)) {
                unlink($tempFile);
            }
        }
    }
}
