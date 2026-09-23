<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Project;
use App\Models\BomItem;
use App\Models\BomRequirement;
use App\Services\ExportService;
use App\Services\KpiDrilldownService;
use Illuminate\Http\Request;
use Tests\TestCase;

class KpiDrilldownExportScopeTest extends TestCase
{
    protected User $adminUser;
    protected Project $project;
    protected BomItem $mfgPart;
    protected BomItem $bopPart;
    protected BomItem $stdPart;

    protected function setUp(): void
    {
        parent::setUp();

        $this->adminUser = User::firstOrCreate(
            ['email' => 'admin_test_scope@sparetrack.internal'],
            ['name' => 'Admin Test Scope', 'password' => bcrypt('password')]
        );
        $this->adminUser->assignRole('ADMIN');

        $this->project = Project::create([
            'project_code' => 'TEST-SCOPE-' . uniqid(),
            'name' => 'Export Scope Test Project',
            'status' => 'active',
        ]);

        // Create 1 MFG part
        $this->mfgPart = BomItem::create([
            'project_id' => $this->project->id,
            'standard_part_no' => 'MFG-PART-001',
            'item_no' => '1',
            'jig_no' => 'JIG-01',
            'unit_no' => 'Unit 01',
            'part_type' => 'MFG',
        ]);
        BomRequirement::create([
            'bom_item_id' => $this->mfgPart->id,
            'side' => 'RH',
            'required_quantity' => 10,
        ]);

        // Create 1 BOP part
        $this->bopPart = BomItem::create([
            'project_id' => $this->project->id,
            'standard_part_no' => 'BOP-PART-001',
            'item_no' => '2',
            'jig_no' => 'JIG-01',
            'unit_no' => 'Unit 01',
            'part_type' => 'BOP',
        ]);
        BomRequirement::create([
            'bom_item_id' => $this->bopPart->id,
            'side' => 'RH',
            'required_quantity' => 5,
        ]);

        // Create 1 STD part
        $this->stdPart = BomItem::create([
            'project_id' => $this->project->id,
            'standard_part_no' => 'STD-PART-001',
            'item_no' => '3',
            'jig_no' => 'JIG-01',
            'unit_no' => 'Unit 01',
            'part_type' => 'STD',
        ]);
        BomRequirement::create([
            'bom_item_id' => $this->stdPart->id,
            'side' => 'RH',
            'required_quantity' => 8,
        ]);
    }

    public function test_mfg_pending_kpi_export_scope_contains_only_mfg_parts(): void
    {
        $this->actingAs($this->adminUser, 'sanctum');

        // 1. Check popup drilldown API with part_type=MFG
        $res = $this->getJson("/api/v1/dashboard/kpi-drilldown?kpi=parts_pending&project_id={$this->project->id}&part_type=MFG");
        $res->assertStatus(200);
        $data = $res->json('data');
        $this->assertCount(1, $data);
        $this->assertEquals('MFG-PART-001', $data[0]['part_no']);
        $this->assertEquals('MFG', $data[0]['part_type']);

        // 2. Check export data payload directly through ExportService
        $exportService = app(ExportService::class);
        $req = new Request([
            'kpi' => 'parts_pending',
            'project_id' => $this->project->id,
            'part_type' => 'MFG',
        ]);
        $req->setUserResolver(fn() => $this->adminUser);

        $exportData = $exportService->exportKpiDrilldownData($req);
        $this->assertStringContainsString('MFG', $exportData['filename']);
        $this->assertCount(1, $exportData['rows']);
        $this->assertEquals('MFG-PART-001', $exportData['rows'][0]['part_no']);
        $this->assertEquals('MFG', $exportData['rows'][0]['part_type']);

        // Active filters should indicate part_type
        $this->assertStringContainsString('Type: MFG', $exportData['active_filters']);
    }

    public function test_bop_pending_kpi_export_scope_contains_only_bop_parts(): void
    {
        $this->actingAs($this->adminUser, 'sanctum');

        $res = $this->getJson("/api/v1/dashboard/kpi-drilldown?kpi=parts_pending&project_id={$this->project->id}&part_type=BOP");
        $res->assertStatus(200);
        $data = $res->json('data');
        $this->assertCount(1, $data);
        $this->assertEquals('BOP-PART-001', $data[0]['part_no']);
        $this->assertEquals('BOP', $data[0]['part_type']);

        $exportService = app(ExportService::class);
        $req = new Request([
            'kpi' => 'parts_pending',
            'project_id' => $this->project->id,
            'part_type' => 'BOP',
        ]);
        $req->setUserResolver(fn() => $this->adminUser);

        $exportData = $exportService->exportKpiDrilldownData($req);
        $this->assertStringContainsString('BOP', $exportData['filename']);
        $this->assertCount(1, $exportData['rows']);
        $this->assertEquals('BOP-PART-001', $exportData['rows'][0]['part_no']);
        $this->assertEquals('BOP', $exportData['rows'][0]['part_type']);
        $this->assertStringContainsString('Type: BOP', $exportData['active_filters']);
    }

    public function test_std_pending_kpi_export_scope_contains_only_std_parts(): void
    {
        $this->actingAs($this->adminUser, 'sanctum');

        $res = $this->getJson("/api/v1/dashboard/kpi-drilldown?kpi=parts_pending&project_id={$this->project->id}&part_type=STD");
        $res->assertStatus(200);
        $data = $res->json('data');
        $this->assertCount(1, $data);
        $this->assertEquals('STD-PART-001', $data[0]['part_no']);
        $this->assertEquals('STD', $data[0]['part_type']);

        $exportService = app(ExportService::class);
        $req = new Request([
            'kpi' => 'parts_pending',
            'project_id' => $this->project->id,
            'part_type' => 'STD',
        ]);
        $req->setUserResolver(fn() => $this->adminUser);

        $exportData = $exportService->exportKpiDrilldownData($req);
        $this->assertStringContainsString('STD', $exportData['filename']);
        $this->assertCount(1, $exportData['rows']);
        $this->assertEquals('STD-PART-001', $exportData['rows'][0]['part_no']);
        $this->assertEquals('STD', $exportData['rows'][0]['part_type']);
        $this->assertStringContainsString('Type: STD', $exportData['active_filters']);
    }

    public function test_all_types_pending_kpi_export_contains_all_types(): void
    {
        $this->actingAs($this->adminUser, 'sanctum');

        $res = $this->getJson("/api/v1/dashboard/kpi-drilldown?kpi=parts_pending&project_id={$this->project->id}&part_type=ALL");
        $res->assertStatus(200);
        $data = $res->json('data');
        $this->assertCount(3, $data);

        $exportService = app(ExportService::class);
        $req = new Request([
            'kpi' => 'parts_pending',
            'project_id' => $this->project->id,
            'part_type' => 'ALL',
        ]);
        $req->setUserResolver(fn() => $this->adminUser);

        $exportData = $exportService->exportKpiDrilldownData($req);
        $this->assertCount(3, $exportData['rows']);
        $partTypes = collect($exportData['rows'])->pluck('part_type')->sort()->values()->all();
        $this->assertEquals(['BOP', 'MFG', 'STD'], $partTypes);
    }

    public function test_export_endpoint_stream_passes_part_type_filter(): void
    {
        $this->actingAs($this->adminUser, 'sanctum');

        $response = $this->get("/api/v1/dashboard/kpi-drilldown/export?kpi=parts_pending&project_id={$this->project->id}&part_type=MFG");
        $response->assertStatus(200);
        $response->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $disposition = $response->headers->get('content-disposition', '');
        $this->assertStringContainsString('MFG', $disposition);
    }
}
