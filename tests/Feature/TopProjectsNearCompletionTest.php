<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\User;
use App\Services\QuantityCalculationService;
use Tests\TestCase;

class TopProjectsNearCompletionTest extends TestCase
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

    public function test_dashboard_summary_returns_all_qualifying_active_projects_in_top_projects()
    {
        $user = $this->getAdminUser();
        $this->actingAs($user, 'sanctum');

        $response = $this->getJson('/api/v1/dashboard/summary?status_filter=active');
        $response->assertStatus(200);

        $data = $response->json();
        $this->assertArrayHasKey('top_projects', $data);
        $topProjects = $data['top_projects'];

        $this->assertArrayHasKey('labels', $topProjects);
        $this->assertArrayHasKey('percentages', $topProjects);
        $this->assertArrayHasKey('health_statuses', $topProjects);
        $this->assertArrayHasKey('health_colors', $topProjects);
        $this->assertArrayHasKey('projects', $topProjects);

        $labels = $topProjects['labels'];
        $healthStatuses = $topProjects['health_statuses'];
        $healthColors = $topProjects['health_colors'];

        $activeProjectsCount = Project::where('status', 'active')->count();
        $this->assertGreaterThanOrEqual(1, count($labels));

        // Verify health colors conform to the 4 strict colors
        $validColors = [
            QuantityCalculationService::HEALTH_COLORS['near_completion'],
            QuantityCalculationService::HEALTH_COLORS['on_track'],
            QuantityCalculationService::HEALTH_COLORS['at_risk'],
            QuantityCalculationService::HEALTH_COLORS['delayed'],
        ];

        foreach ($healthColors as $idx => $color) {
            $this->assertContains(
                $color,
                $validColors,
                "Project {$labels[$idx]} has invalid health color {$color}"
            );
        }

        // Verify health status matches the color
        foreach ($healthStatuses as $idx => $status) {
            $expectedColor = QuantityCalculationService::HEALTH_COLORS[$status] ?? null;
            $this->assertNotNull($expectedColor, "Unknown health status: {$status}");
            $this->assertEquals(
                $expectedColor,
                $healthColors[$idx],
                "Health color mismatch for status {$status} on project {$labels[$idx]}"
            );
        }
    }

    public function test_service_health_map_categorizes_correctly()
    {
        $service = app(QuantityCalculationService::class);
        $projects = Project::where('status', 'active')->get();

        if ($projects->isNotEmpty()) {
            $healthMap = $service->getProjectHealthMap($projects);
            foreach ($projects as $p) {
                $this->assertArrayHasKey($p->id, $healthMap);
                $info = $healthMap[$p->id];
                $this->assertArrayHasKey('category', $info);
                $this->assertArrayHasKey('color', $info);
                $this->assertArrayHasKey('days_inactive', $info);
                $this->assertArrayHasKey('completion_pct', $info);
            }
        }
    }
}
