<?php

namespace Tests\Feature;

use App\Models\BomItem;
use App\Models\BomRequirement;
use App\Models\EcnRequirement;
use App\Models\EcnReceiptItem;
use App\Models\Project;
use App\Models\Receipt;
use App\Models\ReceiptItem;
use App\Models\User;
use App\Services\HierarchyService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class EcnStoreReceiveAndMixedBulkTest extends TestCase
{
    use DatabaseTransactions;

    protected function getAdminUser(): User
    {
        \Spatie\Permission\Models\Role::firstOrCreate(['name' => 'ADMIN', 'guard_name' => 'web']);
        \Spatie\Permission\Models\Role::firstOrCreate(['name' => 'ADMIN', 'guard_name' => 'sanctum']);

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
        }
        $user->syncRoles(['ADMIN']);
        return $user;
    }

    public function test_ecn_store_receive_with_ecn_id_string_does_not_fail_with_bigint_error()
    {
        $user = $this->getAdminUser();
        $this->actingAs($user, 'sanctum');

        $code = 'TEST-ECN-' . uniqid();
        $project = Project::create([
            'project_code' => $code,
            'name' => "ECN Test Project {$code}",
            'status' => 'active',
        ]);

        $ecnReq = EcnRequirement::create([
            'project_id' => $project->id,
            'ecn_number' => 'ECN-999',
            'jig_no' => 'JIG-100',
            'unit_no' => '01',
            'part_no' => 'ECN-PART-999',
            'side' => 'LH',
            'required_qty' => 5,
            'received_qty' => 0,
            'current_state' => 'PENDING',
        ]);

        // Scenario 1: Payload sends ecn_9 or ecn_requirement_id as string
        $response = $this->postJson('/api/v1/store/bulk-receive', [
            'project_id' => $project->id,
            'delivery_note_number' => 'DN-TEST-001',
            'items' => [
                [
                    'bom_item_id' => "ecn_{$ecnReq->id}",
                    'ecn_requirement_id' => $ecnReq->id,
                    'is_ecn' => true,
                    'side' => 'LH',
                    'received_quantity' => 2,
                ]
            ]
        ]);

        $response->assertStatus(200);
        $response->assertJson([
            'success' => true,
            'ecn_processed' => 1,
        ]);

        $ecnReq->refresh();
        $this->assertEquals(2, $ecnReq->received_qty);
        $this->assertEquals('STORE', $ecnReq->current_state);

        // Verify EcnReceiptItem created
        $this->assertDatabaseHas('ecn_receipt_items', [
            'ecn_requirement_id' => $ecnReq->id,
            'received_quantity' => 2,
            'status' => 'received',
        ]);
    }

    public function test_mixed_regular_and_ecn_bulk_store_receive_succeeds_together()
    {
        $user = $this->getAdminUser();
        $this->actingAs($user, 'sanctum');

        $code = 'TEST-MIX-' . uniqid();
        $project = Project::create([
            'project_code' => $code,
            'name' => "Mixed Project {$code}",
            'status' => 'active',
        ]);

        // Regular BOM item
        $bomItem = BomItem::create([
            'project_id' => $project->id,
            'standard_part_no' => 'REG-PART-1',
            'item_no' => 'RP1',
            'jig_no' => 'JIG-100',
            'unit_no' => '01',
        ]);
        BomRequirement::create([
            'bom_item_id' => $bomItem->id,
            'side' => 'LH',
            'required_quantity' => 10,
        ]);

        // ECN Requirement
        $ecnReq = EcnRequirement::create([
            'project_id' => $project->id,
            'ecn_number' => 'ECN-888',
            'jig_no' => 'JIG-100',
            'unit_no' => '01',
            'part_no' => 'ECN-PART-888',
            'side' => 'LH',
            'required_qty' => 4,
            'received_qty' => 0,
            'current_state' => 'PENDING',
        ]);

        $response = $this->postJson('/api/v1/store/bulk-receive', [
            'project_id' => $project->id,
            'delivery_note_number' => 'DN-MIX-001',
            'items' => [
                [
                    'bom_item_id' => $bomItem->id,
                    'side' => 'LH',
                    'received_quantity' => 3,
                    'is_ecn' => false,
                ],
                [
                    'bom_item_id' => "ecn_{$ecnReq->id}",
                    'ecn_requirement_id' => $ecnReq->id,
                    'side' => 'LH',
                    'received_quantity' => 2,
                    'is_ecn' => true,
                ]
            ]
        ]);

        $response->assertStatus(200);
        $response->assertJson([
            'success' => true,
            'regular_processed' => 1,
            'ecn_processed' => 1,
        ]);

        // Verify Regular Receipt Item
        $this->assertDatabaseHas('receipt_items', [
            'bom_item_id' => $bomItem->id,
            'side' => 'LH',
            'received_quantity' => 3,
            'status' => 'received',
        ]);

        // Verify ECN Receipt Item
        $this->assertDatabaseHas('ecn_receipt_items', [
            'ecn_requirement_id' => $ecnReq->id,
            'received_quantity' => 2,
            'status' => 'received',
        ]);
    }

    public function test_store_received_ecn_part_appears_in_qc_physical_arrival_hierarchy()
    {
        $user = $this->getAdminUser();
        $this->actingAs($user, 'sanctum');

        $code = 'TEST-QC-VIS-' . uniqid();
        $project = Project::create([
            'project_code' => $code,
            'name' => "QC Vis Project {$code}",
            'status' => 'active',
        ]);

        $bomItem = BomItem::create([
            'project_id' => $project->id,
            'standard_part_no' => 'BASE-PART-1',
            'item_no' => 'BP1',
            'jig_no' => 'JIG-100',
            'unit_no' => '01',
        ]);
        BomRequirement::create([
            'bom_item_id' => $bomItem->id,
            'side' => 'LH',
            'required_quantity' => 10,
        ]);

        $ecnReq = EcnRequirement::create([
            'project_id' => $project->id,
            'ecn_number' => 'ECN-777',
            'jig_no' => 'JIG-100',
            'unit_no' => '01',
            'part_no' => 'ECN-PART-777',
            'side' => 'LH',
            'required_qty' => 5,
            'received_qty' => 0,
            'current_state' => 'PENDING',
        ]);

        // 1. Receive into Store
        $this->postJson('/api/v1/store/bulk-receive', [
            'project_id' => $project->id,
            'delivery_note_number' => 'DN-QC-001',
            'items' => [
                [
                    'is_ecn' => true,
                    'ecn_requirement_id' => $ecnReq->id,
                    'side' => 'LH',
                    'received_quantity' => 2,
                ]
            ]
        ])->assertStatus(200);

        // 2. Query Hierarchy for QC Department
        $hierarchyService = new HierarchyService();
        $hierarchy = $hierarchyService->getDepartmentHierarchy('qc', $project->id);

        $this->assertNotEmpty($hierarchy['jigs']);
        $jig = $hierarchy['jigs'][0];
        $unit = $jig['units'][0];

        // Check that ECN part is present in QC unit parts
        $ecnPart = collect($unit['parts'])->firstWhere('is_ecn', true);
        $this->assertNotNull($ecnPart, 'ECN part must appear in QC unit parts when Store has received stock');

        $ecnPartArr = (array)$ecnPart;
        $sideStats = (array)($ecnPartArr['side_stats'] ?? []);
        $sideStat = (array)($sideStats['LH'] ?? $sideStats['COMMON'] ?? []);

        $this->assertEquals(2, $sideStat['qc_pending_arrival']);
        $this->assertEquals('QC', $sideStat['status_badge']);

        // 3. Confirm physical arrival in QC
        $qcRecResponse = $this->postJson('/api/v1/qc/bulk-receive', [
            'items' => [
                [
                    'is_ecn' => true,
                    'ecn_requirement_id' => $ecnReq->id,
                    'side' => 'LH',
                    'quantity' => 2,
                ]
            ]
        ]);

        $qcRecResponse->assertStatus(200);

        // Verify status becomes qc_received
        $this->assertDatabaseHas('ecn_receipt_items', [
            'ecn_requirement_id' => $ecnReq->id,
            'status' => 'qc_received',
        ]);

        // 4. Perform QC Inspection Approval to Assembly
        $inspectResponse = $this->postJson('/api/v1/qc/bulk-inspect', [
            'items' => [
                [
                    'is_ecn' => true,
                    'ecn_requirement_id' => $ecnReq->id,
                    'side' => 'LH',
                    'quantity' => 2,
                ]
            ],
            'result' => 'approved',
            'destination' => 'ASSEMBLY',
        ]);

        $inspectResponse->assertStatus(200);

        $ecnReq->refresh();
        $this->assertEquals('ASSEMBLY', $ecnReq->current_state);
    }

    public function test_mixed_bulk_revert_in_qc_and_store()
    {
        $user = $this->getAdminUser();
        $this->actingAs($user, 'sanctum');

        $code = 'TEST-REV-' . uniqid();
        $project = Project::create([
            'project_code' => $code,
            'name' => "Revert Project {$code}",
            'status' => 'active',
        ]);

        $bomItem = BomItem::create([
            'project_id' => $project->id,
            'standard_part_no' => 'REG-REV-1',
            'item_no' => 'RR1',
            'jig_no' => 'JIG-100',
            'unit_no' => '01',
        ]);
        BomRequirement::create([
            'bom_item_id' => $bomItem->id,
            'side' => 'LH',
            'required_quantity' => 10,
        ]);

        $receipt = Receipt::create([
            'project_id' => $project->id,
            'receipt_number' => 'REC-REV-' . uniqid(),
            'received_by' => $user->id,
            'received_at' => now(),
        ]);
        $recItem = ReceiptItem::create([
            'receipt_id' => $receipt->id,
            'bom_item_id' => $bomItem->id,
            'side' => 'LH',
            'received_quantity' => 5,
            'status' => 'received',
        ]);

        $ecnReq = EcnRequirement::create([
            'project_id' => $project->id,
            'ecn_number' => 'ECN-REV',
            'jig_no' => 'JIG-100',
            'unit_no' => '01',
            'part_no' => 'ECN-REV-PART',
            'side' => 'LH',
            'required_qty' => 5,
            'received_qty' => 3,
            'current_state' => 'STORE',
        ]);
        $ecnRecItem = EcnReceiptItem::create([
            'ecn_requirement_id' => $ecnReq->id,
            'received_quantity' => 3,
            'status' => 'received',
            'received_by' => $user->id,
            'received_at' => now(),
        ]);

        // Bulk revert in store
        $revertResponse = $this->postJson('/api/v1/workflow/bulk-revert', [
            'department' => 'store',
            'reason' => 'Test bulk revert',
            'items' => [
                [
                    'bom_item_id' => $bomItem->id,
                    'side' => 'LH',
                    'quantity' => 2,
                    'source_id' => $recItem->id,
                    'is_ecn' => false,
                ],
                [
                    'is_ecn' => true,
                    'source_id' => $ecnRecItem->id,
                    'ecn_requirement_id' => $ecnReq->id,
                    'quantity' => 2,
                ]
            ]
        ]);

        $revertResponse->assertStatus(200);
        $revertResponse->assertJson([
            'success' => true,
            'regular_count' => 1,
            'ecn_count' => 1,
        ]);
    }
}
