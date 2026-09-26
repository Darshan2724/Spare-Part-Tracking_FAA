<?php

namespace Tests\Feature;

use App\Models\BomItem;
use App\Models\BomRequirement;
use App\Models\EcnRequirement;
use App\Models\Project;
use App\Models\Receipt;
use App\Models\ReceiptItem;
use App\Models\User;
use App\Services\HierarchyService;
use App\Services\QuantityCalculationService;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * MobileStoreReceiveProjectIdRegressionTest
 *
 * Regression tests for the incident where commit 1d1380f (memory optimization in
 * HierarchyService) silently dropped project_id from the lightweight partObj,
 * causing mobile Store intake to fail with "The project id field is required."
 *
 * Covers both code paths:
 *   - Single item: POST /api/v1/store/receipts (submitStoreReceive)
 *   - Bulk items:  POST /api/v1/store/bulk-receive (handleBulkStoreReceive)
 */
class MobileStoreReceiveProjectIdRegressionTest extends TestCase
{
    protected QuantityCalculationService $quantityService;
    protected HierarchyService $hierarchyService;

    protected array $mobileHeaders = [
        'X-Client-Platform' => 'mobile',
        'X-Source-Channel'  => 'MOBILE_INTAKE',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->quantityService  = new QuantityCalculationService();
        $this->hierarchyService = new HierarchyService($this->quantityService);
    }

    protected function getAuthUser(string $roleName = 'STORE'): User
    {
        $role = Role::firstOrCreate(['name' => $roleName, 'guard_name' => 'web']);
        $user = User::where('email', strtolower($roleName) . '@sparetrack.internal')->first();
        if (!$user) {
            $user = User::create([
                'name'     => ucfirst($roleName) . ' User',
                'email'    => strtolower($roleName) . '@sparetrack.internal',
                'password' => bcrypt('password123'),
            ]);
        }
        $user->syncRoles([$roleName]);
        return $user;
    }

    protected function createTestFixture(
        string $side = 'RH',
        int $requiredQty = 10,
        string $partNo = null,
        string $jigNo = 'JIG-REG-01',
        string $unitNo = 'UNIT-01'
    ): array {
        $partNo = $partNo ?? 'PART-REG-' . uniqid();

        $project = Project::create([
            'project_code' => 'TEST-REG-' . uniqid(),
            'name'         => 'Regression Test Project',
            'status'       => 'active',
            'is_test_data' => true,
        ]);

        $bomItem = BomItem::create([
            'project_id'       => $project->id,
            'standard_part_no' => $partNo,
            'item_no'          => '01',
            'jig_no'           => $jigNo,
            'unit_no'          => $unitNo,
            'part_type'        => 'MFG',
        ]);

        $req = BomRequirement::create([
            'bom_item_id'       => $bomItem->id,
            'side'              => $side,
            'required_quantity' => $requiredQty,
        ]);

        return compact('project', 'bomItem', 'req');
    }

    protected function cleanupFixture(array $fixture): void
    {
        ReceiptItem::where('bom_item_id', $fixture['bomItem']->id)->forceDelete();
        $fixture['req']->delete();
        $fixture['bomItem']->delete();
        $fixture['project']->forceDelete();
    }

    // 1. HIERARCHY API — project_id presence

    public function test_store_hierarchy_part_includes_project_id(): void
    {
        $storeUser = $this->getAuthUser('STORE');
        $fixture   = $this->createTestFixture('RH', 5);

        $this->actingAs($storeUser, 'sanctum');
        $res = $this->withHeaders($this->mobileHeaders)
                    ->getJson("/api/v1/store/hierarchy?project_id={$fixture['project']->id}");

        $res->assertStatus(200);
        $this->assertTrue($res->json('is_hierarchical'), 'Response must be hierarchical when project_id is given');

        $jigs = $res->json('jigs');
        $this->assertNotEmpty($jigs, 'Jigs must be present');

        $part = $jigs[0]['units'][0]['parts'][0];

        $this->assertArrayHasKey(
            'project_id',
            $part,
            'REGRESSION: Each part in the store hierarchy must include project_id. ' .
            'Was dropped in commit 1d1380f causing mobile receipt 422.'
        );
        $this->assertEquals(
            $fixture['project']->id,
            $part['project_id'],
            'project_id in hierarchy part must exactly match the project.'
        );

        $this->cleanupFixture($fixture);
    }

    public function test_store_hierarchy_all_parts_include_project_id(): void
    {
        $storeUser = $this->getAuthUser('STORE');
        $project   = Project::create([
            'project_code' => 'TEST-ALL-' . uniqid(),
            'name'         => 'All Parts Project ID Test',
            'status'       => 'active',
            'is_test_data' => true,
        ]);

        $bomItems = [];
        $reqs     = [];
        foreach (['LH', 'RH', 'COMMON'] as $i => $side) {
            $bi = BomItem::create([
                'project_id'       => $project->id,
                'standard_part_no' => "PART-ALL-{$side}-" . uniqid(),
                'item_no'          => (string)($i + 1),
                'jig_no'           => 'JIG-ALL',
                'unit_no'          => '01',
                'part_type'        => 'MFG',
            ]);
            $reqs[] = BomRequirement::create([
                'bom_item_id'       => $bi->id,
                'side'              => $side,
                'required_quantity' => 3,
            ]);
            $bomItems[] = $bi;
        }

        $this->actingAs($storeUser, 'sanctum');
        $res = $this->withHeaders($this->mobileHeaders)
                    ->getJson("/api/v1/store/hierarchy?project_id={$project->id}");

        $res->assertStatus(200);
        $jigs = $res->json('jigs');
        $this->assertNotEmpty($jigs);

        foreach ($jigs as $jig) {
            foreach ($jig['units'] as $unit) {
                foreach ($unit['parts'] as $part) {
                    $this->assertArrayHasKey('project_id', $part,
                        "Part {$part['standard_part_no']} missing project_id in hierarchy response");
                    $this->assertEquals($project->id, $part['project_id'],
                        "project_id mismatch for part {$part['standard_part_no']}");
                }
            }
        }

        foreach ($reqs as $r) $r->delete();
        foreach ($bomItems as $bi) { ReceiptItem::where('bom_item_id', $bi->id)->forceDelete(); $bi->delete(); }
        $project->forceDelete();
    }

    // 2. BACKEND VALIDATION — missing project_id must be rejected

    public function test_mobile_store_receive_without_project_id_returns_422(): void
    {
        $storeUser = $this->getAuthUser('STORE');
        $fixture   = $this->createTestFixture('RH', 5);

        $this->actingAs($storeUser, 'sanctum');
        $res = $this->withHeaders($this->mobileHeaders)
                    ->postJson('/api/v1/store/receipts', [
                        'source'    => 'MOBILE_INTAKE',
                        'part_type' => 'MFG',
                        'items'     => [
                            ['bom_item_id' => $fixture['bomItem']->id, 'side' => 'RH', 'received_quantity' => 2],
                        ],
                    ]);

        $res->assertStatus(422);

        $this->cleanupFixture($fixture);
    }

    public function test_mobile_store_receive_with_null_project_id_returns_422(): void
    {
        $storeUser = $this->getAuthUser('STORE');
        $fixture   = $this->createTestFixture('LH', 5);

        $this->actingAs($storeUser, 'sanctum');
        $res = $this->withHeaders($this->mobileHeaders)
                    ->postJson('/api/v1/store/receipts', [
                        'project_id' => null,
                        'source'     => 'MOBILE_INTAKE',
                        'part_type'  => 'MFG',
                        'items'      => [
                            ['bom_item_id' => $fixture['bomItem']->id, 'side' => 'LH', 'received_quantity' => 1],
                        ],
                    ]);

        $res->assertStatus(422);

        $this->cleanupFixture($fixture);
    }

    // 3. SINGLE RECEIVE — LH / RH / COMMON sides

    public function test_mobile_store_receive_single_item_lh_side_succeeds(): void
    {
        $storeUser = $this->getAuthUser('STORE');
        $fixture   = $this->createTestFixture('LH', 8);

        $this->actingAs($storeUser, 'sanctum');
        $res = $this->withHeaders($this->mobileHeaders)
                    ->postJson('/api/v1/store/receipts', [
                        'project_id'           => $fixture['project']->id,
                        'delivery_note_number' => 'DN-LH-001',
                        'source'               => 'MOBILE_INTAKE',
                        'part_type'            => 'MFG',
                        'items'                => [
                            ['bom_item_id' => $fixture['bomItem']->id, 'side' => 'LH', 'received_quantity' => 3],
                        ],
                    ]);

        $res->assertStatus(200)->assertJson(['success' => true]);

        $receiptItem = ReceiptItem::where('bom_item_id', $fixture['bomItem']->id)
                                  ->where('side', 'LH')->where('status', 'received')->first();
        $this->assertNotNull($receiptItem, 'ReceiptItem must be created with status=received');
        $this->assertEquals(3, $receiptItem->received_quantity);

        $this->cleanupFixture($fixture);
    }

    public function test_mobile_store_receive_single_item_rh_side_succeeds(): void
    {
        $storeUser = $this->getAuthUser('STORE');
        $fixture   = $this->createTestFixture('RH', 6);

        $this->actingAs($storeUser, 'sanctum');
        $res = $this->withHeaders($this->mobileHeaders)
                    ->postJson('/api/v1/store/receipts', [
                        'project_id'           => $fixture['project']->id,
                        'delivery_note_number' => 'DN-RH-001',
                        'source'               => 'MOBILE_INTAKE',
                        'part_type'            => 'MFG',
                        'items'                => [
                            ['bom_item_id' => $fixture['bomItem']->id, 'side' => 'RH', 'received_quantity' => 6],
                        ],
                    ]);

        $res->assertStatus(200)->assertJson(['success' => true]);

        $receiptItem = ReceiptItem::where('bom_item_id', $fixture['bomItem']->id)
                                  ->where('side', 'RH')->where('status', 'received')->first();
        $this->assertNotNull($receiptItem);
        $this->assertEquals(6, $receiptItem->received_quantity);

        $this->cleanupFixture($fixture);
    }

    public function test_mobile_store_receive_single_item_common_side_succeeds(): void
    {
        $storeUser = $this->getAuthUser('STORE');
        $fixture   = $this->createTestFixture('COMMON', 4);

        $this->actingAs($storeUser, 'sanctum');
        $res = $this->withHeaders($this->mobileHeaders)
                    ->postJson('/api/v1/store/receipts', [
                        'project_id'           => $fixture['project']->id,
                        'delivery_note_number' => 'DN-COMMON-001',
                        'source'               => 'MOBILE_INTAKE',
                        'part_type'            => 'MFG',
                        'items'                => [
                            ['bom_item_id' => $fixture['bomItem']->id, 'side' => 'COMMON', 'received_quantity' => 4],
                        ],
                    ]);

        $res->assertStatus(200)->assertJson(['success' => true]);

        $receiptItem = ReceiptItem::where('bom_item_id', $fixture['bomItem']->id)
                                  ->where('side', 'COMMON')->where('status', 'received')->first();
        $this->assertNotNull($receiptItem, 'ReceiptItem must be created for COMMON side');
        $this->assertEquals(4, $receiptItem->received_quantity);

        $this->cleanupFixture($fixture);
    }

    // 4. PARTIAL RECEIPT

    public function test_mobile_store_receive_partial_quantity_succeeds(): void
    {
        $storeUser = $this->getAuthUser('STORE');
        $fixture   = $this->createTestFixture('RH', 10);
        $projectId = $fixture['project']->id;
        $bomItemId = $fixture['bomItem']->id;

        $this->actingAs($storeUser, 'sanctum');

        $this->withHeaders($this->mobileHeaders)->postJson('/api/v1/store/receipts', [
            'project_id' => $projectId, 'source' => 'MOBILE_INTAKE', 'part_type' => 'MFG',
            'items' => [['bom_item_id' => $bomItemId, 'side' => 'RH', 'received_quantity' => 3]],
        ])->assertStatus(200)->assertJson(['success' => true]);

        $total1 = ReceiptItem::where('bom_item_id', $bomItemId)->where('side', 'RH')
                             ->whereIn('status', QuantityCalculationService::VALID_RECEIPT_STATUSES)
                             ->sum('received_quantity');
        $this->assertEquals(3, $total1, 'After first receipt total should be 3');

        $this->withHeaders($this->mobileHeaders)->postJson('/api/v1/store/receipts', [
            'project_id' => $projectId, 'source' => 'MOBILE_INTAKE', 'part_type' => 'MFG',
            'items' => [['bom_item_id' => $bomItemId, 'side' => 'RH', 'received_quantity' => 4]],
        ])->assertStatus(200)->assertJson(['success' => true]);

        $total2 = ReceiptItem::where('bom_item_id', $bomItemId)->where('side', 'RH')
                             ->whereIn('status', QuantityCalculationService::VALID_RECEIPT_STATUSES)
                             ->sum('received_quantity');
        $this->assertEquals(7, $total2, 'After second receipt total should be 7 (3+4)');
        $this->assertEquals(3, max(0, 10 - $total2), 'Remaining pending should be 3 (10-7)');

        $this->cleanupFixture($fixture);
    }

    // 5. IDEMPOTENCY — over-receive prevention

    public function test_mobile_store_receive_idempotency_prevents_over_receive(): void
    {
        $storeUser = $this->getAuthUser('STORE');
        $fixture   = $this->createTestFixture('RH', 2);
        $projectId = $fixture['project']->id;
        $bomItemId = $fixture['bomItem']->id;

        $this->actingAs($storeUser, 'sanctum');

        $this->withHeaders($this->mobileHeaders)->postJson('/api/v1/store/receipts', [
            'project_id' => $projectId, 'source' => 'MOBILE_INTAKE', 'part_type' => 'MFG',
            'items' => [['bom_item_id' => $bomItemId, 'side' => 'RH', 'received_quantity' => 2]],
        ])->assertStatus(200)->assertJson(['success' => true]);

        // Attempt to receive 2 more — must be capped to 0 (no-op)
        $this->withHeaders($this->mobileHeaders)->postJson('/api/v1/store/receipts', [
            'project_id' => $projectId, 'source' => 'MOBILE_INTAKE', 'part_type' => 'MFG',
            'items' => [['bom_item_id' => $bomItemId, 'side' => 'RH', 'received_quantity' => 2]],
        ])->assertStatus(200);

        $totalReceived = ReceiptItem::where('bom_item_id', $bomItemId)
                                    ->where('side', 'RH')
                                    ->whereIn('status', QuantityCalculationService::VALID_RECEIPT_STATUSES)
                                    ->sum('received_quantity');
        $this->assertEquals(2, $totalReceived, 'Total received must never exceed required quantity (2)');

        $this->cleanupFixture($fixture);
    }

    // 6. BULK RECEIVE

    public function test_mobile_store_bulk_receive_succeeds_with_project_id(): void
    {
        $storeUser = $this->getAuthUser('STORE');

        $project = Project::create([
            'project_code' => 'TEST-BULK-' . uniqid(),
            'name'         => 'Bulk Receive Test Project',
            'status'       => 'active',
            'is_test_data' => true,
        ]);

        $bomItems = [];
        $reqs     = [];
        for ($i = 1; $i <= 3; $i++) {
            $bi = BomItem::create([
                'project_id' => $project->id, 'standard_part_no' => "BULK-PART-{$i}-" . uniqid(),
                'item_no' => (string)$i, 'jig_no' => 'JIG-BULK', 'unit_no' => '01', 'part_type' => 'MFG',
            ]);
            $reqs[] = BomRequirement::create([
                'bom_item_id' => $bi->id, 'side' => 'LH', 'required_quantity' => 5,
            ]);
            $bomItems[] = $bi;
        }

        $items = array_map(fn($bi) => [
            'bom_item_id' => $bi->id, 'side' => 'LH', 'received_quantity' => 2, 'quantity' => 2,
        ], $bomItems);

        $this->actingAs($storeUser, 'sanctum');
        $res = $this->withHeaders($this->mobileHeaders)
                    ->postJson('/api/v1/store/bulk-receive', [
                        'project_id' => $project->id, 'delivery_note_number' => 'DN-BULK-001',
                        'source' => 'MOBILE_INTAKE', 'part_type' => 'MFG', 'items' => $items,
                    ]);

        $res->assertStatus(200)->assertJson(['success' => true]);

        foreach ($bomItems as $bi) {
            $ri = ReceiptItem::where('bom_item_id', $bi->id)->where('side', 'LH')->where('status', 'received')->first();
            $this->assertNotNull($ri, "ReceiptItem must exist for BomItem {$bi->id}");
            $this->assertEquals(2, $ri->received_quantity);
        }

        foreach ($bomItems as $bi) ReceiptItem::where('bom_item_id', $bi->id)->forceDelete();
        foreach ($reqs as $r) $r->delete();
        foreach ($bomItems as $bi) $bi->delete();
        $project->forceDelete();
    }

    // 7. DEDICATED MOBILE ENDPOINTS

    public function test_dedicated_mobile_store_receive_endpoint_succeeds(): void
    {
        $storeUser = $this->getAuthUser('STORE');
        $fixture   = $this->createTestFixture('RH', 4);

        $this->actingAs($storeUser, 'sanctum');
        $res = $this->withHeaders($this->mobileHeaders)
                    ->postJson('/api/v1/mobile/store/receive', [
                        'project_id'           => $fixture['project']->id,
                        'delivery_note_number' => 'DN-MOB-001',
                        'source'               => 'MOBILE_INTAKE',
                        'part_type'            => 'MFG',
                        'items'                => [
                            ['bom_item_id' => $fixture['bomItem']->id, 'side' => 'RH', 'received_quantity' => 2],
                        ],
                    ]);

        $res->assertStatus(200)->assertJson(['success' => true]);

        $ri = ReceiptItem::where('bom_item_id', $fixture['bomItem']->id)
                         ->where('side', 'RH')->where('status', 'received')->first();
        $this->assertNotNull($ri);
        $this->assertEquals(2, $ri->received_quantity);

        $this->cleanupFixture($fixture);
    }

    public function test_dedicated_mobile_store_bulk_receive_endpoint_succeeds(): void
    {
        $storeUser = $this->getAuthUser('STORE');
        $fixture   = $this->createTestFixture('LH', 10);

        $this->actingAs($storeUser, 'sanctum');
        $res = $this->withHeaders($this->mobileHeaders)
                    ->postJson('/api/v1/mobile/store/bulk-receive', [
                        'project_id' => $fixture['project']->id,
                        'source'     => 'MOBILE_INTAKE',
                        'part_type'  => 'MFG',
                        'items'      => [
                            ['bom_item_id' => $fixture['bomItem']->id, 'side' => 'LH', 'received_quantity' => 5],
                        ],
                    ]);

        $res->assertStatus(200)->assertJson(['success' => true]);

        $ri = ReceiptItem::where('bom_item_id', $fixture['bomItem']->id)
                         ->where('side', 'LH')->where('status', 'received')->first();
        $this->assertNotNull($ri);
        $this->assertEquals(5, $ri->received_quantity);

        $this->cleanupFixture($fixture);
    }

    // 8. ADMIN USER PATH

    public function test_admin_user_can_perform_mobile_store_receive(): void
    {
        $adminUser = $this->getAuthUser('ADMIN');
        $fixture   = $this->createTestFixture('RH', 5);

        $this->actingAs($adminUser, 'sanctum');
        $res = $this->withHeaders($this->mobileHeaders)
                    ->postJson('/api/v1/store/receipts', [
                        'project_id' => $fixture['project']->id,
                        'source'     => 'MOBILE_INTAKE',
                        'part_type'  => 'MFG',
                        'items'      => [
                            ['bom_item_id' => $fixture['bomItem']->id, 'side' => 'RH', 'received_quantity' => 5],
                        ],
                    ]);

        $res->assertStatus(200)->assertJson(['success' => true]);

        $this->cleanupFixture($fixture);
    }
}
