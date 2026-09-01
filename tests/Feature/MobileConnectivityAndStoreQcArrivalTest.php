<?php

namespace Tests\Feature;

use App\Models\BomItem;
use App\Models\BomRequirement;
use App\Models\Project;
use App\Models\ReceiptItem;
use App\Models\User;
use App\Services\HierarchyService;
use App\Services\QuantityCalculationService;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class MobileConnectivityAndStoreQcArrivalTest extends TestCase
{
    protected QuantityCalculationService $quantityService;
    protected HierarchyService $hierarchyService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->quantityService = new QuantityCalculationService();
        $this->hierarchyService = new HierarchyService($this->quantityService);
    }

    protected function getAuthUser(string $roleName = 'ADMIN'): User
    {
        $role = Role::firstOrCreate(['name' => $roleName, 'guard_name' => 'web']);
        $user = User::where('email', strtolower($roleName) . '@sparetrack.internal')->first();
        if (!$user) {
            $user = User::create([
                'name' => ucfirst($roleName) . ' User',
                'email' => strtolower($roleName) . '@sparetrack.internal',
                'password' => bcrypt('password123'),
            ]);
        }
        $user->syncRoles([$roleName]);
        return $user;
    }

    /**
     * Test Health and public endpoints used by Mobile Network Ping Tester.
     */
    public function test_health_endpoints_accessible()
    {
        $res1 = $this->getJson('/api/v1/health');
        $res1->assertStatus(200)
            ->assertJsonStructure(['status', 'checks']);

        $res2 = $this->getJson('/api/health');
        $res2->assertStatus(200)
            ->assertJsonStructure(['status']);
    }

    /**
     * Test that Store Receipt of Regular parts immediately populates qc_pending_arrival in /qc/hierarchy.
     */
    public function test_regular_store_receive_immediately_populates_qc_hierarchy_pending_arrival()
    {
        $storeUser = $this->getAuthUser('STORE');
        $qcUser = $this->getAuthUser('QC');

        $project = Project::create([
            'project_code' => 'TEST-QC-ARR-' . uniqid(),
            'name' => 'QC Arrival Test Project',
            'status' => 'active',
            'is_test_data' => true,
        ]);

        $bomItem = BomItem::create([
            'project_id' => $project->id,
            'standard_part_no' => 'PART-QC-ARR-001',
            'item_no' => '01',
            'jig_no' => 'JIG-01',
            'unit_no' => 'UNIT-01',
        ]);

        BomRequirement::create([
            'bom_item_id' => $bomItem->id,
            'side' => 'RH',
            'required_quantity' => 10,
        ]);

        // 1. Store receives 4 parts (Status = 'received')
        $this->actingAs($storeUser, 'sanctum');
        $response = $this->postJson('/api/v1/store/receipts', [
            'project_id' => $project->id,
            'delivery_note_number' => 'DN-QC-001',
            'items' => [
                [
                    'bom_item_id' => $bomItem->id,
                    'side' => 'RH',
                    'received_quantity' => 4,
                ]
            ]
        ]);
        $response->assertStatus(200)->assertJson(['success' => true]);

        // 2. Query /qc/hierarchy with QC User
        $this->actingAs($qcUser, 'sanctum');
        $qcHierarchyRes = $this->getJson("/api/v1/qc/hierarchy?project_id={$project->id}&side=RH");
        $qcHierarchyRes->assertStatus(200);

        $jigs = $qcHierarchyRes->json('jigs');
        $this->assertNotEmpty($jigs, 'Jigs should be returned in QC hierarchy');

        $unit = $jigs[0]['units'][0];
        $this->assertNotEmpty($unit['parts'], 'Parts list in unit should not be empty');

        $part = $unit['parts'][0];
        $this->assertEquals('PART-QC-ARR-001', $part['standard_part_no']);
        
        $rhStats = $part['side_stats']['RH'];
        $this->assertEquals(10, $rhStats['required']);
        $this->assertEquals(4, $rhStats['received']);
        $this->assertEquals(6, $rhStats['pending']);
        // Crucial invariant: qc_pending_arrival must be 4 so Mobile QC Arrival displays the part!
        $this->assertEquals(4, $rhStats['qc_pending_arrival'], 'qc_pending_arrival must equal 4 immediately upon store receipt');
        $this->assertEquals(0, $rhStats['qc_pending_inspection']);

        // 3. Confirm QC Physical Arrival (/qc/receive)
        $receiptItem = ReceiptItem::where('bom_item_id', $bomItem->id)->where('side', 'RH')->first();
        $qcReceiveRes = $this->postJson('/api/v1/qc/receive', [
            'receipt_item_id' => $receiptItem->id,
            'quantity' => 4,
        ]);
        $qcReceiveRes->assertStatus(200)->assertJson(['success' => true]);

        // 4. Query /qc/hierarchy after physical arrival
        $qcHierarchyRes2 = $this->getJson("/api/v1/qc/hierarchy?project_id={$project->id}&side=RH");
        $partAfterArrival = $qcHierarchyRes2->json('jigs')[0]['units'][0]['parts'][0];
        $rhStatsAfterArrival = $partAfterArrival['side_stats']['RH'];

        $this->assertEquals(0, $rhStatsAfterArrival['qc_pending_arrival'], 'qc_pending_arrival must become 0 after physical arrival check');
        $this->assertEquals(4, $rhStatsAfterArrival['qc_pending_inspection'], 'qc_pending_inspection must become 4 for inspection stage');

        // 5. Clean up test project
        ReceiptItem::where('bom_item_id', $bomItem->id)->delete();
        BomRequirement::where('bom_item_id', $bomItem->id)->delete();
        $bomItem->delete();
        $project->delete();
    }

    /**
     * Test Partial Store Receipt and Split Physical Arrival.
     */
    public function test_partial_store_receipt_and_split_physical_arrival()
    {
        $storeUser = $this->getAuthUser('STORE');
        $qcUser = $this->getAuthUser('QC');

        $project = Project::create([
            'project_code' => 'TEST-SPLIT-QC-' . uniqid(),
            'name' => 'Split QC Test Project',
            'status' => 'active',
            'is_test_data' => true,
        ]);

        $bomItem = BomItem::create([
            'project_id' => $project->id,
            'standard_part_no' => 'PART-SPLIT-002',
            'item_no' => '02',
            'jig_no' => 'JIG-02',
            'unit_no' => 'UNIT-02',
        ]);

        BomRequirement::create([
            'bom_item_id' => $bomItem->id,
            'side' => 'LH',
            'required_quantity' => 8,
        ]);

        // Store receives 5 units
        $this->actingAs($storeUser, 'sanctum');
        $this->postJson('/api/v1/store/receipts', [
            'project_id' => $project->id,
            'items' => [
                [
                    'bom_item_id' => $bomItem->id,
                    'side' => 'LH',
                    'received_quantity' => 5,
                ]
            ]
        ])->assertStatus(200);

        // QC physically accepts only 2 units (Partial Arrival)
        $this->actingAs($qcUser, 'sanctum');
        $receiptItem = ReceiptItem::where('bom_item_id', $bomItem->id)->where('side', 'LH')->first();
        $this->postJson('/api/v1/qc/receive', [
            'receipt_item_id' => $receiptItem->id,
            'quantity' => 2,
        ])->assertStatus(200);

        // Verify hierarchy has 3 pending arrival and 2 pending inspection
        $hierarchy = $this->getJson("/api/v1/qc/hierarchy?project_id={$project->id}&side=LH");
        $part = $hierarchy->json('jigs')[0]['units'][0]['parts'][0];
        $lh = $part['side_stats']['LH'];

        $this->assertEquals(3, $lh['qc_pending_arrival']);
        $this->assertEquals(2, $lh['qc_pending_inspection']);
        $this->assertEquals(3, $lh['pending']);

        // Clean up
        ReceiptItem::where('bom_item_id', $bomItem->id)->delete();
        BomRequirement::where('bom_item_id', $bomItem->id)->delete();
        $bomItem->delete();
        $project->delete();
    }
}
