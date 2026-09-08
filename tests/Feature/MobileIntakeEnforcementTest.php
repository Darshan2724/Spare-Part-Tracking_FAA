<?php

namespace Tests\Feature;

use App\Models\AssemblyRecord;
use App\Models\BomItem;
use App\Models\BomRequirement;
use App\Models\Project;
use App\Models\Receipt;
use App\Models\ReceiptItem;
use App\Models\User;
use App\Services\HierarchyService;
use App\Services\QuantityCalculationService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class MobileIntakeEnforcementTest extends TestCase
{
    use DatabaseTransactions;

    protected HierarchyService $hierarchyService;
    protected QuantityCalculationService $quantityService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->quantityService = new QuantityCalculationService();
        $this->hierarchyService = new HierarchyService($this->quantityService);
    }

    protected function getStoreUser(): User
    {
        $role = Role::firstOrCreate(['name' => 'STORE', 'guard_name' => 'web']);

        $user = User::where('email', 'store_test@sparetrack.internal')->first();
        if (!$user) {
            $user = User::create([
                'name' => 'Store Test User',
                'email' => 'store_test@sparetrack.internal',
                'password' => bcrypt('password'),
            ]);
        }
        if (!$user->hasRole('STORE')) {
            $user->assignRole($role);
        }
        return $user;
    }

    public function test_mobile_store_intake_rejects_bop_parts_with_422(): void
    {
        $user = $this->getStoreUser();

        $project = Project::create([
            'name' => 'Test Project BOP Intake',
            'project_code' => 'TEST-BOP-REJECT-' . uniqid(),
            'status' => 'active',
        ]);

        $bopItem = BomItem::create([
            'project_id' => $project->id,
            'jig_no' => 'JIG-01',
            'unit_no' => 'Unit 1',
            'item_no' => 'ITM-BOP-1',
            'part_name' => 'BOP Pneumatic Valve',
            'standard_part_no' => 'BOP-VALVE-01',
            'part_type' => 'BOP',
        ]);

        BomRequirement::create([
            'bom_item_id' => $bopItem->id,
            'side' => 'LH',
            'required_quantity' => 5,
        ]);

        $payload = [
            'project_id' => $project->id,
            'delivery_note_number' => 'DN-BOP-TEST-' . uniqid(),
            'remarks' => 'Testing BOP intake rejection',
            'items' => [
                [
                    'bom_item_id' => $bopItem->id,
                    'side' => 'LH',
                    'received_quantity' => 2,
                ],
            ],
        ];

        $response = $this->actingAs($user, 'web')->postJson('/api/v1/mobile/store/receive', $payload);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['part_type']);
        $this->assertStringContainsString('Mobile part intake is strictly restricted to MFG items', $response->json('errors.part_type.0'));
    }

    public function test_mobile_store_intake_rejects_std_parts_with_422(): void
    {
        $user = $this->getStoreUser();

        $project = Project::create([
            'name' => 'Test Project STD Intake',
            'project_code' => 'TEST-STD-REJECT-' . uniqid(),
            'status' => 'active',
        ]);

        $stdItem = BomItem::create([
            'project_id' => $project->id,
            'jig_no' => 'JIG-01',
            'unit_no' => 'Unit 1',
            'item_no' => 'ITM-STD-1',
            'part_name' => 'STD Hex Bolt',
            'standard_part_no' => 'STD-BOLT-M8',
            'part_type' => 'STD',
        ]);

        BomRequirement::create([
            'bom_item_id' => $stdItem->id,
            'side' => 'LH',
            'required_quantity' => 10,
        ]);

        $payload = [
            'project_id' => $project->id,
            'delivery_note_number' => 'DN-STD-TEST-' . uniqid(),
            'remarks' => 'Testing STD intake rejection',
            'items' => [
                [
                    'bom_item_id' => $stdItem->id,
                    'side' => 'LH',
                    'received_quantity' => 4,
                ],
            ],
        ];

        $response = $this->actingAs($user, 'web')->postJson('/api/v1/mobile/store/receive', $payload);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['part_type']);
        $this->assertStringContainsString('Mobile part intake is strictly restricted to MFG items', $response->json('errors.part_type.0'));
    }

    public function test_mobile_store_intake_accepts_mfg_parts(): void
    {
        $user = $this->getStoreUser();

        $project = Project::create([
            'name' => 'Test Project MFG Intake',
            'project_code' => 'TEST-MFG-ACCEPT-' . uniqid(),
            'status' => 'active',
        ]);

        $mfgItem = BomItem::create([
            'project_id' => $project->id,
            'jig_no' => 'JIG-01',
            'unit_no' => 'Unit 1',
            'item_no' => 'ITM-MFG-1',
            'part_name' => 'MFG Base Plate',
            'standard_part_no' => 'MFG-PLATE-01',
            'part_type' => 'MFG',
        ]);

        BomRequirement::create([
            'bom_item_id' => $mfgItem->id,
            'side' => 'LH',
            'required_quantity' => 6,
        ]);

        $payload = [
            'project_id' => $project->id,
            'delivery_note_number' => 'DN-MFG-TEST-' . uniqid(),
            'remarks' => 'Testing MFG intake acceptance',
            'items' => [
                [
                    'bom_item_id' => $mfgItem->id,
                    'side' => 'LH',
                    'received_quantity' => 6,
                ],
            ],
        ];

        $response = $this->actingAs($user, 'web')->postJson('/api/v1/mobile/store/receive', $payload);

        $response->assertStatus(200);
        $this->assertDatabaseHas('receipt_items', [
            'bom_item_id' => $mfgItem->id,
            'side' => 'LH',
            'received_quantity' => 6,
        ]);
    }

    public function test_completed_assembly_parts_counted_as_valid_received_and_show_completed_status(): void
    {
        $user = $this->getStoreUser();

        $project = Project::create([
            'name' => 'Test Assembly Completed Visibility',
            'project_code' => 'TEST-ASM-COMP-' . uniqid(),
            'status' => 'active',
        ]);

        $mfgItem = BomItem::create([
            'project_id' => $project->id,
            'jig_no' => 'JIG-01',
            'unit_no' => 'Unit 1',
            'item_no' => 'ITM-MFG-1',
            'part_name' => 'MFG Pin',
            'standard_part_no' => 'MFG-PIN-01',
            'part_type' => 'MFG',
        ]);

        BomRequirement::create([
            'bom_item_id' => $mfgItem->id,
            'side' => 'LH',
            'required_quantity' => 4,
        ]);

        $receipt = Receipt::create([
            'project_id' => $project->id,
            'delivery_note_number' => 'DN-ASM-TEST-' . uniqid(),
            'received_by' => $user->id,
        ]);

        // Receipt item transitioned all the way to assembly_completed
        $receiptItem = ReceiptItem::create([
            'receipt_id' => $receipt->id,
            'bom_item_id' => $mfgItem->id,
            'side' => 'LH',
            'received_quantity' => 4,
            'status' => 'assembly_completed',
        ]);

        AssemblyRecord::create([
            'bom_item_id' => $mfgItem->id,
            'side' => 'LH',
            'quantity' => 4,
            'status' => 'completed',
            'assembled_by' => $user->id,
            'created_at' => now(),
        ]);

        // Query hierarchy service
        $hierarchy = $this->hierarchyService->getDepartmentHierarchy('manager', $project->id, []);

        $this->assertNotEmpty($hierarchy['jigs']);
        $jig = $hierarchy['jigs'][0];
        $this->assertEquals(4, $jig['total_received']);
        $this->assertEquals(0, $jig['total_pending']);
        $this->assertTrue($jig['is_complete']);

        $unit = $jig['units'][0];
        $this->assertEquals(4, $unit['sides']['LH']['total_received']);
        $this->assertEquals(0, $unit['sides']['LH']['pending_quantity']);
        $this->assertTrue($unit['sides']['LH']['is_complete']);

        $part = $unit['sides']['LH']['parts'][0];
        $this->assertEquals(4, $part['received_qty']);
        $this->assertEquals(0, $part['pending_qty']);
        $this->assertEquals('Completed', $part['status_badge']);
    }
}
