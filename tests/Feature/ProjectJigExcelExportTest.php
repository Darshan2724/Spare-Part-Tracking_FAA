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
use App\Models\Supplier;
use App\Models\SupplierAssignment;
use App\Models\User;
use App\Services\QuantityCalculationService;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ProjectJigExcelExportTest extends TestCase
{
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

            // Validate row 2 headers across 14 columns A through N
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
                'L2' => 'Assembly Completed',
                'M2' => 'ECN',
                'N2' => 'Project Completion %',
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

                    // Verify per-Jig column sums for E..M
                    $cols = ['E', 'F', 'G', 'H', 'I', 'J', 'K', 'L', 'M'];
                    foreach ($cols as $col) {
                        $expectedSum = 0;
                        foreach ($dataRows as $dr) {
                            $expectedSum += (int) $sheet->getCell("{$col}{$dr}")->getValue();
                        }
                        $actualSum = (int) $sheet->getCell("{$col}{$totalRow}")->getValue();
                        $this->assertEquals($expectedSum, $actualSum, "TOTAL mismatch at row {$totalRow} column {$col}");
                    }

                    // Verify Project Completion % in column N is numeric and properly formatted
                    $pctVal = $sheet->getCell("N{$totalRow}")->getValue();
                    $this->assertIsNumeric($pctVal, "Column N in TOTAL row should be numeric ratio");
                    $this->assertGreaterThanOrEqual(0, (float)$pctVal);
                    $this->assertLessThanOrEqual(1.0, (float)$pctVal);
                    $this->assertEquals('0.0%', $sheet->getStyle("N{$totalRow}")->getNumberFormat()->getFormatCode());
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

    public function test_assembly_vs_assembly_completed_column_separation()
    {
        $user = $this->getAdminUser();
        $this->actingAs($user, 'sanctum');

        $project = Project::create([
            'project_code' => 'TEST-SEP-' . uniqid(),
            'name' => 'Assembly Separation Test Project',
            'status' => 'active',
        ]);

        $bomItem = BomItem::create([
            'project_id' => $project->id,
            'standard_part_no' => 'PART-SEP-001',
            'item_no' => '10',
            'jig_no' => 'JIG-SEP-01',
            'unit_no' => 'Unit 01',
        ]);

        BomRequirement::create([
            'bom_item_id' => $bomItem->id,
            'side' => 'RH',
            'required_quantity' => 10,
        ]);

        $receipt = Receipt::create([
            'project_id' => $project->id,
            'delivery_note_number' => 'DN-SEP-' . uniqid(),
            'received_by' => $user->id,
            'status' => 'completed',
        ]);

        $receiptItem = ReceiptItem::create([
            'receipt_id' => $receipt->id,
            'bom_item_id' => $bomItem->id,
            'side' => 'RH',
            'received_quantity' => 10,
            'status' => 'paint_completed',
        ]);

        $inspection = QcInspection::create([
            'receipt_item_id' => $receiptItem->id,
            'bom_item_id' => $bomItem->id,
            'inspected_by' => $user->id,
            'side' => 'RH',
            'result' => 'approved',
            'destination' => 'PAINT',
            'inspected_quantity' => 10,
            'approved_quantity' => 10,
            'inspection_date' => now()->toDateString(),
        ]);

        $paintRecord = PaintRecord::create([
            'bom_item_id' => $bomItem->id,
            'qc_inspection_id' => $inspection->id,
            'side' => 'RH',
            'quantity' => 10,
            'painted_by' => $user->id,
            'status' => 'completed',
            'completed_at' => now(),
        ]);

        // Complete 4 parts in Assembly out of 10
        // Remaining in-assembly ready = 10 - 4 = 6
        AssemblyRecord::create([
            'bom_item_id' => $bomItem->id,
            'paint_record_id' => $paintRecord->id,
            'side' => 'RH',
            'quantity' => 4,
            'assembled_by' => $user->id,
            'status' => 'completed',
            'completed_at' => now(),
        ]);

        $response = $this->getJson('/api/v1/export/project-jigs?project_id=' . $project->id);
        $response->assertStatus(200);

        $tempFile = tempnam(sys_get_temp_dir(), 'jig_sep_') . '.xlsx';
        file_put_contents($tempFile, $response->streamedContent());

        try {
            $spreadsheet = IOFactory::load($tempFile);
            $sheet = $spreadsheet->getActiveSheet();

            // Check headers
            $this->assertEquals('Assembly', $sheet->getCell('K2')->getValue());
            $this->assertEquals('Assembly Completed', $sheet->getCell('L2')->getValue());

            // Check Row 3 (Fixture RH row)
            $this->assertEquals(10, (int)$sheet->getCell('E3')->getValue()); // Total
            $this->assertEquals(10, (int)$sheet->getCell('F3')->getValue()); // Received
            $this->assertEquals(0, (int)$sheet->getCell('G3')->getValue());  // Pending
            $this->assertEquals(6, (int)$sheet->getCell('K3')->getValue());  // Assembly (in department)
            $this->assertEquals(4, (int)$sheet->getCell('L3')->getValue());  // Assembly Completed
            $this->assertEquals(0.4, round((float)$sheet->getCell('N3')->getValue(), 4)); // Project Completion % (4 / 10 = 40.0%)

            // Check Row 4 (TOTAL row)
            $this->assertEquals('TOTAL', $sheet->getCell('A4')->getValue());
            $this->assertEquals(10, (int)$sheet->getCell('E4')->getValue());
            $this->assertEquals(6, (int)$sheet->getCell('K4')->getValue());
            $this->assertEquals(4, (int)$sheet->getCell('L4')->getValue());
            $this->assertEquals(0.4, round((float)$sheet->getCell('N4')->getValue(), 4));
        } finally {
            if (file_exists($tempFile)) {
                unlink($tempFile);
            }
        }
    }

    public function test_single_combined_project_completion_percentage_matches_website()
    {
        $user = $this->getAdminUser();
        $this->actingAs($user, 'sanctum');

        $project = Project::create([
            'project_code' => 'TEST-PCT-' . uniqid(),
            'name' => 'Project Completion Match Test',
            'status' => 'active',
        ]);

        // Jig 1: 10 required, 6 assembly completed (60% individually)
        $bomItem1 = BomItem::create([
            'project_id' => $project->id,
            'standard_part_no' => 'PART-PCT-001',
            'item_no' => '1',
            'jig_no' => 'JIG-01',
            'unit_no' => 'Unit 01',
        ]);
        BomRequirement::create([
            'bom_item_id' => $bomItem1->id,
            'side' => 'RH',
            'required_quantity' => 10,
        ]);

        $receipt1 = Receipt::create([
            'project_id' => $project->id,
            'delivery_note_number' => 'DN-PCT-1-' . uniqid(),
            'received_by' => $user->id,
            'status' => 'completed',
        ]);
        $receiptItem1 = ReceiptItem::create([
            'receipt_id' => $receipt1->id,
            'bom_item_id' => $bomItem1->id,
            'side' => 'RH',
            'received_quantity' => 10,
            'status' => 'paint_completed',
        ]);
        $inspection1 = QcInspection::create([
            'receipt_item_id' => $receiptItem1->id,
            'bom_item_id' => $bomItem1->id,
            'inspected_by' => $user->id,
            'side' => 'RH',
            'result' => 'approved',
            'destination' => 'PAINT',
            'inspected_quantity' => 10,
            'approved_quantity' => 10,
            'inspection_date' => now()->toDateString(),
        ]);
        $paint1 = PaintRecord::create([
            'bom_item_id' => $bomItem1->id,
            'qc_inspection_id' => $inspection1->id,
            'side' => 'RH',
            'quantity' => 10,
            'painted_by' => $user->id,
            'status' => 'completed',
            'completed_at' => now(),
        ]);
        AssemblyRecord::create([
            'bom_item_id' => $bomItem1->id,
            'paint_record_id' => $paint1->id,
            'side' => 'RH',
            'quantity' => 6,
            'assembled_by' => $user->id,
            'status' => 'completed',
            'completed_at' => now(),
        ]);

        // Jig 2: 30 required, 2 assembly completed (6.67% individually)
        $bomItem2 = BomItem::create([
            'project_id' => $project->id,
            'standard_part_no' => 'PART-PCT-002',
            'item_no' => '2',
            'jig_no' => 'JIG-02',
            'unit_no' => 'Unit 02',
        ]);
        BomRequirement::create([
            'bom_item_id' => $bomItem2->id,
            'side' => 'LH',
            'required_quantity' => 30,
        ]);

        $receipt2 = Receipt::create([
            'project_id' => $project->id,
            'delivery_note_number' => 'DN-PCT-2-' . uniqid(),
            'received_by' => $user->id,
            'status' => 'completed',
        ]);
        $receiptItem2 = ReceiptItem::create([
            'receipt_id' => $receipt2->id,
            'bom_item_id' => $bomItem2->id,
            'side' => 'LH',
            'received_quantity' => 30,
            'status' => 'paint_completed',
        ]);
        $inspection2 = QcInspection::create([
            'receipt_item_id' => $receiptItem2->id,
            'bom_item_id' => $bomItem2->id,
            'inspected_by' => $user->id,
            'side' => 'LH',
            'result' => 'approved',
            'destination' => 'PAINT',
            'inspected_quantity' => 30,
            'approved_quantity' => 30,
            'inspection_date' => now()->toDateString(),
        ]);
        $paint2 = PaintRecord::create([
            'bom_item_id' => $bomItem2->id,
            'qc_inspection_id' => $inspection2->id,
            'side' => 'LH',
            'quantity' => 30,
            'painted_by' => $user->id,
            'status' => 'completed',
            'completed_at' => now(),
        ]);
        AssemblyRecord::create([
            'bom_item_id' => $bomItem2->id,
            'paint_record_id' => $paint2->id,
            'side' => 'LH',
            'quantity' => 2,
            'assembled_by' => $user->id,
            'status' => 'completed',
            'completed_at' => now(),
        ]);

        // Combined Project Completion: (6 + 2) / (10 + 30) = 8 / 40 = 20.0% (0.2)
        $expectedRatio = 0.2;

        $response = $this->getJson('/api/v1/export/project-jigs?project_id=' . $project->id);
        $response->assertStatus(200);

        $tempFile = tempnam(sys_get_temp_dir(), 'jig_pct_') . '.xlsx';
        file_put_contents($tempFile, $response->streamedContent());

        try {
            $spreadsheet = IOFactory::load($tempFile);
            $sheet = $spreadsheet->getActiveSheet();

            $highestRow = $sheet->getHighestRow();
            $checkedRows = 0;

            for ($r = 3; $r <= $highestRow; $r++) {
                $fixNo = $sheet->getCell("A{$r}")->getValue();
                // Skip header banner rows
                if ($sheet->getCell("B{$r}")->getValue() === null && $fixNo !== null && $sheet->getCell("E{$r}")->getValue() === null) {
                    continue;
                }
                // Check data rows and TOTAL rows
                if ($fixNo !== null && $fixNo !== 'Fix No.') {
                    $pctVal = (float)$sheet->getCell("N{$r}")->getValue();
                    $this->assertEquals($expectedRatio, round($pctVal, 4), "Row {$r} Column N should match project completion ratio of 0.20");
                    $this->assertEquals('0.0%', $sheet->getStyle("N{$r}")->getNumberFormat()->getFormatCode());
                    $checkedRows++;
                }
            }

            $this->assertGreaterThanOrEqual(4, $checkedRows, 'Should have checked at least 4 rows (2 fixture rows + 2 TOTAL rows)');
        } finally {
            if (file_exists($tempFile)) {
                unlink($tempFile);
            }
        }
    }

    public function test_boundary_completion_percentages_zero_and_hundred()
    {
        $user = $this->getAdminUser();
        $this->actingAs($user, 'sanctum');

        // Project with 0% completion
        $projectZero = Project::create([
            'project_code' => 'TEST-ZERO-' . uniqid(),
            'name' => 'Zero Completion Project',
            'status' => 'active',
        ]);
        $bomZero = BomItem::create([
            'project_id' => $projectZero->id,
            'standard_part_no' => 'PART-ZERO-001',
            'item_no' => '1',
            'jig_no' => 'JIG-ZERO',
            'unit_no' => 'Unit 01',
        ]);
        BomRequirement::create([
            'bom_item_id' => $bomZero->id,
            'side' => 'RH',
            'required_quantity' => 10,
        ]);

        $resZero = $this->getJson('/api/v1/export/project-jigs?project_id=' . $projectZero->id);
        $resZero->assertStatus(200);
        $tempZero = tempnam(sys_get_temp_dir(), 'jig_zero_') . '.xlsx';
        file_put_contents($tempZero, $resZero->streamedContent());

        try {
            $spreadsheet = IOFactory::load($tempZero);
            $sheet = $spreadsheet->getActiveSheet();
            $this->assertEquals(0.0, (float)$sheet->getCell('N3')->getValue());
            $this->assertEquals('0.0%', $sheet->getStyle('N3')->getNumberFormat()->getFormatCode());
            $this->assertEquals(0.0, (float)$sheet->getCell('N4')->getValue());
        } finally {
            if (file_exists($tempZero)) {
                unlink($tempZero);
            }
        }

        // Project with 100% completion
        $projectHundred = Project::create([
            'project_code' => 'TEST-100-' . uniqid(),
            'name' => 'Hundred Completion Project',
            'status' => 'active',
        ]);
        $bomHundred = BomItem::create([
            'project_id' => $projectHundred->id,
            'standard_part_no' => 'PART-100-001',
            'item_no' => '1',
            'jig_no' => 'JIG-100',
            'unit_no' => 'Unit 01',
        ]);
        BomRequirement::create([
            'bom_item_id' => $bomHundred->id,
            'side' => 'LH',
            'required_quantity' => 5,
        ]);
        $recHundred = Receipt::create([
            'project_id' => $projectHundred->id,
            'delivery_note_number' => 'DN-100-' . uniqid(),
            'received_by' => $user->id,
            'status' => 'completed',
        ]);
        $recItemHundred = ReceiptItem::create([
            'receipt_id' => $recHundred->id,
            'bom_item_id' => $bomHundred->id,
            'side' => 'LH',
            'received_quantity' => 5,
            'status' => 'paint_completed',
        ]);
        $inspectionHundred = QcInspection::create([
            'receipt_item_id' => $recItemHundred->id,
            'bom_item_id' => $bomHundred->id,
            'inspected_by' => $user->id,
            'side' => 'LH',
            'result' => 'approved',
            'destination' => 'PAINT',
            'inspected_quantity' => 5,
            'approved_quantity' => 5,
            'inspection_date' => now()->toDateString(),
        ]);
        $paintHundred = PaintRecord::create([
            'bom_item_id' => $bomHundred->id,
            'qc_inspection_id' => $inspectionHundred->id,
            'side' => 'LH',
            'quantity' => 5,
            'painted_by' => $user->id,
            'status' => 'completed',
            'completed_at' => now(),
        ]);
        AssemblyRecord::create([
            'bom_item_id' => $bomHundred->id,
            'paint_record_id' => $paintHundred->id,
            'side' => 'LH',
            'quantity' => 5,
            'assembled_by' => $user->id,
            'status' => 'completed',
            'completed_at' => now(),
        ]);

        $resHundred = $this->getJson('/api/v1/export/project-jigs?project_id=' . $projectHundred->id);
        $resHundred->assertStatus(200);
        $tempHundred = tempnam(sys_get_temp_dir(), 'jig_hundred_') . '.xlsx';
        file_put_contents($tempHundred, $resHundred->streamedContent());

        try {
            $spreadsheet = IOFactory::load($tempHundred);
            $sheet = $spreadsheet->getActiveSheet();
            $this->assertEquals(1.0, (float)$sheet->getCell('N3')->getValue());
            $this->assertEquals('0.0%', $sheet->getStyle('N3')->getNumberFormat()->getFormatCode());
            $this->assertEquals(1.0, (float)$sheet->getCell('N4')->getValue());
        } finally {
            if (file_exists($tempHundred)) {
                unlink($tempHundred);
            }
        }
    }

    public function test_zero_n_plus_one_queries_during_jig_export()
    {
        $user = $this->getAdminUser();
        $this->actingAs($user, 'sanctum');

        $project = Project::create([
            'project_code' => 'TEST-SCALE-' . uniqid(),
            'name' => 'Scale Query Test Project',
            'status' => 'active',
        ]);

        // Create 5 separate Jigs
        for ($i = 1; $i <= 5; $i++) {
            $bom = BomItem::create([
                'project_id' => $project->id,
                'standard_part_no' => "PART-SCALE-{$i}",
                'item_no' => (string)$i,
                'jig_no' => "JIG-SCALE-{$i}",
                'unit_no' => "Unit {$i}",
            ]);
            BomRequirement::create([
                'bom_item_id' => $bom->id,
                'side' => 'RH',
                'required_quantity' => 10,
            ]);
        }

        DB::flushQueryLog();
        DB::enableQueryLog();

        $response = $this->getJson('/api/v1/export/project-jigs?project_id=' . $project->id);
        $response->assertStatus(200);

        $queries = DB::getQueryLog();
        $queryCount = count($queries);

        // Even with 5 Jigs, queries should remain strictly bounded (zero per-jig database queries)
        $this->assertLessThanOrEqual(25, $queryCount, "Export executed {$queryCount} queries for 5 Jigs. Expected <= 25 queries due to batch preloading.");
    }
}
