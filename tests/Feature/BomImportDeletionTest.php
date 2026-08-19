<?php

namespace Tests\Feature;

use App\Models\BomImportBatch;
use App\Models\BomItem;
use App\Models\BomRequirement;
use App\Models\Project;
use App\Models\SystemLog;
use App\Models\User;
use Tests\TestCase;

class BomImportDeletionTest extends TestCase
{
    protected function getAdminUser(): User
    {
        $user = User::where('email', 'admin@sparetrack.internal')->first();
        if (!$user) {
            $user = User::first();
        }
        return $user;
    }

    protected function getStoreUser(): ?User
    {
        return User::whereHas('roles', fn ($q) => $q->where('name', 'STORE'))->first();
    }

    public function test_unauthenticated_request_is_rejected(): void
    {
        $response = $this->deleteJson('/api/v1/bom/history/999999');
        $response->assertStatus(401);

        $impactResponse = $this->getJson('/api/v1/bom/history/999999/impact');
        $impactResponse->assertStatus(401);
    }

    public function test_unauthorized_role_cannot_delete_or_preview_impact(): void
    {
        $storeUser = $this->getStoreUser();
        if (!$storeUser) {
            $this->markTestSkipped('No store user found in database.');
        }

        $this->actingAs($storeUser, 'sanctum');

        $impactResponse = $this->getJson('/api/v1/bom/history/1/impact');
        $impactResponse->assertStatus(403);

        $deleteResponse = $this->deleteJson('/api/v1/bom/history/1');
        $deleteResponse->assertStatus(403);
    }

    public function test_impact_preview_returns_accurate_metrics(): void
    {
        $admin = $this->getAdminUser();
        $this->actingAs($admin, 'sanctum');

        // Create temporary isolated project and batch
        $testProject = Project::create([
            'project_code' => 'TEST_IMPACT_' . uniqid(),
            'name' => 'Test Impact Project',
            'status' => 'active',
            'created_by' => $admin->id,
        ]);

        $testBatch = BomImportBatch::create([
            'project_id' => $testProject->id,
            'filename' => 'TEST_IMPACT_BOM.xlsx',
            'file_hash' => hash('sha256', 'TEST_IMPACT_' . uniqid()),
            'imported_by' => $admin->id,
            'total_rows' => 2,
            'successful_rows' => 2,
            'status' => 'completed',
        ]);

        $item1 = BomItem::create([
            'project_id' => $testProject->id,
            'jig_no' => 'TEST_JIG_1',
            'unit_no' => '01',
            'standard_part_no' => 'TEST-PART-01-' . uniqid(),
            'import_batch_id' => $testBatch->id,
        ]);

        BomRequirement::create([
            'bom_item_id' => $item1->id,
            'side' => 'RH',
            'required_quantity' => 5,
        ]);

        $response = $this->getJson("/api/v1/bom/history/{$testBatch->id}/impact");

        $response->assertStatus(200)
                 ->assertJsonStructure([
                     'batch' => ['id', 'filename', 'status', 'total_rows'],
                     'project' => ['id', 'name', 'project_code'],
                     'counts' => [
                         'jigs_count',
                         'units_count',
                         'unique_parts_count',
                         'bom_requirements_count',
                         'receipts_count',
                         'qc_inspections_count',
                     ],
                     'has_operational_data',
                     'will_delete_project',
                 ]);

        $this->assertEquals(1, $response->json('counts.jigs_count'));
        $this->assertEquals(1, $response->json('counts.units_count'));
        $this->assertEquals(1, $response->json('counts.unique_parts_count'));
        $this->assertEquals(1, $response->json('counts.bom_requirements_count'));
        $this->assertTrue($response->json('will_delete_project'));

        // Clean up test records
        $this->deleteJson("/api/v1/bom/history/{$testBatch->id}");
    }

    public function test_safe_deletion_deletes_only_target_project_and_protects_other_projects(): void
    {
        $admin = $this->getAdminUser();
        $this->actingAs($admin, 'sanctum');

        // Create Project A
        $projectA = Project::create([
            'project_code' => 'TEST_PROJ_A_' . uniqid(),
            'name' => 'Project A for Deletion',
            'status' => 'active',
            'created_by' => $admin->id,
        ]);

        $batchA = BomImportBatch::create([
            'project_id' => $projectA->id,
            'filename' => 'PROJECT_A_BOM.xlsx',
            'file_hash' => hash('sha256', 'PROJ_A_' . uniqid()),
            'imported_by' => $admin->id,
            'total_rows' => 1,
            'successful_rows' => 1,
            'status' => 'completed',
        ]);

        $itemA = BomItem::create([
            'project_id' => $projectA->id,
            'jig_no' => 'JIG_A',
            'unit_no' => '01',
            'standard_part_no' => 'PART-A-' . uniqid(),
            'import_batch_id' => $batchA->id,
        ]);

        BomRequirement::create([
            'bom_item_id' => $itemA->id,
            'side' => 'LH',
            'required_quantity' => 10,
        ]);

        // Create Project B (Protected Project)
        $projectB = Project::create([
            'project_code' => 'TEST_PROJ_B_' . uniqid(),
            'name' => 'Project B Protected',
            'status' => 'active',
            'created_by' => $admin->id,
        ]);

        $batchB = BomImportBatch::create([
            'project_id' => $projectB->id,
            'filename' => 'PROJECT_B_BOM.xlsx',
            'file_hash' => hash('sha256', 'PROJ_B_' . uniqid()),
            'imported_by' => $admin->id,
            'total_rows' => 1,
            'successful_rows' => 1,
            'status' => 'completed',
        ]);

        $itemB = BomItem::create([
            'project_id' => $projectB->id,
            'jig_no' => 'JIG_B',
            'unit_no' => '02',
            'standard_part_no' => 'PART-B-' . uniqid(),
            'import_batch_id' => $batchB->id,
        ]);

        BomRequirement::create([
            'bom_item_id' => $itemB->id,
            'side' => 'RH',
            'required_quantity' => 20,
        ]);

        // Execute deletion of Batch A / Project A
        $deleteResponse = $this->deleteJson("/api/v1/bom/history/{$batchA->id}");
        $deleteResponse->assertStatus(200)
                       ->assertJson(['success' => true]);

        // Assert Batch A and Project A are deleted
        $this->assertNull(BomImportBatch::find($batchA->id));
        $this->assertNull(Project::withTrashed()->find($projectA->id));
        $this->assertNull(BomItem::withTrashed()->find($itemA->id));

        // Assert Project B and Batch B are 100% UNTOUCHED
        $this->assertNotNull(BomImportBatch::find($batchB->id));
        $this->assertNotNull(Project::find($projectB->id));
        $this->assertNotNull(BomItem::find($itemB->id));
        $this->assertEquals(1, BomRequirement::where('bom_item_id', $itemB->id)->count());

        // Verify Audit Log was recorded
        $auditLog = SystemLog::where('category', 'admin_actions')
            ->where('module', 'BOM_IMPORT')
            ->where('details->event', 'BOM_IMPORT_DELETED')
            ->where('details->import_batch_id', $batchA->id)
            ->first();

        $this->assertNotNull($auditLog, 'Expected BOM_IMPORT_DELETED audit log entry in system_logs');

        // Clean up Project B
        $this->deleteJson("/api/v1/bom/history/{$batchB->id}");
    }
}
