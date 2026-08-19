<?php

namespace Tests\Feature;

use App\Models\BomImportBatch;
use App\Models\BomItem;
use App\Models\BomRequirement;
use App\Models\Project;
use App\Models\Receipt;
use App\Models\ReceiptItem;
use App\Models\User;
use Tests\TestCase;

class PriorityMapSideIsolationTest extends TestCase
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

    public function test_priority_map_separates_rh_and_lh_requirements_and_never_combines_quantities(): void
    {
        $admin = $this->getAdminUser();
        $this->actingAs($admin, 'sanctum');

        // 1. Create a test project
        $project = Project::create([
            'project_code' => 'TEST_PRIORITY_' . uniqid(),
            'name' => 'Priority Map Isolation Project',
            'status' => 'active',
            'created_by' => $admin->id,
        ]);

        $batch = BomImportBatch::create([
            'project_id' => $project->id,
            'filename' => 'FA-279_TEST.xlsx',
            'file_hash' => hash('sha256', uniqid()),
            'imported_by' => $admin->id,
            'total_rows' => 2,
            'successful_rows' => 2,
            'status' => 'completed',
        ]);

        // 2. Create a single BOM Item (Unit 01, Part 010#R00)
        $bomItem = BomItem::create([
            'project_id' => $project->id,
            'jig_no' => 'JIG-01',
            'unit_no' => 'Unit 01',
            'standard_part_no' => '010#R00',
            'import_batch_id' => $batch->id,
        ]);

        // 3. Create RH requirement (Qty 1) and LH requirement (Qty 1)
        BomRequirement::create([
            'bom_item_id' => $bomItem->id,
            'side' => 'RH',
            'required_quantity' => 1,
        ]);

        BomRequirement::create([
            'bom_item_id' => $bomItem->id,
            'side' => 'LH',
            'required_quantity' => 1,
        ]);

        // 4. Query Priority Map endpoint for this specific project
        $response = $this->getJson("/api/v1/dashboard/priority-map?project_id={$project->id}");
        $response->assertStatus(200);

        $units = $response->json('units');
        $this->assertNotEmpty($units);

        $targetUnit = collect($units)->firstWhere('project_id', $project->id);
        $this->assertNotNull($targetUnit);

        // Unit-level summary: 2 distinct side requirements, total_required = 2
        $this->assertEquals(2, $targetUnit['total_required']);
        $this->assertEquals(2, $targetUnit['parts_count']); // 2 side requirements

        // Verify parts array contains TWO distinct side entries, NOT one combined entry
        $parts = $targetUnit['parts'];
        $this->assertCount(2, $parts);

        $rhPart = collect($parts)->firstWhere('side', 'RH');
        $lhPart = collect($parts)->firstWhere('side', 'LH');

        $this->assertNotNull($rhPart, 'RH requirement must exist as a separate entry');
        $this->assertNotNull($lhPart, 'LH requirement must exist as a separate entry');

        // CRITICAL CHECK: Required quantity must be 1 for each side, NOT 2!
        $this->assertEquals(1, $rhPart['required'], 'RH Required quantity must be exactly 1');
        $this->assertEquals(1, $lhPart['required'], 'LH Required quantity must be exactly 1');
        $this->assertEquals(1, $rhPart['pending'], 'RH Pending quantity must be exactly 1');
        $this->assertEquals(1, $lhPart['pending'], 'LH Pending quantity must be exactly 1');

        // Clean up test data
        $this->deleteJson("/api/v1/bom/history/{$batch->id}");
    }

    public function test_priority_map_received_quantities_are_strictly_side_isolated(): void
    {
        $admin = $this->getAdminUser();
        $this->actingAs($admin, 'sanctum');

        $project = Project::create([
            'project_code' => 'TEST_REC_' . uniqid(),
            'name' => 'Side Isolated Receipts Project',
            'status' => 'active',
            'created_by' => $admin->id,
        ]);

        $batch = BomImportBatch::create([
            'project_id' => $project->id,
            'filename' => 'RECEIPT_TEST.xlsx',
            'file_hash' => hash('sha256', uniqid()),
            'imported_by' => $admin->id,
            'total_rows' => 2,
            'successful_rows' => 2,
            'status' => 'completed',
        ]);

        $bomItem = BomItem::create([
            'project_id' => $project->id,
            'jig_no' => 'JIG-01',
            'unit_no' => 'Unit 01',
            'standard_part_no' => '020#R00',
            'import_batch_id' => $batch->id,
        ]);

        BomRequirement::create([
            'bom_item_id' => $bomItem->id,
            'side' => 'RH',
            'required_quantity' => 1,
        ]);

        BomRequirement::create([
            'bom_item_id' => $bomItem->id,
            'side' => 'LH',
            'required_quantity' => 1,
        ]);

        // Receive ONLY the RH part
        $receipt = Receipt::create([
            'project_id' => $project->id,
            'delivery_note_number' => 'DN-' . uniqid(),
            'received_by' => $admin->id,
        ]);

        ReceiptItem::create([
            'receipt_id' => $receipt->id,
            'bom_item_id' => $bomItem->id,
            'side' => 'RH',
            'received_quantity' => 1,
            'status' => 'received',
        ]);

        $response = $this->getJson("/api/v1/dashboard/priority-map?project_id={$project->id}");
        $response->assertStatus(200);

        $units = $response->json('units');
        $targetUnit = collect($units)->firstWhere('project_id', $project->id);

        $rhPart = collect($targetUnit['parts'])->firstWhere('side', 'RH');
        $lhPart = collect($targetUnit['parts'])->firstWhere('side', 'LH');

        // RH received = 1, pending = 0
        $this->assertEquals(1, $rhPart['received']);
        $this->assertEquals(0, $rhPart['pending']);

        // LH received = 0, pending = 1 (MUST NOT BE AFFECTED BY RH RECEIPT)
        $this->assertEquals(0, $lhPart['received']);
        $this->assertEquals(1, $lhPart['pending']);

        // Clean up
        $this->deleteJson("/api/v1/bom/history/{$batch->id}");
    }

    public function test_bom_preview_requires_uploaded_file_and_rejects_path_only(): void
    {
        $admin = $this->getAdminUser();
        $this->actingAs($admin, 'sanctum');

        // Request with path parameter but NO uploaded file must be rejected with 422 Unprocessable Entity
        $response = $this->postJson('/api/v1/bom/preview', [
            'path' => 'BOM/FA-279.xlsx',
        ]);

        $response->assertStatus(422)
                 ->assertJsonValidationErrors(['file']);
    }
}
