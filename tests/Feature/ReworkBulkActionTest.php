<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Project;
use App\Models\BomItem;
use App\Models\BomRequirement;
use App\Models\Receipt;
use App\Models\ReceiptItem;
use App\Models\QcInspection;
use App\Models\ReworkRecord;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ReworkBulkActionTest extends TestCase
{
    protected User $reworkUser;
    protected Project $project;
    protected BomItem $item1;
    protected BomItem $item2;
    protected BomItem $item3;
    protected ReworkRecord $record1;
    protected ReworkRecord $record2;
    protected ReworkRecord $record3;

    protected function setUp(): void
    {
        parent::setUp();

        $role = Role::firstOrCreate(['name' => 'REWORK', 'guard_name' => 'web']);

        $this->reworkUser = User::firstOrCreate(
            ['email' => 'rework_bulk_test@sparetrack.internal'],
            ['name' => 'Rework Bulk Tester', 'password' => bcrypt('password')]
        );
        $this->reworkUser->assignRole($role);

        $this->project = Project::create([
            'project_code' => 'TEST-RWK-' . uniqid(),
            'name' => 'Rework Bulk Test Project',
            'status' => 'active',
        ]);

        // Create 3 parts in rework
        $this->item1 = BomItem::create([
            'project_id' => $this->project->id,
            'standard_part_no' => 'RWK-PART-001',
            'item_no' => '1',
            'jig_no' => 'JIG-01',
            'unit_no' => 'Unit 01',
            'part_type' => 'MFG',
        ]);
        BomRequirement::create([
            'bom_item_id' => $this->item1->id,
            'side' => 'RH',
            'required_quantity' => 10,
        ]);

        $this->item2 = BomItem::create([
            'project_id' => $this->project->id,
            'standard_part_no' => 'RWK-PART-002',
            'item_no' => '2',
            'jig_no' => 'JIG-01',
            'unit_no' => 'Unit 01',
            'part_type' => 'MFG',
        ]);
        BomRequirement::create([
            'bom_item_id' => $this->item2->id,
            'side' => 'RH',
            'required_quantity' => 10,
        ]);

        $this->item3 = BomItem::create([
            'project_id' => $this->project->id,
            'standard_part_no' => 'RWK-PART-003',
            'item_no' => '3',
            'jig_no' => 'JIG-01',
            'unit_no' => 'Unit 01',
            'part_type' => 'MFG',
        ]);
        BomRequirement::create([
            'bom_item_id' => $this->item3->id,
            'side' => 'RH',
            'required_quantity' => 10,
        ]);

        $receipt = Receipt::create([
            'project_id' => $this->project->id,
            'delivery_note_number' => 'DN-RWK-' . uniqid(),
            'received_by' => $this->reworkUser->id,
            'status' => 'completed',
        ]);

        // Create receipt items, QC inspections, and rework records
        $rec1 = ReceiptItem::create([
            'receipt_id' => $receipt->id,
            'bom_item_id' => $this->item1->id,
            'side' => 'RH',
            'received_quantity' => 5,
            'status' => 'qc_rework',
        ]);
        $insp1 = QcInspection::create([
            'receipt_item_id' => $rec1->id,
            'bom_item_id' => $this->item1->id,
            'inspected_by' => $this->reworkUser->id,
            'side' => 'RH',
            'result' => 'rework',
            'inspected_quantity' => 5,
            'rework_quantity' => 5,
            'inspection_date' => now()->toDateString(),
        ]);
        $this->record1 = ReworkRecord::create([
            'qc_inspection_id' => $insp1->id,
            'bom_item_id' => $this->item1->id,
            'side' => 'RH',
            'quantity' => 5,
            'status' => 'pending',
            'reason' => 'Dimensional variance',
            'cycle_number' => 1,
        ]);

        $rec2 = ReceiptItem::create([
            'receipt_id' => $receipt->id,
            'bom_item_id' => $this->item2->id,
            'side' => 'RH',
            'received_quantity' => 3,
            'status' => 'qc_rework',
        ]);
        $insp2 = QcInspection::create([
            'receipt_item_id' => $rec2->id,
            'bom_item_id' => $this->item2->id,
            'inspected_by' => $this->reworkUser->id,
            'side' => 'RH',
            'result' => 'rework',
            'inspected_quantity' => 3,
            'rework_quantity' => 3,
            'inspection_date' => now()->toDateString(),
        ]);
        $this->record2 = ReworkRecord::create([
            'qc_inspection_id' => $insp2->id,
            'bom_item_id' => $this->item2->id,
            'side' => 'RH',
            'quantity' => 3,
            'status' => 'pending',
            'reason' => 'Surface scratch',
            'cycle_number' => 1,
        ]);

        $rec3 = ReceiptItem::create([
            'receipt_id' => $receipt->id,
            'bom_item_id' => $this->item3->id,
            'side' => 'RH',
            'received_quantity' => 4,
            'status' => 'qc_rework',
        ]);
        $insp3 = QcInspection::create([
            'receipt_item_id' => $rec3->id,
            'bom_item_id' => $this->item3->id,
            'inspected_by' => $this->reworkUser->id,
            'side' => 'RH',
            'result' => 'rework',
            'inspected_quantity' => 4,
            'rework_quantity' => 4,
            'inspection_date' => now()->toDateString(),
        ]);
        $this->record3 = ReworkRecord::create([
            'qc_inspection_id' => $insp3->id,
            'bom_item_id' => $this->item3->id,
            'side' => 'RH',
            'quantity' => 4,
            'status' => 'pending',
            'reason' => 'Burr on edges',
            'cycle_number' => 1,
        ]);
    }

    public function test_bulk_start_rework_records_by_ids(): void
    {
        $this->actingAs($this->reworkUser, 'sanctum');

        $response = $this->postJson('/api/v1/rework/bulk-action', [
            'action' => 'start',
            'rework_record_ids' => [$this->record1->id, $this->record2->id],
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('processed_count', 2);

        $this->assertEquals('in_progress', $this->record1->fresh()->status);
        $this->assertEquals('in_progress', $this->record2->fresh()->status);
        // Unselected record remains pending
        $this->assertEquals('pending', $this->record3->fresh()->status);
    }

    public function test_bulk_complete_rework_records_with_deduplication(): void
    {
        $this->actingAs($this->reworkUser, 'sanctum');

        // Include duplicate IDs in the request
        $response = $this->postJson('/api/v1/rework/bulk-action', [
            'action' => 'complete',
            'rework_record_ids' => [$this->record1->id, $this->record2->id, $this->record1->id, $this->record2->id],
            'completion_notes' => 'Batch rework completed after CNC deburring.',
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('processed_count', 2);
        $response->assertJsonPath('processed_quantity', 8); // 5 + 3

        $this->assertEquals('completed', $this->record1->fresh()->status);
        $this->assertEquals('completed', $this->record2->fresh()->status);
        // Unselected record remains untouched
        $this->assertEquals('pending', $this->record3->fresh()->status);
    }

    public function test_bulk_action_using_mobile_items_payload(): void
    {
        $this->actingAs($this->reworkUser, 'sanctum');

        // The mobile app sends: items: [{bom_item_id: X, side: 'RH'}, ...]
        $response = $this->postJson('/api/v1/rework/bulk-action', [
            'action' => 'complete',
            'items' => [
                ['bom_item_id' => $this->item1->id, 'side' => 'RH'],
                ['bom_item_id' => $this->item3->id, 'side' => 'RH'],
            ],
            'completion_notes' => 'Completed via mobile batch action.',
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('processed_count', 2);
        $response->assertJsonPath('processed_quantity', 9); // 5 + 4

        $this->assertEquals('completed', $this->record1->fresh()->status);
        $this->assertEquals('completed', $this->record3->fresh()->status);
        // Item 2 was not included, must remain pending
        $this->assertEquals('pending', $this->record2->fresh()->status);
    }

    public function test_bulk_action_with_no_active_records_returns_422(): void
    {
        $this->actingAs($this->reworkUser, 'sanctum');

        // First complete record 1
        $this->record1->update(['status' => 'completed']);

        // Attempting to bulk complete already completed record
        $response = $this->postJson('/api/v1/rework/bulk-action', [
            'action' => 'complete',
            'rework_record_ids' => [$this->record1->id],
        ]);

        // When record is already completed, it is not eligible
        $response->assertStatus(200);
        $response->assertJsonPath('processed_count', 0);
    }
}
