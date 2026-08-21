<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\Project;
use App\Models\BomItem;
use App\Models\BomRequirement;
use App\Models\Receipt;
use App\Models\ReceiptItem;
use App\Models\Supplier;

class DashboardBlockClickTest extends TestCase
{
    protected function getAdminUser(): User
    {
        return User::where('email', 'admin@sparetrack.internal')->first();
    }

    protected function ensureTestFixture(): array
    {
        $project = Project::firstOrCreate(
            ['project_code' => 'TEST-BLK-01'],
            [
                'name' => 'Block Test Project',
                'description' => 'Testing 11 clickable blocks',
                'status' => 'active',
            ]
        );

        $supplier = Supplier::firstOrCreate(
            ['code' => 'SUP-BLK-01'],
            ['name' => 'Block Test Supplier', 'status' => 'ACTIVE']
        );

        // Create 25 BOM items to test that large datasets are NOT arbitrarily limited to 10
        $bomItems = [];
        for ($i = 1; $i <= 25; $i++) {
            $partNo = sprintf('BLK-PART-%03d', $i);
            $bom = BomItem::firstOrCreate(
                ['project_id' => $project->id, 'standard_part_no' => $partNo],
                [
                    'item_no' => 'ITEM-' . $i,
                    'supplier_id' => $supplier->id,
                    'jig_name' => 'JIG-01',
                    'unit_no' => 'UNIT-01',
                    'side' => 'COMMON',
                    'total_required' => 20,
                    'total_received' => 0,
                    'total_pending' => 20,
                ]
            );

            BomRequirement::firstOrCreate(
                ['bom_item_id' => $bom->id, 'side' => 'COMMON'],
                ['required_quantity' => 20, 'received_quantity' => 0, 'pending_quantity' => 20]
            );

            $bomItems[] = $bom;
        }

        return [$project, $supplier, $bomItems];
    }

    public function test_all_11_dashboard_blocks_return_valid_responses()
    {
        $user = $this->getAdminUser();
        [$project] = $this->ensureTestFixture();

        $blocks = [
            'active_projects',
            'completed_projects',
            'delayed_projects',
            'total_parts',
            'total_parts_received',
            'parts_pending',
            'store',
            'qc',
            'rework',
            'paint',
            'assembly',
        ];

        foreach ($blocks as $blk) {
            $res = $this->actingAs($user, 'sanctum')->getJson("/api/v1/dashboard/block-details?block={$blk}");
            $res->assertStatus(200);
            $res->assertJsonStructure([
                'block',
                'title',
                'columns',
                'items',
                'total_quantity',
                'total_records',
            ]);
            $this->assertEquals($blk, $res->json('block'), "Block key mismatch for {$blk}");
        }
    }

    public function test_total_parts_block_does_not_arbitrarily_truncate_to_10_records()
    {
        $user = $this->getAdminUser();
        [$project] = $this->ensureTestFixture();

        $res = $this->actingAs($user, 'sanctum')->getJson("/api/v1/dashboard/block-details?block=total_parts&project_id={$project->id}");
        $res->assertStatus(200);
        
        $items = $res->json('items');
        $this->assertGreaterThanOrEqual(25, count($items), 'Total parts should not be truncated to 10');
        
        // Total required should be 25 * 20 = 500
        $this->assertEquals(500, $res->json('total_quantity'));
    }

    public function test_parts_pending_block_reflects_accurate_pending_quantities()
    {
        $user = $this->getAdminUser();
        [$project] = $this->ensureTestFixture();

        $res = $this->actingAs($user, 'sanctum')->getJson("/api/v1/dashboard/block-details?block=parts_pending&project_id={$project->id}");
        $res->assertStatus(200);
        
        $this->assertGreaterThanOrEqual(25, count($res->json('items')));
        $this->assertEquals(500, $res->json('total_quantity'));
    }

    public function test_store_and_received_blocks_reconcile_when_receipt_added()
    {
        $user = $this->getAdminUser();
        [$project, $supplier, $bomItems] = $this->ensureTestFixture();

        $firstBom = $bomItems[0];

        $receipt = Receipt::create([
            'project_id' => $project->id,
            'supplier_id' => $supplier->id,
            'delivery_note_number' => 'DN-BLK-001',
            'received_by' => $user->id,
        ]);

        $receiptItem = ReceiptItem::create([
            'receipt_id' => $receipt->id,
            'bom_item_id' => $firstBom->id,
            'received_quantity' => 12,
            'side' => 'COMMON',
            'status' => 'received',
        ]);

        // 1. Total Parts Received Block
        $recRes = $this->actingAs($user, 'sanctum')->getJson("/api/v1/dashboard/block-details?block=total_parts_received&project_id={$project->id}");
        $recRes->assertStatus(200);
        $this->assertEquals(12, $recRes->json('total_quantity'));
        $this->assertEquals(1, $recRes->json('total_records'));

        // 2. Store Block
        $storeRes = $this->actingAs($user, 'sanctum')->getJson("/api/v1/dashboard/block-details?block=store&project_id={$project->id}");
        $storeRes->assertStatus(200);
        $this->assertEquals(12, $storeRes->json('total_quantity'));
        $this->assertEquals(1, $storeRes->json('total_records'));

        // Clean up
        $receiptItem->delete();
        $receipt->delete();
    }

    public function test_empty_state_blocks_return_zero_records_safely()
    {
        $user = $this->getAdminUser();
        [$project] = $this->ensureTestFixture();

        // Rework, Paint, Assembly currently have 0 records in fresh fixture
        foreach (['rework', 'paint', 'assembly'] as $blk) {
            $res = $this->actingAs($user, 'sanctum')->getJson("/api/v1/dashboard/block-details?block={$blk}&project_id={$project->id}");
            $res->assertStatus(200);
            $this->assertEquals(0, $res->json('total_quantity'));
            $this->assertEquals(0, $res->json('total_records'));
            $this->assertEmpty($res->json('items'));
        }
    }

    public function test_total_parts_generates_correct_part_number_and_separates_sides()
    {
        $user = $this->getAdminUser();
        $project = Project::firstOrCreate(
            ['project_code' => 'TEST-SEP-01'],
            ['name' => 'Side Separation Project', 'status' => 'active']
        );

        $bom = BomItem::firstOrCreate(
            [
                'project_id' => $project->id,
                'jig_no' => '169961@',
                'unit_no' => '00',
                'standard_part_no' => '020#R00',
            ],
            [
                'item_no' => '020',
                'side' => 'BOTH',
                'total_required' => 2,
                'total_received' => 0,
                'total_pending' => 2,
            ]
        );

        // LH requirement with qty 1
        BomRequirement::firstOrCreate(
            ['bom_item_id' => $bom->id, 'side' => 'LH'],
            ['required_quantity' => 1, 'received_quantity' => 0, 'pending_quantity' => 1]
        );

        // RH requirement with qty 1
        BomRequirement::firstOrCreate(
            ['bom_item_id' => $bom->id, 'side' => 'RH'],
            ['required_quantity' => 1, 'received_quantity' => 0, 'pending_quantity' => 1]
        );

        $res = $this->actingAs($user, 'sanctum')->getJson("/api/v1/dashboard/block-details?block=total_parts&project_id={$project->id}");
        $res->assertStatus(200);

        $items = $res->json('items');
        $this->assertCount(2, $items);

        // Original Quantity = Total Represented Quantity (1 + 1 = 2, NOT doubled to 4)
        $this->assertEquals(2, $res->json('total_quantity'));

        $lhItem = collect($items)->firstWhere('side', 'LH');
        $rhItem = collect($items)->firstWhere('side', 'RH');

        $this->assertNotNull($lhItem);
        $this->assertNotNull($rhItem);

        // Generated Part Number formula: Jig + Unit + PartNo + Side
        $this->assertEquals('169961@00020#R00LH', $lhItem['part_number']);
        $this->assertEquals('169961@00020#R00RH', $rhItem['part_number']);

        $this->assertEquals(1, $lhItem['quantity']);
        $this->assertEquals(1, $rhItem['quantity']);
    }
}
