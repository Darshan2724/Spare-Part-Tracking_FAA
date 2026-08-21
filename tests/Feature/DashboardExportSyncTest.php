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

class DashboardExportSyncTest extends TestCase
{
    protected function getAdminUser(): User
    {
        return User::where('email', 'admin@sparetrack.internal')->first();
    }

    protected function ensureTestProjectWithBOM(): array
    {
        $project = Project::firstOrCreate(
            ['project_code' => 'TEST-PRJ-01'],
            [
                'name' => 'Test Automation Project',
                'description' => 'Test project for export sync validation',
                'status' => 'active',
            ]
        );

        $supplier = Supplier::firstOrCreate(
            ['code' => 'SUP-TEST-01'],
            ['name' => 'Test Supplier Alpha', 'status' => 'ACTIVE']
        );

        $bomItem = BomItem::firstOrCreate(
            ['project_id' => $project->id, 'standard_part_no' => 'TP-1001'],
            [
                'item_no' => 'ITEM-01',
                'supplier_id' => $supplier->id,
                'jig_name' => 'JIG-01',
                'unit_no' => 'UNIT-01',
                'side' => 'COMMON',
                'total_required' => 50,
                'total_received' => 0,
                'total_pending' => 50,
            ]
        );

        BomRequirement::firstOrCreate(
            ['bom_item_id' => $bomItem->id, 'side' => 'COMMON'],
            ['required_quantity' => 50, 'received_quantity' => 0, 'pending_quantity' => 50]
        );

        return [$project, $supplier, $bomItem];
    }

    public function test_dashboard_api_and_export_data_have_exact_synchronization()
    {
        $user = $this->getAdminUser();
        $this->ensureTestProjectWithBOM();

        // 1. Fetch live Dashboard API
        $dashboardResponse = $this->actingAs($user, 'sanctum')->getJson('/api/v1/dashboard/summary');
        $dashboardResponse->assertStatus(200);
        $dashData = $dashboardResponse->json();
        $dashSummary = $dashData['summary'] ?? [];

        // 2. Fetch Export Dataset via ExportService
        $exportService = app(ExportService::class);
        $request = new Request();
        $request->setUserResolver(fn() => $user);
        $exportData = $exportService->exportDashboardData($request);
        $expSummary = $exportData['summary'] ?? [];

        // 3. Verify 100% Parity across all core Dashboard KPIs
        $this->assertEquals($dashSummary['active_projects'], $expSummary['active_projects'], 'Active Projects mismatch');
        $this->assertEquals($dashSummary['completed_projects'], $expSummary['completed_projects'], 'Completed Projects mismatch');
        $this->assertEquals($dashSummary['delayed_projects'], $expSummary['delayed_projects'], 'Delayed Projects mismatch');
        $this->assertEquals($dashSummary['total_parts'], $expSummary['total_parts'], 'Total Parts mismatch');
        $this->assertEquals($dashSummary['total_parts_received'], $expSummary['total_parts_received'], 'Parts Received mismatch');
        $this->assertEquals($dashSummary['parts_pending'], $expSummary['parts_pending'], 'Parts Pending mismatch');
        $this->assertEquals($dashSummary['parts_in_store'], $expSummary['parts_in_store'], 'Store mismatch');
        $this->assertEquals($dashSummary['parts_in_qc'], $expSummary['parts_in_qc'], 'QC mismatch');
        $this->assertEquals($dashSummary['parts_in_rework'], $expSummary['parts_in_rework'], 'Rework mismatch');
        $this->assertEquals($dashSummary['parts_in_paint'], $expSummary['parts_in_paint'], 'Paint mismatch');
        $this->assertEquals($dashSummary['parts_in_assembly'], $expSummary['parts_in_assembly'], 'Assembly mismatch');
        $this->assertEquals($dashSummary['completion_pct'], $expSummary['completion_pct'], 'Completion % mismatch');

        // 4. Verify Project Health Parity
        $dashHealth = $dashData['health_distribution'] ?? [];
        $this->assertEquals($dashHealth['counts']['near_completion'] ?? 0, $exportData['health_counts']['near_completion']);
        $this->assertEquals($dashHealth['counts']['on_track'] ?? 0, $exportData['health_counts']['on_track']);
        $this->assertEquals($dashHealth['counts']['at_risk'] ?? 0, $exportData['health_counts']['at_risk']);
        $this->assertEquals($dashHealth['counts']['delayed'] ?? 0, $exportData['health_counts']['delayed']);

        // 5. Verify Top Projects list
        $dashTop = $dashData['top_projects'] ?? [];
        if (!empty($dashTop['labels'])) {
            $this->assertCount(count($dashTop['labels']), $exportData['top_projects_list']);
            $this->assertEquals($dashTop['required'][0] ?? 0, $exportData['top_projects_list'][0]['required']);
            $this->assertEquals($dashTop['received'][0] ?? 0, $exportData['top_projects_list'][0]['received']);
            $this->assertEquals($dashTop['pending'][0] ?? 0, $exportData['top_projects_list'][0]['pending']);
        }
    }

    public function test_export_endpoints_generate_valid_files_with_matching_data()
    {
        $user = $this->getAdminUser();
        $this->ensureTestProjectWithBOM();

        // Export Excel
        $excelRes = $this->actingAs($user, 'sanctum')->post('/api/v1/export/dashboard', ['format' => 'excel']);
        $excelRes->assertStatus(200);
        $this->assertTrue(str_contains($excelRes->headers->get('content-disposition'), '.xlsx'));

        // Export PDF
        $pdfRes = $this->actingAs($user, 'sanctum')->post('/api/v1/export/dashboard', ['format' => 'pdf']);
        $pdfRes->assertStatus(200);
        $this->assertTrue(str_contains($pdfRes->headers->get('content-disposition'), '.pdf'));
    }

    public function test_dynamic_data_synchronization_when_values_change()
    {
        $user = $this->getAdminUser();
        [$project, $supplier, $bomItem] = $this->ensureTestProjectWithBOM();

        $initialDash = $this->actingAs($user, 'sanctum')->getJson('/api/v1/dashboard/summary')->json()['summary'];
        $initialPartsReceived = $initialDash['total_parts_received'];

        $receipt = Receipt::create([
            'project_id' => $project->id,
            'supplier_id' => $supplier->id,
            'delivery_note_number' => 'DN-TEST-999',
            'received_by' => $user->id,
        ]);

        // Simulate a receipt of 15 parts
        $receiptItem = ReceiptItem::create([
            'receipt_id' => $receipt->id,
            'bom_item_id' => $bomItem->id,
            'received_quantity' => 15,
            'side' => $bomItem->side ?? 'COMMON',
            'status' => 'received',
        ]);

        // Re-fetch Dashboard summary
        $updatedDash = $this->actingAs($user, 'sanctum')->getJson('/api/v1/dashboard/summary')->json()['summary'];
        $this->assertEquals($initialPartsReceived + 15, $updatedDash['total_parts_received']);

        // Re-fetch Export dataset
        $exportService = app(ExportService::class);
        $req = new Request();
        $req->setUserResolver(fn() => $user);
        $updatedExport = $exportService->exportDashboardData($req)['summary'];

        // Assert exact sync between live dashboard and export
        $this->assertEquals($updatedDash['total_parts_received'], $updatedExport['total_parts_received']);
        $this->assertEquals($updatedDash['parts_pending'], $updatedExport['parts_pending']);
        $this->assertEquals($updatedDash['parts_in_store'], $updatedExport['parts_in_store']);

        // Clean up
        $receiptItem->delete();
        $receipt->delete();
    }

    public function test_project_filter_synchronization()
    {
        $user = $this->getAdminUser();
        [$project] = $this->ensureTestProjectWithBOM();

        // Live Dashboard for Project
        $dashRes = $this->actingAs($user, 'sanctum')->getJson("/api/v1/dashboard/summary?project_id={$project->id}");
        $dashRes->assertStatus(200);
        $dashSummary = $dashRes->json()['summary'];

        // Export for Project
        $exportService = app(ExportService::class);
        $req = new Request(['project_id' => $project->id]);
        $req->setUserResolver(fn() => $user);
        $exportData = $exportService->exportDashboardData($req);
        $expSummary = $exportData['summary'];

        $this->assertEquals($dashSummary['total_parts'], $expSummary['total_parts']);
        $this->assertEquals($dashSummary['total_parts_received'], $expSummary['total_parts_received']);
        $this->assertEquals($dashSummary['parts_pending'], $expSummary['parts_pending']);
        $this->assertEquals($project->name, $exportData['project_name']);
        $this->assertEquals($project->project_code, $exportData['project_code']);
    }

    public function test_export_includes_detailed_parts_with_generated_part_numbers_and_quantity()
    {
        $user = $this->getAdminUser();
        $project = Project::firstOrCreate(
            ['project_code' => 'TEST-EXP-DET-01'],
            ['name' => 'Export Detailed Parts Project', 'status' => 'active']
        );

        $bom = BomItem::firstOrCreate(
            [
                'project_id' => $project->id,
                'jig_no' => '169961@',
                'unit_no' => '01',
                'standard_part_no' => '010#R00',
            ],
            [
                'item_no' => '010',
                'side' => 'BOTH',
                'total_required' => 2,
                'total_received' => 0,
                'total_pending' => 2,
            ]
        );

        BomRequirement::firstOrCreate(
            ['bom_item_id' => $bom->id, 'side' => 'LH'],
            ['required_quantity' => 1, 'received_quantity' => 0, 'pending_quantity' => 1]
        );
        BomRequirement::firstOrCreate(
            ['bom_item_id' => $bom->id, 'side' => 'RH'],
            ['required_quantity' => 1, 'received_quantity' => 0, 'pending_quantity' => 1]
        );

        $exportService = app(ExportService::class);
        $req = new Request(['project_id' => $project->id]);
        $req->setUserResolver(fn() => $user);
        $exportData = $exportService->exportDashboardData($req);

        $this->assertArrayHasKey('detailed_parts', $exportData);
        $detailedParts = $exportData['detailed_parts'];
        $this->assertCount(2, $detailedParts);

        $lh = collect($detailedParts)->firstWhere('side', 'LH');
        $rh = collect($detailedParts)->firstWhere('side', 'RH');

        $this->assertNotNull($lh);
        $this->assertNotNull($rh);

        $this->assertEquals('169961@01010#R00LH', $lh['part_number']);
        $this->assertEquals('169961@01010#R00RH', $rh['part_number']);
        $this->assertEquals(1, $lh['quantity']);
        $this->assertEquals(1, $rh['quantity']);

        // Assert 100% total quantity reconciliation
        $this->assertEquals($exportData['summary']['total_parts'], array_sum(array_column($detailedParts, 'quantity')));
    }

    public function test_export_never_truncates_large_datasets_and_exports_all_records_dynamically()
    {
        $user = $this->getAdminUser();
        $project = Project::firstOrCreate(
            ['project_code' => 'TEST-UNLIMITED-01'],
            ['name' => 'Unlimited Export Test Project', 'status' => 'active']
        );

        $supplier = Supplier::firstOrCreate(
            ['code' => 'SUP-UNLIM-01'],
            ['name' => 'Unlimited Test Supplier', 'status' => 'ACTIVE']
        );

        // Create 120 distinct BOM items with side requirements (exceeding any 50, 75, 100 limit)
        $totalExpectedPieces = 0;
        for ($i = 1; $i <= 120; $i++) {
            $partNo = sprintf('UNLIM-%04d', $i);
            $bom = BomItem::firstOrCreate(
                ['project_id' => $project->id, 'standard_part_no' => $partNo],
                [
                    'item_no' => 'ITM-' . $i,
                    'supplier_id' => $supplier->id,
                    'jig_no' => 'JIG-A',
                    'unit_no' => '01',
                    'side' => 'LH',
                    'total_required' => 3,
                    'total_received' => 0,
                    'total_pending' => 3,
                ]
            );

            BomRequirement::firstOrCreate(
                ['bom_item_id' => $bom->id, 'side' => 'LH'],
                ['required_quantity' => 3, 'received_quantity' => 0, 'pending_quantity' => 3]
            );
            $totalExpectedPieces += 3;
        }

        $exportService = app(ExportService::class);
        $req = new Request(['project_id' => $project->id]);
        $req->setUserResolver(fn() => $user);
        $exportData = $exportService->exportDashboardData($req);

        // 1. Assert exactly 120 detailed parts exported (no 50, 75, 100 truncation)
        $this->assertCount(120, $exportData['detailed_parts']);
        $this->assertEquals($totalExpectedPieces, array_sum(array_column($exportData['detailed_parts'], 'quantity')));

        // 2. Test PDF rendering includes all 120 records
        $pdfHtml = view('exports.dashboard_pdf', $exportData)->render();
        preg_match_all('/<td class="font-monospace fw-bold" style="color: #0f172a;">(.*?)<\/td>/', $pdfHtml, $pdfMatches);
        $this->assertCount(120, $pdfMatches[1]);

        // 3. Test Excel generation contains all 120 records
        $tempFile = sys_get_temp_dir() . '/test_unlim_' . time() . '.xlsx';
        $excelResponse = $exportService->generateDashboardExcel($exportData);
        ob_start();
        $excelResponse->sendContent();
        $content = ob_get_clean();
        file_put_contents($tempFile, $content);

        $reader = \PhpOffice\PhpSpreadsheet\IOFactory::createReaderForFile($tempFile);
        $spreadsheet = $reader->load($tempFile);
        $sheetDetailed = $spreadsheet->getSheetByName('Detailed Parts');
        $this->assertNotNull($sheetDetailed);
        // Header is row 3, so rows 4 to 123 = 120 rows
        $this->assertEquals(123, $sheetDetailed->getHighestRow());

        @unlink($tempFile);
    }
}
