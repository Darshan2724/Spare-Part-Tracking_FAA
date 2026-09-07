<?php

namespace Tests\Feature;

use App\Events\EcnUpdated;
use App\Models\BomImportBatch;
use App\Models\BomItem;
use App\Models\BomRequirement;
use App\Models\EcnImportBatch;
use App\Models\EcnReceiptItem;
use App\Models\EcnRequirement;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class EcnCompletedProjectVisibilityTest extends TestCase
{
    protected User $adminUser;

    protected function setUp(): void
    {
        parent::setUp();

        $role = Role::firstOrCreate(['name' => 'ADMIN', 'guard_name' => 'web']);

        $this->adminUser = User::firstOrCreate(
            ['email' => 'admin_test_visibility@sparetrack.internal'],
            [
                'name' => 'Admin Test Visibility',
                'password' => bcrypt('password'),
            ]
        );

        if (!$this->adminUser->hasRole('ADMIN')) {
            $this->adminUser->assignRole($role);
        }

        Sanctum::actingAs($this->adminUser);
    }

    /**
     * 1. Active project without ECN appears in active_projects and not in completed_projects.
     */
    public function test_active_project_without_ecn_appears_in_active_projects_list_and_not_in_completed_list(): void
    {
        $project = Project::create([
            'name' => 'Active Project No ECN ' . uniqid(),
            'project_code' => 'AP-NO-ECN-' . uniqid(),
            'status' => 'active',
        ]);

        $resHierarchy = $this->getJson('/api/v1/dashboard/project-hierarchy');
        $resHierarchy->assertStatus(200);
        $activeIds = collect($resHierarchy->json('active_projects'))->pluck('id')->all();
        $completedIds = collect($resHierarchy->json('completed_projects'))->pluck('id')->all();

        $this->assertContains($project->id, $activeIds);
        $this->assertNotContains($project->id, $completedIds);

        $resSummary = $this->getJson('/api/v1/dashboard/summary');
        $resSummary->assertStatus(200);
        $progressIds = collect($resSummary->json('projects_progress'))->pluck('id')->all();
        $this->assertContains($project->id, $progressIds);

        $resEcn = $this->getJson('/api/v1/ecn/hierarchy');
        $resEcn->assertStatus(200);
        $ecnActiveIds = collect($resEcn->json('active_projects'))->pluck('id')->all();
        $this->assertContains($project->id, $ecnActiveIds);
    }

    /**
     * 2. Completed project without ECN appears in completed_projects and not in active_projects.
     */
    public function test_completed_project_without_ecn_appears_in_completed_projects_list_and_not_in_active_list(): void
    {
        $project = Project::create([
            'name' => 'Completed Project No ECN ' . uniqid(),
            'project_code' => 'CP-NO-ECN-' . uniqid(),
            'status' => 'completed',
            'actual_completion_date' => now()->toDateString(),
        ]);

        $resHierarchy = $this->getJson('/api/v1/dashboard/project-hierarchy');
        $resHierarchy->assertStatus(200);
        $activeIds = collect($resHierarchy->json('active_projects'))->pluck('id')->all();
        $completedIds = collect($resHierarchy->json('completed_projects'))->pluck('id')->all();

        $this->assertNotContains($project->id, $activeIds);
        $this->assertContains($project->id, $completedIds);

        $resEcn = $this->getJson('/api/v1/ecn/hierarchy');
        $resEcn->assertStatus(200);
        $ecnActiveIds = collect($resEcn->json('active_projects'))->pluck('id')->all();
        $ecnCompletedIds = collect($resEcn->json('completed_projects'))->pluck('id')->all();

        $this->assertNotContains($project->id, $ecnActiveIds);
        $this->assertContains($project->id, $ecnCompletedIds);
    }

    /**
     * 3. Completed project with newly added active ECN immediately appears in active_projects.
     */
    public function test_completed_project_with_newly_added_active_ecn_immediately_appears_in_active_projects_list(): void
    {
        $project = Project::create([
            'name' => 'Completed Project With ECN ' . uniqid(),
            'project_code' => 'CP-WITH-ECN-' . uniqid(),
            'status' => 'completed',
            'actual_completion_date' => now()->subMonth()->toDateString(),
        ]);

        // Add qualifying active ECN requirement
        EcnRequirement::create([
            'project_id' => $project->id,
            'ecn_number' => 'ECN-TEST-001',
            'jig_no' => 'JIG-100',
            'unit_no' => 'UNIT-01',
            'part_no' => 'PART-A',
            'side' => 'RH',
            'side_display' => 'RH',
            'side_family' => 'RIGHT',
            'required_qty' => 3,
            'received_qty' => 0,
            'current_state' => 'PENDING',
        ]);

        // 1. Dashboard project hierarchy
        $resHierarchy = $this->getJson('/api/v1/dashboard/project-hierarchy');
        $resHierarchy->assertStatus(200);
        $activeIds = collect($resHierarchy->json('active_projects'))->pluck('id')->all();
        $completedIds = collect($resHierarchy->json('completed_projects'))->pluck('id')->all();

        $this->assertContains($project->id, $activeIds, 'Completed project with active ECN must appear in active_projects list');
        $this->assertNotContains($project->id, $completedIds, 'Completed project with active ECN must not be duplicated in completed_projects list');

        // 2. Dashboard summary
        $resSummary = $this->getJson('/api/v1/dashboard/summary');
        $resSummary->assertStatus(200);
        $progressList = collect($resSummary->json('projects_progress'));
        $projItem = $progressList->firstWhere('id', $project->id);

        $this->assertNotNull($projItem, 'Completed project with active ECN must appear in projects_progress');
        $this->assertEquals('completed', $projItem['status'], 'Original production completion status must be preserved');
        $this->assertTrue($projItem['has_active_ecn'], 'has_active_ecn flag must be true');
        $this->assertTrue($projItem['is_ecn_active'], 'is_ecn_active flag must be true');

        // 3. ECN hierarchy
        $resEcn = $this->getJson('/api/v1/ecn/hierarchy');
        $resEcn->assertStatus(200);
        $ecnActiveIds = collect($resEcn->json('active_projects'))->pluck('id')->all();
        $ecnCompletedIds = collect($resEcn->json('completed_projects'))->pluck('id')->all();

        $this->assertContains($project->id, $ecnActiveIds, 'Completed project with active ECN must appear in ECN active_projects');
        $this->assertNotContains($project->id, $ecnCompletedIds, 'Completed project with active ECN must not appear in ECN completed_projects');
    }

    /**
     * 4. Adding multiple ECNs to completed project does not duplicate project in lists.
     */
    public function test_adding_multiple_ecns_to_completed_project_does_not_duplicate_project_in_lists(): void
    {
        $project = Project::create([
            'name' => 'Completed Multi ECN ' . uniqid(),
            'project_code' => 'CP-MULTI-' . uniqid(),
            'status' => 'completed',
        ]);

        for ($i = 1; $i <= 3; $i++) {
            EcnRequirement::create([
                'project_id' => $project->id,
                'ecn_number' => 'ECN-MULTI-' . $i,
                'jig_no' => 'JIG-100',
                'unit_no' => 'UNIT-0' . $i,
                'part_no' => 'PART-M' . $i,
                'side' => 'RH',
                'side_display' => 'RH',
                'side_family' => 'RIGHT',
                'required_qty' => 2,
                'received_qty' => 0,
                'current_state' => 'PENDING',
            ]);
        }

        $resHierarchy = $this->getJson('/api/v1/dashboard/project-hierarchy');
        $activeList = collect($resHierarchy->json('active_projects'));
        $matchingActive = $activeList->where('id', $project->id);

        $this->assertCount(1, $matchingActive, 'Project must appear exactly once in active_projects even with multiple ECNs');

        $resSummary = $this->getJson('/api/v1/dashboard/summary');
        $progressList = collect($resSummary->json('projects_progress'));
        $matchingProgress = $progressList->where('id', $project->id);

        $this->assertCount(1, $matchingProgress, 'Project must appear exactly once in projects_progress');
    }

    /**
     * 5. Soft-deleted or zero quantity ECN does not activate completed project.
     */
    public function test_soft_deleted_or_zero_qty_ecn_does_not_activate_completed_project(): void
    {
        $project = Project::create([
            'name' => 'Completed Deleted ECN ' . uniqid(),
            'project_code' => 'CP-DEL-' . uniqid(),
            'status' => 'completed',
        ]);

        // Soft-deleted ECN
        $req1 = EcnRequirement::create([
            'project_id' => $project->id,
            'ecn_number' => 'ECN-DEL-001',
            'jig_no' => 'JIG-100',
            'unit_no' => 'UNIT-01',
            'part_no' => 'PART-DEL',
            'side' => 'RH',
            'side_display' => 'RH',
            'side_family' => 'RIGHT',
            'required_qty' => 5,
            'received_qty' => 0,
            'current_state' => 'PENDING',
        ]);
        $req1->delete();

        // Zero required qty ECN
        EcnRequirement::create([
            'project_id' => $project->id,
            'ecn_number' => 'ECN-ZERO-002',
            'jig_no' => 'JIG-100',
            'unit_no' => 'UNIT-02',
            'part_no' => 'PART-ZERO',
            'side' => 'LH',
            'side_display' => 'LH',
            'side_family' => 'LEFT',
            'required_qty' => 0,
            'received_qty' => 0,
            'current_state' => 'PENDING',
        ]);

        $resHierarchy = $this->getJson('/api/v1/dashboard/project-hierarchy');
        $activeIds = collect($resHierarchy->json('active_projects'))->pluck('id')->all();
        $completedIds = collect($resHierarchy->json('completed_projects'))->pluck('id')->all();

        $this->assertNotContains($project->id, $activeIds, 'Soft-deleted/zero-qty ECN must not activate completed project');
        $this->assertContains($project->id, $completedIds, 'Project must remain in completed_projects');
    }

    /**
     * 6. Completed project with all ECNs resolved to ASSEMBLY_COMPLETED returns to completed list.
     */
    public function test_completed_project_with_all_ecns_resolved_to_assembly_completed_returns_to_completed_list(): void
    {
        $project = Project::create([
            'name' => 'Completed Resolved ECN ' . uniqid(),
            'project_code' => 'CP-RESOLVED-' . uniqid(),
            'status' => 'completed',
        ]);

        $ecnReq = EcnRequirement::create([
            'project_id' => $project->id,
            'ecn_number' => 'ECN-RES-001',
            'jig_no' => 'JIG-100',
            'unit_no' => 'UNIT-01',
            'part_no' => 'PART-RES',
            'side' => 'RH',
            'side_display' => 'RH',
            'side_family' => 'RIGHT',
            'required_qty' => 2,
            'received_qty' => 2,
            'current_state' => 'PENDING',
        ]);

        // When pending: in active_projects
        $res1 = $this->getJson('/api/v1/dashboard/project-hierarchy');
        $this->assertContains($project->id, collect($res1->json('active_projects'))->pluck('id')->all());

        // When updated to ASSEMBLY_COMPLETED (resolved lifecycle):
        $ecnReq->update(['current_state' => 'ASSEMBLY_COMPLETED']);

        $res2 = $this->getJson('/api/v1/dashboard/project-hierarchy');
        $activeIds = collect($res2->json('active_projects'))->pluck('id')->all();
        $completedIds = collect($res2->json('completed_projects'))->pluck('id')->all();

        $this->assertNotContains($project->id, $activeIds, 'Fully assembled ECN must return project to completed list');
        $this->assertContains($project->id, $completedIds, 'Project must appear in completed_projects list');
    }

    /**
     * 7. Original production completion history and status are strictly preserved.
     */
    public function test_original_production_completion_history_and_status_are_strictly_preserved(): void
    {
        $completionDate = '2026-08-15';
        $project = Project::create([
            'name' => 'Completed Integrity ' . uniqid(),
            'project_code' => 'CP-INT-' . uniqid(),
            'status' => 'completed',
            'actual_completion_date' => $completionDate,
        ]);

        EcnRequirement::create([
            'project_id' => $project->id,
            'ecn_number' => 'ECN-INT-001',
            'jig_no' => 'JIG-100',
            'unit_no' => 'UNIT-01',
            'part_no' => 'PART-INT',
            'side' => 'RH',
            'side_display' => 'RH',
            'side_family' => 'RIGHT',
            'required_qty' => 1,
            'received_qty' => 0,
            'current_state' => 'STORE',
        ]);

        // Re-fetch project fresh from database
        $freshProject = Project::find($project->id);
        $this->assertEquals('completed', $freshProject->status, 'Database status must remain completed');
        $this->assertEquals($completionDate, $freshProject->actual_completion_date->toDateString(), 'Database completion date must be preserved');
    }

    /**
     * 8. ECN hierarchy and summary load correctly for completed project with active ECN.
     */
    public function test_ecn_hierarchy_and_summary_load_correctly_for_completed_project_with_active_ecn(): void
    {
        $project = Project::create([
            'name' => 'Completed Drilldown ' . uniqid(),
            'project_code' => 'CP-DRILL-' . uniqid(),
            'status' => 'completed',
        ]);

        $req = EcnRequirement::create([
            'project_id' => $project->id,
            'ecn_number' => 'ECN-DRILL-001',
            'jig_no' => 'JIG-DRILL-1',
            'unit_no' => 'UNIT-01',
            'part_no' => 'PART-D1',
            'side' => 'RH',
            'side_display' => 'RH',
            'side_family' => 'RIGHT',
            'required_qty' => 4,
            'received_qty' => 2,
            'current_state' => 'STORE',
        ]);

        EcnReceiptItem::create([
            'ecn_requirement_id' => $req->id,
            'project_id' => $project->id,
            'ecn_number' => 'ECN-DRILL-001',
            'jig_no' => 'JIG-DRILL-1',
            'unit_no' => 'UNIT-01',
            'part_no' => 'PART-D1',
            'side' => 'RH',
            'side_display' => 'RH',
            'side_family' => 'RIGHT',
            'received_quantity' => 2,
            'status' => 'store_received',
            'created_by' => $this->adminUser->id,
        ]);

        $resHier = $this->getJson('/api/v1/ecn/hierarchy?project_id=' . $project->id);
        $resHier->assertStatus(200);
        $this->assertNotEmpty($resHier->json('ecn_nodes'));

        $resSummary = $this->getJson('/api/v1/ecn/summary?project_id=' . $project->id);
        $resSummary->assertStatus(200);
        $this->assertEquals(4, $resSummary->json('summary.total_parts'));
        $this->assertEquals(2, $resSummary->json('summary.total_received'));
        $this->assertEquals(2, $resSummary->json('summary.parts_pending'));
    }
}
