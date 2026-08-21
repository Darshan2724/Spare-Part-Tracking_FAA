<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\Project;
use App\Models\BomItem;
use App\Models\BomRequirement;
use App\Models\Receipt;
use App\Models\ReceiptItem;
use App\Models\Supplier;
use App\Services\ExportService;
use Illuminate\Http\Request;

class DashboardIndividualBlockExportTest extends TestCase
{
    protected function getAdminUser(): User
    {
        return User::where('email', 'admin@sparetrack.internal')->first();
    }

    protected function ensureTestFixture(): array
    {
        $project = Project::firstOrCreate(
            ['project_code' => 'TEST-BLK-EXP-01'],
            [
                'name' => 'Block Export Test Project',
                'description' => 'Testing 11 individual block exports',
                'status' => 'active',
            ]
        );

        $supplier = Supplier::firstOrCreate(
            ['code' => 'SUP-BLK-EXP-01'],
            ['name' => 'Block Export Test Supplier', 'status' => 'ACTIVE']
        );

        $bomItems = [];
        for ($i = 1; $i <= 15; $i++) {
            $partNo = sprintf('EXP-PART-%03d', $i);
            $bom = BomItem::firstOrCreate(
                ['project_id' => $project->id, 'standard_part_no' => $partNo],
                [
                    'item_no' => 'ITEM-' . $i,
                    'supplier_id' => $supplier->id,
                    'jig_no' => 'JIG-100',
                    'unit_no' => '01',
                    'side' => 'BOTH',
                    'total_required' => 4,
                    'total_received' => 0,
                    'total_pending' => 4,
                ]
            );

            // LH requirement with qty 2
            BomRequirement::firstOrCreate(
                ['bom_item_id' => $bom->id, 'side' => 'LH'],
                ['required_quantity' => 2, 'received_quantity' => 0, 'pending_quantity' => 2]
            );

            // RH requirement with qty 2
            BomRequirement::firstOrCreate(
                ['bom_item_id' => $bom->id, 'side' => 'RH'],
                ['required_quantity' => 2, 'received_quantity' => 0, 'pending_quantity' => 2]
            );

            $bomItems[] = $bom;
        }

        return [$project, $supplier, $bomItems];
    }

    public function test_all_11_dashboard_blocks_have_functional_individual_excel_exports()
    {
        $user = $this->getAdminUser();
        [$project] = $this->ensureTestFixture();

        $blocks = [
            'active_projects',
            'completed_projects',
            'delayed_projects',
            'total_parts',
            'total_parts_received',
            'parts_pending',
            'store',
            'qc',
            'rework',
            'paint',
            'assembly',
        ];

        foreach ($blocks as $blk) {
            $res = $this->actingAs($user, 'sanctum')->post('/api/v1/export/block', [
                'block' => $blk,
                'project_id' => $project->id,
            ]);

            $res->assertStatus(200);
            $this->assertTrue(
                str_contains($res->headers->get('content-disposition'), '.xlsx'),
                "Block [{$blk}] export did not return an xlsx file"
            );
        }
    }

    public function test_total_parts_block_export_reconciles_with_popup_details()
    {
        $user = $this->getAdminUser();
        [$project] = $this->ensureTestFixture();

        // 1. Fetch Popup Details from API
        $detailsRes = $this->actingAs($user, 'sanctum')->getJson("/api/v1/dashboard/block-details?block=total_parts&project_id={$project->id}");
        $detailsRes->assertStatus(200);
        $blockData = $detailsRes->json();

        $expectedRecordCount = count($blockData['items']);
        $expectedTotalQty = $blockData['total_quantity'];

        // 2. Generate and Inspect Excel Output
        $exportService = app(ExportService::class);
        $req = new Request(['block' => 'total_parts', 'project_id' => $project->id]);
        $req->setUserResolver(fn() => $user);

        $tempFile = sys_get_temp_dir() . '/test_blk_total_parts_' . time() . '.xlsx';
        $excelResp = $exportService->generateBlockExcel($blockData, $req);

        ob_start();
        $excelResp->sendContent();
        $content = ob_get_clean();
        file_put_contents($tempFile, $content);

        $reader = \PhpOffice\PhpSpreadsheet\IOFactory::createReaderForFile($tempFile);
        $spreadsheet = $reader->load($tempFile);
        $sheet = $spreadsheet->getActiveSheet();

        // Row 1-3 = Header & Metadata, Row 5 = Table Header, Data rows = Row 6 to (5 + count)
        $expectedLastDataRow = 5 + $expectedRecordCount;
        $expectedSummaryRow = $expectedLastDataRow + 1;

        $this->assertEquals($expectedSummaryRow, $sheet->getHighestRow());

        // Check Part Number format on first data row
        $firstPartNumber = $sheet->getCell('B6')->getValue();
        $this->assertNotEmpty($firstPartNumber);

        @unlink($tempFile);
    }

    public function test_report_section_excel_export_generates_valid_workbook()
    {
        $user = $this->getAdminUser();
        [$project] = $this->ensureTestFixture();

        $res = $this->actingAs($user, 'sanctum')->post('/api/v1/export/report', [
            'project_id' => $project->id,
        ]);

        $res->assertStatus(200);
        $this->assertTrue(
            str_contains($res->headers->get('content-disposition'), '.xlsx'),
            "Report export did not return an xlsx file"
        );

        $exportService = app(ExportService::class);
        $req = new Request(['project_id' => $project->id]);
        $req->setUserResolver(fn() => $user);

        $reportData = $exportService->exportReportData($req);
        $this->assertArrayHasKey('detailed_parts', $reportData);
        $this->assertArrayHasKey('daily_matrix', $reportData);
        $this->assertArrayHasKey('analytics', $reportData);

        $tempFile = sys_get_temp_dir() . '/test_rep_' . time() . '.xlsx';
        $excelResp = $exportService->generateReportExcel($reportData);

        ob_start();
        $excelResp->sendContent();
        $content = ob_get_clean();
        file_put_contents($tempFile, $content);

        $reader = \PhpOffice\PhpSpreadsheet\IOFactory::createReaderForFile($tempFile);
        $spreadsheet = $reader->load($tempFile);

        $this->assertGreaterThanOrEqual(4, $spreadsheet->getSheetCount());
        $this->assertNotNull($spreadsheet->getSheetByName('Report Summary & Parts'));
        $this->assertNotNull($spreadsheet->getSheetByName('Parts Priority Map'));
        $this->assertNotNull($spreadsheet->getSheetByName('Daily Movements Matrix'));
        $this->assertNotNull($spreadsheet->getSheetByName('Production Analytics'));
        $this->assertEquals(0, $spreadsheet->getActiveSheetIndex());
        $this->assertEquals('Report Summary & Parts', $spreadsheet->getActiveSheet()->getTitle());

        @unlink($tempFile);
    }

    public function test_block_export_validation_fails_when_block_param_missing()
    {
        $user = $this->getAdminUser();
        $res = $this->actingAs($user, 'sanctum')->postJson('/api/v1/export/block', []);
        $res->assertStatus(422);
    }
}
