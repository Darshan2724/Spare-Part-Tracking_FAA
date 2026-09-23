<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Project;
use App\Models\BomImportBatch;
use App\Models\BomItem;
use App\Models\BomRequirement;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ProjectPreservationAuditTest extends TestCase
{
    protected User $adminUser;

    protected function setUp(): void
    {
        parent::setUp();

        $role = Role::firstOrCreate(['name' => 'ADMIN', 'guard_name' => 'web']);
        $this->adminUser = User::firstOrCreate(
            ['email' => 'admin_audit@sparetrack.internal'],
            ['name' => 'Admin Audit', 'password' => bcrypt('password')]
        );
        $this->adminUser->assignRole($role);
    }

    public function test_deleting_sole_import_batch_preserves_project_record(): void
    {
        $this->actingAs($this->adminUser, 'sanctum');

        $project = Project::create([
            'project_code' => 'PRESERVE-' . uniqid(),
            'name' => 'Preserved Project With Sole Batch',
            'status' => 'active',
        ]);

        $batch = BomImportBatch::create([
            'project_id' => $project->id,
            'filename' => 'BOM_Test_Sole.xlsx',
            'file_hash' => hash('sha256', uniqid()),
            'status' => 'completed',
            'imported_by' => $this->adminUser->id,
            'imported_at' => now(),
        ]);

        $item = BomItem::create([
            'project_id' => $project->id,
            'import_batch_id' => $batch->id,
            'standard_part_no' => 'PART-SOLE-001',
            'item_no' => '1',
            'jig_no' => 'JIG-01',
            'unit_no' => 'Unit 01',
            'part_type' => 'MFG',
        ]);

        BomRequirement::create([
            'bom_item_id' => $item->id,
            'side' => 'RH',
            'required_quantity' => 5,
        ]);

        $this->assertEquals(1, BomImportBatch::where('project_id', $project->id)->count());
        $this->assertNotNull(Project::find($project->id));

        // Delete the sole import batch
        $response = $this->deleteJson("/api/v1/bom/history/{$batch->id}");
        $response->assertStatus(200);

        // Batch is deleted
        $this->assertNull(BomImportBatch::find($batch->id));
        $this->assertNull(BomItem::find($item->id));

        // CRITICAL CHECK: Project record MUST NOT be deleted
        $refreshedProject = Project::find($project->id);
        $this->assertNotNull($refreshedProject, 'Project record must remain intact even when its sole BOM batch is deleted.');
        $this->assertEquals($project->project_code, $refreshedProject->project_code);
    }

    public function test_project_with_zero_parts_is_not_automatically_deleted(): void
    {
        $this->actingAs($this->adminUser, 'sanctum');

        $project = Project::create([
            'project_code' => 'EMPTY-PROJ-' . uniqid(),
            'name' => 'Empty Shell Project',
            'status' => 'active',
        ]);

        // Calling hierarchy or listing endpoints must never clean up or delete empty projects
        $resHierarchy = $this->getJson("/api/v1/dashboard/project-hierarchy?project_id={$project->id}");
        $resHierarchy->assertStatus(200);

        $resOverview = $this->getJson('/api/v1/dashboard/summary');
        $resOverview->assertStatus(200);

        $this->assertNotNull(Project::find($project->id), 'Empty project must never be removed as side-effect of hierarchy requests.');
    }
}
