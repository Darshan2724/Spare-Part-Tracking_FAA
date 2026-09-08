<?php

namespace Tests\Feature;

use App\Models\BomItem;
use App\Models\BomRequirement;
use App\Models\Project;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class CrossTypeIsolationAndEnforcementTest extends TestCase
{
    use DatabaseTransactions;

    protected User $storeUser;
    protected Supplier $supplier;
    protected Project $project;
    protected BomItem $mfgItem;
    protected BomItem $bopItem;
    protected BomItem $stdItem;

    protected function setUp(): void
    {
        parent::setUp();

        $role = Role::firstOrCreate(['name' => 'STORE', 'guard_name' => 'web']);
        $this->storeUser = User::create([
            'name' => 'Cross Type Test User',
            'email' => 'crosstype_' . uniqid() . '@example.com',
            'password' => bcrypt('secret'),
        ]);
        $this->storeUser->assignRole($role);
        Sanctum::actingAs($this->storeUser);

        $this->supplier = Supplier::create([
            'name' => 'Cross Type Supplier ' . uniqid(),
            'code' => 'CT-SUP-' . uniqid(),
            'is_active' => true,
        ]);

        $this->project = Project::create([
            'name' => 'Cross Type Isolation Project ' . uniqid(),
            'project_code' => 'CT-ISO-' . uniqid(),
            'status' => 'active',
        ]);

        $this->mfgItem = BomItem::create([
            'project_id' => $this->project->id,
            'standard_part_no' => 'MFG-PART-' . uniqid(),
            'part_type' => 'MFG',
            'supplier_id' => $this->supplier->id,
            'jig_no' => 'JIG-01',
            'unit_no' => 'Unit 1',
        ]);
        BomRequirement::create([
            'bom_item_id' => $this->mfgItem->id,
            'side' => 'LH',
            'required_quantity' => 10,
        ]);

        $this->bopItem = BomItem::create([
            'project_id' => $this->project->id,
            'standard_part_no' => 'BOP-PART-' . uniqid(),
            'part_type' => 'BOP',
            'supplier_id' => $this->supplier->id,
            'jig_no' => 'JIG-01',
            'unit_no' => 'Unit 1',
        ]);
        BomRequirement::create([
            'bom_item_id' => $this->bopItem->id,
            'side' => 'COMMON',
            'required_quantity' => 10,
        ]);

        $this->stdItem = BomItem::create([
            'project_id' => $this->project->id,
            'standard_part_no' => 'STD-PART-' . uniqid(),
            'part_type' => 'STD',
            'supplier_id' => $this->supplier->id,
            'jig_no' => 'JIG-01',
            'unit_no' => 'Unit 1',
        ]);
        BomRequirement::create([
            'bom_item_id' => $this->stdItem->id,
            'side' => 'COMMON',
            'required_quantity' => 10,
        ]);
    }

    /**
     * Mobile store intake strictly permits MFG parts.
     */
    public function test_mobile_intake_permits_mfg_parts(): void
    {
        $response = $this->postJson('/api/v1/mobile/store/receive', [
            'project_id' => $this->project->id,
            'supplier_id' => $this->supplier->id,
            'bom_item_id' => $this->mfgItem->id,
            'side' => 'LH',
            'received_quantity' => 5,
            'delivery_note_number' => 'DN-MFG-001',
        ]);

        $response->assertStatus(200);
        $response->assertJson(['success' => true]);
    }

    /**
     * Mobile store intake rejects BOP parts with 422 unprocessable entity.
     */
    public function test_mobile_intake_rejects_bop_parts(): void
    {
        $response = $this->postJson('/api/v1/mobile/store/receive', [
            'project_id' => $this->project->id,
            'supplier_id' => $this->supplier->id,
            'bom_item_id' => $this->bopItem->id,
            'side' => 'COMMON',
            'received_quantity' => 5,
            'delivery_note_number' => 'DN-BOP-001',
        ]);

        $response->assertStatus(422);
        $this->assertStringContainsString('Mobile part intake is strictly restricted to MFG items', $response->json('message'));
    }

    /**
     * Mobile store intake rejects STD parts with 422 unprocessable entity.
     */
    public function test_mobile_intake_rejects_std_parts(): void
    {
        $response = $this->postJson('/api/v1/mobile/store/receive', [
            'project_id' => $this->project->id,
            'supplier_id' => $this->supplier->id,
            'bom_item_id' => $this->stdItem->id,
            'side' => 'COMMON',
            'received_quantity' => 5,
            'delivery_note_number' => 'DN-STD-001',
        ]);

        $response->assertStatus(422);
        $this->assertStringContainsString('Mobile part intake is strictly restricted to MFG items', $response->json('message'));
    }
}
