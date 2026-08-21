<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Project;
use Tests\TestCase;

class DashboardReportTest extends TestCase
{
    protected function getAdminUser(): User
    {
        return User::where('email', 'admin@sparetrack.internal')->first();
    }

    public function test_dashboard_summary_api()
    {
        $user = $this->getAdminUser();
        $response = $this->actingAs($user, 'sanctum')->getJson('/api/v1/dashboard/summary');
        $response->assertStatus(200);
        $response->assertJsonStructure([
            'summary',
            'status_distribution',
            'top_projects',
            'health_distribution',
        ]);
    }

    public function test_dashboard_analytics_api_for_reports()
    {
        $user = $this->getAdminUser();
        $response = $this->actingAs($user, 'sanctum')->getJson('/api/v1/dashboard/analytics');
        $response->assertStatus(200);
        $response->assertJsonStructure([
            'project_readiness_index',
            'conversion_rate',
            'velocity_series',
            'supplier_fill_accuracy',
            'quality_cost_pressure',
        ]);
    }

    public function test_dashboard_priority_map_api_for_reports()
    {
        $user = $this->getAdminUser();
        $response = $this->actingAs($user, 'sanctum')->getJson('/api/v1/dashboard/priority-map');
        $response->assertStatus(200);
        $response->assertJsonStructure([
            'units',
            'summary_counts',
            'chart',
        ]);
    }

    public function test_dashboard_daily_movement_api_for_reports()
    {
        $user = $this->getAdminUser();
        $response = $this->actingAs($user, 'sanctum')->getJson('/api/v1/dashboard/daily-movement?quick_range=last_5_active&window_offset=0&window_size=5');
        $response->assertStatus(200);
        $response->assertJsonStructure([
            'matrix',
            'totals',
            'pagination',
        ]);
    }

    public function test_dashboard_export_excel_all_projects()
    {
        $user = $this->getAdminUser();
        $response = $this->actingAs($user, 'sanctum')->post('/api/v1/export/dashboard', [
            'format' => 'excel',
        ]);
        $response->assertStatus(200);
        $response->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    }

    public function test_dashboard_export_pdf_all_projects()
    {
        $user = $this->getAdminUser();
        $response = $this->actingAs($user, 'sanctum')->post('/api/v1/export/dashboard', [
            'format' => 'pdf',
        ]);
        $response->assertStatus(200);
        $response->assertHeader('content-type', 'application/pdf');
    }

    public function test_dashboard_export_excel_single_project()
    {
        $user = $this->getAdminUser();
        $project = Project::first();
        $response = $this->actingAs($user, 'sanctum')->post('/api/v1/export/dashboard', [
            'format' => 'excel',
            'project_id' => $project?->id,
        ]);
        $response->assertStatus(200);
        $response->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    }

    public function test_dashboard_export_pdf_single_project()
    {
        $user = $this->getAdminUser();
        $project = Project::first();
        $response = $this->actingAs($user, 'sanctum')->post('/api/v1/export/dashboard', [
            'format' => 'pdf',
            'project_id' => $project?->id,
        ]);
        $response->assertStatus(200);
        $response->assertHeader('content-type', 'application/pdf');
    }
}
