<?php

namespace Tests\Feature;

use App\Models\BomItem;
use App\Models\BomRequirement;
use App\Models\Project;
use App\Models\User;
use App\Models\Supplier;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class MobileIntakeMfgEnforcementTest extends TestCase
{
    use DatabaseTransactions;

    protected User $storeUser;
    protected Project $project;
    protected BomItem $mfgItem;
    protected BomItem $bopItem;
    protected BomItem $stdItem;
    protected Supplier $supplier;

    protected function setUp(): void
    {
        parent::setUp();

        $role = Role::firstOrCreate(['name' => 'STORE', 'guard_name' => 'web']);
        $this->storeUser = User::create([
            'name' => 'Store Test User',
            'email' => 'store_' . uniqid() . '@example.com',
            'password' => bcrypt('secret'),
        ]);
        $this->storeUser->assignRole($role);

        $this->supplier = Supplier::create([
            'name' => 'Test Supplier ' . uniqid(),
            'code' => 'SUP-' . uniqid(),
            'is_active' => true,
        ]);

        $this->project = Project::create([
            'name' => 'Mobile Intake Test Project ' . uniqid(),
            'project_code' => 'MOB-TEST-' . uniqid(),
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

    public function test_mobile_endpoint_creates_mfg_receipt_successfully()
    {
        $response = $this->actingAs($this->storeUser, 'sanctum')
            ->postJson('/api/v1/mobile/store/receive', [
                'project_id' => $this->project->id,
                'bom_item_id' => $this->mfgItem->id,
                'supplier_id' => $this->supplier->id,
                'side' => 'LH',
                'received_quantity' => 5,
                'delivery_note_number' => 'DN-MOB-01',
            ]);

        $response->assertSuccessful();
        $this->assertDatabaseHas('receipt_items', [
            'bom_item_id' => $this->mfgItem->id,
            'side' => 'LH',
            'received_quantity' => 5,
        ]);
    }

    public function test_mobile_intake_via_standard_route_with_mobile_headers_succeeds_for_mfg()
    {
        $response = $this->actingAs($this->storeUser, 'sanctum')
            ->withHeaders([
                'X-Client-Platform' => 'mobile',
                'X-Source-Channel' => 'MOBILE_INTAKE',
            ])
            ->postJson('/api/v1/store/receive', [
                'project_id' => $this->project->id,
                'bom_item_id' => $this->mfgItem->id,
                'supplier_id' => $this->supplier->id,
                'side' => 'LH',
                'received_quantity' => 4,
                'delivery_note_number' => 'DN-MOB-02',
            ]);

        $response->assertSuccessful();
    }

    public function test_mobile_endpoint_strictly_rejects_bop_part_with_422()
    {
        $response = $this->actingAs($this->storeUser, 'sanctum')
            ->postJson('/api/v1/mobile/store/receive', [
                'project_id' => $this->project->id,
                'bom_item_id' => $this->bopItem->id,
                'supplier_id' => $this->supplier->id,
                'side' => 'COMMON',
                'received_quantity' => 2,
                'delivery_note_number' => 'DN-MOB-BOP',
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['part_type']);
        $this->assertStringContainsString('MFG', $response->json('message') . json_encode($response->json('errors')));
    }

    public function test_mobile_endpoint_strictly_rejects_std_part_with_422()
    {
        $response = $this->actingAs($this->storeUser, 'sanctum')
            ->postJson('/api/v1/mobile/store/receive', [
                'project_id' => $this->project->id,
                'bom_item_id' => $this->stdItem->id,
                'supplier_id' => $this->supplier->id,
                'side' => 'COMMON',
                'received_quantity' => 2,
                'delivery_note_number' => 'DN-MOB-STD',
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['part_type']);
    }

    public function test_mobile_header_intake_strictly_rejects_non_mfg_payload_part_type()
    {
        $response = $this->actingAs($this->storeUser, 'sanctum')
            ->withHeaders(['X-Client-Platform' => 'mobile'])
            ->postJson('/api/v1/store/receive', [
                'project_id' => $this->project->id,
                'bom_item_id' => $this->mfgItem->id,
                'supplier_id' => $this->supplier->id,
                'part_type' => 'BOP',
                'side' => 'LH',
                'received_quantity' => 3,
                'delivery_note_number' => 'DN-MOB-FORGED',
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['part_type']);
    }

    public function test_mobile_bulk_receive_strictly_rejects_mixed_or_non_mfg_items()
    {
        $response = $this->actingAs($this->storeUser, 'sanctum')
            ->postJson('/api/v1/mobile/store/bulk-receive', [
                'project_id' => $this->project->id,
                'delivery_note_number' => 'DN-MOB-BULK-01',
                'supplier_id' => $this->supplier->id,
                'items' => [
                    [
                        'bom_item_id' => $this->mfgItem->id,
                        'side' => 'LH',
                        'quantity' => 2,
                    ],
                    [
                        'bom_item_id' => $this->bopItem->id,
                        'side' => 'COMMON',
                        'quantity' => 2,
                    ],
                ],
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['part_type']);
    }

    public function test_mobile_bulk_receive_succeeds_when_all_items_are_mfg()
    {
        $mfgItem2 = BomItem::create([
            'project_id' => $this->project->id,
            'standard_part_no' => 'MFG-PART-2-' . uniqid(),
            'part_type' => 'MFG',
            'supplier_id' => $this->supplier->id,
            'jig_no' => 'JIG-01',
            'unit_no' => 'Unit 1',
        ]);
        BomRequirement::create([
            'bom_item_id' => $mfgItem2->id,
            'side' => 'LH',
            'required_quantity' => 10,
        ]);

        $response = $this->actingAs($this->storeUser, 'sanctum')
            ->postJson('/api/v1/mobile/store/bulk-receive', [
                'project_id' => $this->project->id,
                'delivery_note_number' => 'DN-MOB-BULK-OK',
                'supplier_id' => $this->supplier->id,
                'items' => [
                    [
                        'bom_item_id' => $this->mfgItem->id,
                        'side' => 'LH',
                        'quantity' => 2,
                    ],
                    [
                        'bom_item_id' => $mfgItem2->id,
                        'side' => 'LH',
                        'quantity' => 3,
                    ],
                ],
            ]);

        $response->assertSuccessful();
        $this->assertEquals(2, $response->json('items_count'));
    }

    public function test_mobile_hierarchy_endpoint_defaults_to_mfg_only()
    {
        $response = $this->actingAs($this->storeUser, 'sanctum')
            ->getJson('/api/v1/mobile/store/hierarchy?project_id=' . $this->project->id);

        $response->assertStatus(200);
        $jigs = $response->json('jigs');
        $this->assertNotEmpty($jigs);

        // Verify no BOP or STD parts are present in mobile hierarchy response
        foreach ($jigs as $jig) {
            foreach ($jig['units'] ?? [] as $unit) {
                foreach ($unit['sides'] ?? [] as $side) {
                    foreach ($side['parts'] ?? [] as $part) {
                        $this->assertEquals('MFG', $part['part_type'] ?? 'MFG', 'Mobile hierarchy must contain only MFG parts');
                    }
                }
            }
        }
    }
}
