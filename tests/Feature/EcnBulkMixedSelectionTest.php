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
use App\Services\EcnBulkSplitService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class EcnBulkMixedSelectionTest extends TestCase
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

    public function test_mixed_selection_summary_counts()
    {
        $splitService = new EcnBulkSplitService();

        $selectedItems = [
            ['id' => 1, 'is_ecn' => false, 'quantity' => 2],
            ['id' => 2, 'is_ecn' => false, 'quantity' => 5],
            ['id' => 10, 'is_ecn' => true, 'quantity' => 1],
            ['id' => 11, 'is_ecn' => true, 'quantity' => 3],
        ];

        $summary = $splitService->buildSplitSummary($selectedItems);

        $this->assertEquals(4, $summary['total_items']);
        $this->assertEquals(2, $summary['regular']['count']);
        $this->assertEquals(7, $summary['regular']['quantity']);
        $this->assertEquals(2, $summary['ecn']['count']);
        $this->assertEquals(4, $summary['ecn']['quantity']);
        $this->assertStringContainsString('Regular: 2 parts • 7 pcs', $summary['summary_text']);
        $this->assertStringContainsString('ECN: 2 parts • 4 pcs', $summary['summary_text']);
    }

    public function test_mixed_bulk_intake_partitions_and_processes_cleanly()
    {
        $user = $this->getAdminUser();
        $this->actingAs($user, 'sanctum');

        $code = 'MIX-' . uniqid();
        $project = Project::create([
            'project_code' => $code,
            'name' => "Mixed Project {$code}",
            'status' => 'active',
        ]);

        $receipt = Receipt::create([
            'project_id' => $project->id,
            'receipt_number' => 'REC-' . uniqid(),
            'received_by' => $user->id,
            'received_at' => now(),
        ]);

        // Regular BOM item
        $bomItem = BomItem::create([
            'project_id' => $project->id,
            'standard_part_no' => 'REG-101',
            'item_no' => 'R101',
            'jig_no' => 'JIG-1',
            'unit_no' => '01',
        ]);
        BomRequirement::create([
            'bom_item_id' => $bomItem->id,
            'side' => 'LH',
            'required_quantity' => 10,
        ]);

        // ECN Item
        $ecnReq = EcnRequirement::create([
            'project_id' => $project->id,
            'ecn_number' => 'ECN-MIX',
            'jig_no' => 'JIG-1',
            'unit_no' => '01',
            'part_no' => 'ECN-202',
            'side' => 'LA',
            'side_display' => 'LH',
            'side_family' => 'LEFT',
            'required_qty' => 5,
            'received_qty' => 0,
            'current_state' => 'PENDING',
        ]);

        $items = [
            [
                'bom_item_id' => $bomItem->id,
                'receipt_id' => $receipt->id,
                'side' => 'LH',
                'quantity' => 3,
                'is_ecn' => false,
            ],
            [
                'ecn_requirement_id' => $ecnReq->id,
                'quantity' => 2,
                'is_ecn' => true,
            ],
        ];

        $res = $this->postJson('/api/v1/ecn/mixed-bulk-intake', [
            'items' => $items,
        ]);

        $res->assertStatus(200);
        $this->assertTrue($res->json('success'));
        $this->assertEquals(1, $res->json('regular_processed'));
        $this->assertEquals(1, $res->json('ecn_processed'));
        $this->assertEquals(2, $res->json('total_processed'));

        // Verify Regular receipt item was created
        $this->assertDatabaseHas('receipt_items', [
            'bom_item_id' => $bomItem->id,
            'received_quantity' => 3,
            'status' => 'received',
        ]);

        // Verify ECN receipt item and updated requirement
        $ecnReq->refresh();
        $this->assertEquals(2, $ecnReq->received_qty);
        $this->assertEquals('STORE', $ecnReq->current_state);

        $this->assertDatabaseHas('ecn_receipt_items', [
            'ecn_requirement_id' => $ecnReq->id,
            'received_quantity' => 2,
            'status' => 'received',
        ]);
    }
}
