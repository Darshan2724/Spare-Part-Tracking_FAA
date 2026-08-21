<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Project;
use App\Models\BomItem;
use App\Models\BomRequirement;
use App\Models\Receipt;
use App\Models\ReceiptItem;
use App\Models\QcInspection;
use App\Models\ReworkRecord;
use App\Models\PaintRecord;
use App\Models\AssemblyRecord;
use App\Services\QuantityCalculationService;
use App\Services\KpiDrilldownService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class KpiDrilldownTest extends TestCase
{
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

    public function test_kpi_drilldown_project_level_cards()
    {
        $user = $this->getAdminUser();
        $this->actingAs($user, 'sanctum');

        // 1. Active Projects
        $resActive = $this->getJson('/api/v1/dashboard/kpi-drilldown?kpi=active_projects');
        $resActive->assertStatus(200)
            ->assertJsonStructure([
                'kpi',
                'kpi_type',
                'project_scope',
                'total_records',
                'total_quantity',
                'columns',
                'data',
            ]);
        $this->assertEquals('active_projects', $resActive->json('kpi'));
        $this->assertEquals('project', $resActive->json('kpi_type'));

        // 2. Completed Projects
        $resCompleted = $this->getJson('/api/v1/dashboard/kpi-drilldown?kpi=completed_projects');
        $resCompleted->assertStatus(200);
        $this->assertEquals('completed_projects', $resCompleted->json('kpi'));

        // 3. Delayed Projects
        $resDelayed = $this->getJson('/api/v1/dashboard/kpi-drilldown?kpi=delayed_projects');
        $resDelayed->assertStatus(200);
        $this->assertEquals('delayed_projects', $resDelayed->json('kpi'));
    }

    public function test_kpi_drilldown_part_level_cards()
    {
        $user = $this->getAdminUser();
        $this->actingAs($user, 'sanctum');

        $kpis = ['total_parts', 'total_parts_received', 'parts_pending', 'store', 'rework', 'paint'];

        foreach ($kpis as $kpi) {
            $res = $this->getJson("/api/v1/dashboard/kpi-drilldown?kpi={$kpi}");
            $res->assertStatus(200)
                ->assertJsonStructure([
                    'kpi',
                    'kpi_type',
                    'project_scope',
                    'total_records',
                    'total_quantity',
                    'columns',
                    'data',
                ]);
            $this->assertEquals($kpi, $res->json('kpi'));
            $this->assertEquals('part', $res->json('kpi_type'));
        }
    }

    public function test_qc_kpi_drilldown_with_substates()
    {
        $user = $this->getAdminUser();
        $this->actingAs($user, 'sanctum');

        // All QC
        $resAll = $this->getJson('/api/v1/dashboard/kpi-drilldown?kpi=qc&substate=all');
        $resAll->assertStatus(200);
        $this->assertEquals('qc', $resAll->json('kpi'));

        // QC Inspection
        $resInsp = $this->getJson('/api/v1/dashboard/kpi-drilldown?kpi=qc&substate=inspection');
        $resInsp->assertStatus(200);
        $this->assertEquals('inspection', $resInsp->json('substate'));

        // QC Rejected
        $resRej = $this->getJson('/api/v1/dashboard/kpi-drilldown?kpi=qc&substate=rejected');
        $resRej->assertStatus(200);
        $this->assertEquals('rejected', $resRej->json('substate'));
    }

    public function test_assembly_kpi_drilldown_with_substates()
    {
        $user = $this->getAdminUser();
        $this->actingAs($user, 'sanctum');

        // All Assembly
        $resAll = $this->getJson('/api/v1/dashboard/kpi-drilldown?kpi=assembly&substate=all');
        $resAll->assertStatus(200);
        $this->assertEquals('assembly', $resAll->json('kpi'));

        // Assembly Queue
        $resQueue = $this->getJson('/api/v1/dashboard/kpi-drilldown?kpi=assembly&substate=queue');
        $resQueue->assertStatus(200);
        $this->assertEquals('queue', $resQueue->json('substate'));

        // Assembly Completed
        $resComp = $this->getJson('/api/v1/dashboard/kpi-drilldown?kpi=assembly&substate=completed');
        $resComp->assertStatus(200);
        $this->assertEquals('completed', $resComp->json('substate'));
    }

    public function test_kpi_drilldown_single_project_filter()
    {
        $user = $this->getAdminUser();
        $this->actingAs($user, 'sanctum');

        $project = Project::where('status', 'active')->first();
        if ($project) {
            $res = $this->getJson("/api/v1/dashboard/kpi-drilldown?kpi=total_parts&project_id={$project->id}");
            $res->assertStatus(200);
            $this->assertTrue($res->json('is_single_project'));
            $this->assertEquals($project->id, $res->json('selected_project.id'));
        }
    }

    public function test_kpi_drilldown_search_and_pagination()
    {
        $user = $this->getAdminUser();
        $this->actingAs($user, 'sanctum');

        $res = $this->getJson('/api/v1/dashboard/kpi-drilldown?kpi=total_parts&page=1&per_page=5');
        $res->assertStatus(200);
        $this->assertEquals(1, $res->json('page'));
        $this->assertEquals(5, $res->json('per_page'));
        $this->assertLessThanOrEqual(5, count($res->json('data')));
    }

    public function test_kpi_drilldown_excel_export_returns_streamed_file()
    {
        $user = $this->getAdminUser();
        $this->actingAs($user, 'sanctum');

        $response = $this->get('/api/v1/dashboard/kpi-drilldown/export?kpi=total_parts');
        $response->assertStatus(200);
        $response->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    }

    public function test_kpi_drilldown_excel_part_number_format()
    {
        $user = $this->getAdminUser();
        $this->actingAs($user, 'sanctum');

        $exportService = new \App\Services\ExportService();
        $req = new \Illuminate\Http\Request(['kpi' => 'total_parts']);
        $req->setUserResolver(fn() => $user);

        $payload = $exportService->exportKpiDrilldownData($req);

        // Verify Excel column headers: strictly "Project", "Part Number", "Status", "Quantity"
        $this->assertCount(4, $payload['columns']);
        $labels = array_column($payload['columns'], 'label');
        $this->assertEquals(['Project', 'Part Number', 'Status', 'Quantity'], $labels);

        // Verify row format: contains concatenated Jig + Unit + Part + R/L (e.g. 169961@00020#R00R)
        if (!empty($payload['rows'])) {
            $firstRow = is_array($payload['rows']) ? reset($payload['rows']) : $payload['rows']->first();
            $this->assertNotEmpty($firstRow['excel_part_number']);
            // Verify no spaces/slashes in excel_part_number
            $this->assertStringNotContainsString(' / ', $firstRow['excel_part_number']);
            // Verify side is formatted as R or L
            if ($firstRow['side'] === 'RH') {
                $this->assertStringEndsWith('R', $firstRow['excel_part_number']);
            } elseif ($firstRow['side'] === 'LH') {
                $this->assertStringEndsWith('L', $firstRow['excel_part_number']);
            }
        }
    }
}
