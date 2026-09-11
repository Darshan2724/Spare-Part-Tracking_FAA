<?php

namespace Tests\Feature;

use App\Models\BomItem;
use App\Models\BomRequirement;
use App\Models\Project;
use App\Models\Receipt;
use App\Models\ReceiptItem;
use App\Models\QcInspection;
use App\Models\ReworkRecord;
use App\Models\PaintRecord;
use App\Models\AssemblyRecord;
use App\Models\PurchaseQueueItem;
use App\Models\EcnRequirement;
use App\Models\EcnReceiptItem;
use App\Models\SystemLog;
use App\Models\User;
use App\Services\QuantityCalculationService;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class PendingPartDeletionTest extends TestCase
{
    protected function getAdminUser(): User
    {
        $role = Role::firstOrCreate(['name' => 'ADMIN', 'guard_name' => 'web']);
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

    protected function getManagerUser(): User
    {
        $role = Role::firstOrCreate(['name' => 'MANAGER', 'guard_name' => 'web']);
        $user = User::where('email', 'manager_test@sparetrack.internal')->first();
        if (!$user) {
            $user = User::create([
                'name' => 'Manager Test',
                'email' => 'manager_test@sparetrack.internal',
                'password' => bcrypt('password'),
            ]);
        }
        if (!$user->hasRole('MANAGER')) {
            $user->assignRole($role);
        }
        return $user;
    }

    protected function getStoreUser(): User
    {
        $role = Role::firstOrCreate(['name' => 'STORE', 'guard_name' => 'web']);
        $user = User::where('email', 'store_test@sparetrack.internal')->first();
        if (!$user) {
            $user = User::create([
                'name' => 'Store Test',
                'email' => 'store_test@sparetrack.internal',
                'password' => bcrypt('password'),
            ]);
        }
        if (!$user->hasRole('STORE')) {
            $user->assignRole($role);
        }
        return $user;
    }

    protected function createTestProject(string $prefix = 'TEST_DEL'): Project
    {
        return Project::create([
            'project_code'  => $prefix . '_' . uniqid(),
            'name'          => 'Test Deletion Project ' . uniqid(),
            'customer_name' => 'Test Customer',
            'status'        => 'active',
            'created_by'    => $this->getAdminUser()->id,
        ]);
    }

    public function test_unauthenticated_requests_are_rejected(): void
    {
        $this->getJson('/api/v1/pending-parts/projects')->assertStatus(401);
        $this->getJson('/api/v1/pending-parts/jigs?project_id=1&bom_type=MFG')->assertStatus(401);
        $this->getJson('/api/v1/pending-parts/units?project_id=1&bom_type=MFG&jig_no=J1')->assertStatus(401);
        $this->getJson('/api/v1/pending-parts/sides?project_id=1&bom_type=MFG&jig_no=J1&unit_no=U1')->assertStatus(401);
        $this->getJson('/api/v1/pending-parts/eligible?project_id=1&bom_type=MFG')->assertStatus(401);
        $this->deleteJson('/api/v1/pending-parts/1', ['bom_type' => 'MFG'])->assertStatus(401);
    }

    public function test_unauthorized_store_role_is_rejected_with_403(): void
    {
        $storeUser = $this->getStoreUser();
        $this->actingAs($storeUser, 'sanctum');

        $this->getJson('/api/v1/pending-parts/projects')->assertStatus(403);
        $this->deleteJson('/api/v1/pending-parts/1', ['bom_type' => 'MFG'])->assertStatus(403);
    }

    public function test_admin_and_manager_are_authorized(): void
    {
        $admin = $this->getAdminUser();
        $this->actingAs($admin, 'sanctum');
        $this->getJson('/api/v1/pending-parts/projects')->assertStatus(200);

        $manager = $this->getManagerUser();
        $this->actingAs($manager, 'sanctum');
        $this->getJson('/api/v1/pending-parts/projects')->assertStatus(200);
    }

    public function test_progressive_filters_for_regular_bom(): void
    {
        $admin = $this->getAdminUser();
        $this->actingAs($admin, 'sanctum');

        $project = $this->createTestProject('PROG_FILT');

        $item1 = BomItem::create([
            'project_id'       => $project->id,
            'jig_no'           => 'JIG-ALPHA',
            'unit_no'          => 'UNIT-01',
            'part_type'        => 'MFG',
            'item_no'          => 'ITEM-1',
            'standard_part_no' => 'PART-A1',
        ]);
        BomRequirement::create([
            'bom_item_id'       => $item1->id,
            'side'              => 'LH',
            'required_quantity' => 2,
        ]);

        $item2 = BomItem::create([
            'project_id'       => $project->id,
            'jig_no'           => 'JIG-BETA',
            'unit_no'          => 'UNIT-02',
            'part_type'        => 'MFG',
            'item_no'          => 'ITEM-2',
            'standard_part_no' => 'PART-B2',
        ]);
        BomRequirement::create([
            'bom_item_id'       => $item2->id,
            'side'              => 'RH',
            'required_quantity' => 3,
        ]);

        // 1. Projects
        $projResp = $this->getJson('/api/v1/pending-parts/projects');
        $projResp->assertStatus(200);
        $this->assertTrue(collect($projResp->json('projects'))->pluck('id')->contains($project->id));

        // 2. Jigs
        $jigResp = $this->getJson("/api/v1/pending-parts/jigs?project_id={$project->id}&bom_type=MFG");
        $jigResp->assertStatus(200);
        $jigs = $jigResp->json('jigs');
        $this->assertContains('JIG-ALPHA', $jigs);
        $this->assertContains('JIG-BETA', $jigs);

        // 3. Units
        $unitResp = $this->getJson("/api/v1/pending-parts/units?project_id={$project->id}&bom_type=MFG&jig_no=JIG-ALPHA");
        $unitResp->assertStatus(200);
        $this->assertEquals(['UNIT-01'], $unitResp->json('units'));

        // 4. Sides
        $sideResp = $this->getJson("/api/v1/pending-parts/sides?project_id={$project->id}&bom_type=MFG&jig_no=JIG-ALPHA&unit_no=UNIT-01");
        $sideResp->assertStatus(200);
        $this->assertEquals(['LH'], $sideResp->json('sides'));
    }

    public function test_eligible_pending_parts_returns_only_untouched_parts(): void
    {
        $admin = $this->getAdminUser();
        $this->actingAs($admin, 'sanctum');

        $project = $this->createTestProject('ELIG_TEST');

        // Part 1: Untouched pending part
        $item1 = BomItem::create([
            'project_id'       => $project->id,
            'jig_no'           => 'JIG-1',
            'unit_no'          => 'UNIT-1',
            'part_type'        => 'MFG',
            'item_no'          => 'ITM-1',
            'standard_part_no' => 'PART-PENDING-01',
        ]);
        $req1 = BomRequirement::create([
            'bom_item_id'       => $item1->id,
            'side'              => 'LH',
            'required_quantity' => 4,
        ]);

        // Part 2: Received part (should be excluded)
        $item2 = BomItem::create([
            'project_id'       => $project->id,
            'jig_no'           => 'JIG-1',
            'unit_no'          => 'UNIT-1',
            'part_type'        => 'MFG',
            'item_no'          => 'ITM-2',
            'standard_part_no' => 'PART-RECEIVED-02',
        ]);
        $req2 = BomRequirement::create([
            'bom_item_id'       => $item2->id,
            'side'              => 'LH',
            'required_quantity' => 2,
        ]);
        $receipt = Receipt::create([
            'project_id'     => $project->id,
            'receipt_number' => 'REC_' . uniqid(),
            'received_by'    => $admin->id,
            'status'         => 'received',
        ]);
        ReceiptItem::create([
            'receipt_id'        => $receipt->id,
            'bom_item_id'       => $item2->id,
            'side'              => 'LH',
            'received_quantity' => 2,
            'status'            => 'received',
        ]);

        $response = $this->getJson("/api/v1/pending-parts/eligible?project_id={$project->id}&bom_type=MFG");
        $response->assertStatus(200);
        $parts = $response->json('parts');

        $this->assertCount(1, $parts);
        $this->assertEquals($req1->id, $parts[0]['id']);
        $this->assertEquals('PART-PENDING-01', $parts[0]['standard_part_no']);
        $this->assertEquals(4, $parts[0]['required_quantity']);
    }

    public function test_partial_quantity_deletion_regular_bom(): void
    {
        $admin = $this->getAdminUser();
        $this->actingAs($admin, 'sanctum');

        $project = $this->createTestProject('PARTIAL_DEL');

        $item = BomItem::create([
            'project_id'       => $project->id,
            'jig_no'           => 'JIG-PARTIAL',
            'unit_no'          => 'U1',
            'part_type'        => 'MFG',
            'item_no'          => 'P1',
            'standard_part_no' => 'PART-MULTI-QTY',
        ]);
        $req = BomRequirement::create([
            'bom_item_id'       => $item->id,
            'side'              => 'COMMON',
            'required_quantity' => 2,
        ]);

        // Calculate initial project metrics via QuantityCalculationService
        $qtyService = app(QuantityCalculationService::class);
        $initialMetrics = $qtyService->calculateProjectMetrics($project);
        $this->assertEquals(2, $initialMetrics['total_required']);
        $this->assertEquals(2, $initialMetrics['total_pending']);

        // Delete 1 out of 2
        $response = $this->deleteJson("/api/v1/pending-parts/{$req->id}", [
            'bom_type' => 'MFG',
            'quantity' => 1,
            'reason'   => 'Operator entered wrong duplicate part count',
        ]);

        $response->assertStatus(200)
                 ->assertJson([
                     'success'            => true,
                     'action'             => 'decremented',
                     'deleted_quantity'   => 1,
                     'remaining_quantity' => 1,
                 ]);

        // Verify DB state
        $req->refresh();
        $this->assertEquals(1, $req->required_quantity);
        $this->assertDatabaseHas('bom_items', ['id' => $item->id]);

        // Verify QuantityCalculationService reflects the decrement immediately across the system
        $updatedMetrics = $qtyService->calculateProjectMetrics($project);
        $this->assertEquals(1, $updatedMetrics['total_required']);
        $this->assertEquals(1, $updatedMetrics['total_pending']);

        // Now delete the remaining 1
        $response2 = $this->deleteJson("/api/v1/pending-parts/{$req->id}", [
            'bom_type' => 'MFG',
            'quantity' => 1,
        ]);

        $response2->assertStatus(200)
                  ->assertJson([
                      'success'            => true,
                      'action'             => 'deleted',
                      'deleted_quantity'   => 1,
                      'remaining_quantity' => 0,
                  ]);

        $this->assertDatabaseMissing('bom_requirements', ['id' => $req->id]);
        $this->assertDatabaseMissing('bom_items', ['id' => $item->id]);

        $finalMetrics = $qtyService->calculateProjectMetrics($project);
        $this->assertEquals(0, $finalMetrics['total_required']);
        $this->assertEquals(0, $finalMetrics['total_pending']);
    }

    public function test_multi_side_isolation_during_deletion(): void
    {
        $admin = $this->getAdminUser();
        $this->actingAs($admin, 'sanctum');

        $project = $this->createTestProject('SIDE_ISO');

        $item = BomItem::create([
            'project_id'       => $project->id,
            'jig_no'           => 'JIG-ISO',
            'unit_no'          => 'U1',
            'part_type'        => 'BOP',
            'item_no'          => 'BOP-1',
            'standard_part_no' => 'BOP-MULTI-SIDE',
        ]);
        $reqLH = BomRequirement::create([
            'bom_item_id'       => $item->id,
            'side'              => 'LH',
            'required_quantity' => 2,
        ]);
        $reqRH = BomRequirement::create([
            'bom_item_id'       => $item->id,
            'side'              => 'RH',
            'required_quantity' => 3,
        ]);

        // Delete all of LH
        $response = $this->deleteJson("/api/v1/pending-parts/{$reqLH->id}", [
            'bom_type' => 'BOP',
            'quantity' => 2,
        ]);

        $response->assertStatus(200)
                 ->assertJson(['success' => true, 'action' => 'deleted']);

        $this->assertDatabaseMissing('bom_requirements', ['id' => $reqLH->id]);
        $this->assertDatabaseHas('bom_requirements', ['id' => $reqRH->id, 'required_quantity' => 3]);
        // BomItem MUST remain because RH requirement is still active
        $this->assertDatabaseHas('bom_items', ['id' => $item->id]);
    }

    public function test_cannot_delete_exceeding_quantity(): void
    {
        $admin = $this->getAdminUser();
        $this->actingAs($admin, 'sanctum');

        $project = $this->createTestProject('EXCEED_QTY');

        $item = BomItem::create([
            'project_id'       => $project->id,
            'jig_no'           => 'J1',
            'unit_no'          => 'U1',
            'part_type'        => 'STD',
            'item_no'          => 'STD-1',
            'standard_part_no' => 'BOLT-M8',
        ]);
        $req = BomRequirement::create([
            'bom_item_id'       => $item->id,
            'side'              => 'COMMON',
            'required_quantity' => 2,
        ]);

        // Requesting 3 when only 2 exist
        $response = $this->deleteJson("/api/v1/pending-parts/{$req->id}", [
            'bom_type' => 'STD',
            'quantity' => 3,
        ]);

        $response->assertStatus(422)
                 ->assertJsonStructure(['success', 'message']);
        $this->assertStringContainsString('exceeds', $response->json('message'));
    }

    public function test_cannot_delete_already_received_part(): void
    {
        $admin = $this->getAdminUser();
        $this->actingAs($admin, 'sanctum');

        $project = $this->createTestProject('ALREADY_REC');

        $item = BomItem::create([
            'project_id'       => $project->id,
            'jig_no'           => 'J1',
            'unit_no'          => 'U1',
            'part_type'        => 'MFG',
            'item_no'          => 'M1',
            'standard_part_no' => 'PART-WITH-RECEIPT',
        ]);
        $req = BomRequirement::create([
            'bom_item_id'       => $item->id,
            'side'              => 'LH',
            'required_quantity' => 1,
        ]);

        $receipt = Receipt::create([
            'project_id'     => $project->id,
            'receipt_number' => 'REC_' . uniqid(),
            'received_by'    => $admin->id,
            'status'         => 'received',
        ]);
        ReceiptItem::create([
            'receipt_id'        => $receipt->id,
            'bom_item_id'       => $item->id,
            'side'              => 'LH',
            'received_quantity' => 1,
            'status'            => 'received',
        ]);

        $response = $this->deleteJson("/api/v1/pending-parts/{$req->id}", [
            'bom_type' => 'MFG',
            'quantity' => 1,
        ]);

        $response->assertStatus(422);
        $this->assertStringContainsString('receipt records already exist', $response->json('message'));
    }

    public function test_ecn_partial_and_full_deletion(): void
    {
        $admin = $this->getAdminUser();
        $this->actingAs($admin, 'sanctum');

        $project = $this->createTestProject('ECN_DEL');

        $ecnReq = EcnRequirement::create([
            'project_id'    => $project->id,
            'ecn_number'    => 'ECN-TEST-001',
            'jig_no'        => 'JIG-ECN',
            'unit_no'       => 'UNIT-ECN',
            'part_no'       => 'ECN-PART-1',
            'side'          => 'LH',
            'side_display'  => 'LH',
            'side_family'   => 'LEFT',
            'required_qty'  => 3,
            'received_qty'  => 0,
            'current_state' => 'PENDING',
        ]);

        // Test progressive filters for ECN
        $jigResp = $this->getJson("/api/v1/pending-parts/jigs?project_id={$project->id}&bom_type=ECN");
        $jigResp->assertStatus(200);
        $this->assertContains('JIG-ECN', $jigResp->json('jigs'));

        $unitResp = $this->getJson("/api/v1/pending-parts/units?project_id={$project->id}&bom_type=ECN&jig_no=JIG-ECN");
        $unitResp->assertStatus(200);
        $this->assertEquals(['UNIT-ECN'], $unitResp->json('units'));

        $sideResp = $this->getJson("/api/v1/pending-parts/sides?project_id={$project->id}&bom_type=ECN&jig_no=JIG-ECN&unit_no=UNIT-ECN");
        $sideResp->assertStatus(200);
        $this->assertEquals(['LH'], $sideResp->json('sides'));

        // Test eligibility
        $eligResp = $this->getJson("/api/v1/pending-parts/eligible?project_id={$project->id}&bom_type=ECN");
        $eligResp->assertStatus(200);
        $this->assertCount(1, $eligResp->json('parts'));
        $this->assertEquals('ECN-PART-1', $eligResp->json('parts.0.item_no'));

        // Partial delete 1 of 3
        $delResp = $this->deleteJson("/api/v1/pending-parts/{$ecnReq->id}", [
            'bom_type' => 'ECN',
            'quantity' => 1,
            'reason'   => 'ECN count revision',
        ]);

        $delResp->assertStatus(200)
                ->assertJson([
                    'success'            => true,
                    'action'             => 'decremented',
                    'deleted_quantity'   => 1,
                    'remaining_quantity' => 2,
                ]);

        $ecnReq->refresh();
        $this->assertEquals(2, $ecnReq->required_qty);

        // Full delete remaining 2
        $delResp2 = $this->deleteJson("/api/v1/pending-parts/{$ecnReq->id}", [
            'bom_type' => 'ECN',
            'quantity' => 2,
        ]);

        $delResp2->assertStatus(200)
                 ->assertJson([
                     'success'            => true,
                     'action'             => 'deleted',
                     'deleted_quantity'   => 2,
                     'remaining_quantity' => 0,
                 ]);

        $this->assertDatabaseMissing('ecn_requirements', ['id' => $ecnReq->id]);
    }

    public function test_ecn_cannot_delete_non_pending_state(): void
    {
        $admin = $this->getAdminUser();
        $this->actingAs($admin, 'sanctum');

        $project = $this->createTestProject('ECN_NON_PEND');

        $ecnReq = EcnRequirement::create([
            'project_id'    => $project->id,
            'ecn_number'    => 'ECN-STORE-002',
            'jig_no'        => 'J1',
            'unit_no'       => 'U1',
            'part_no'       => 'ECN-STORE-PART',
            'side'          => 'LH',
            'side_display'  => 'LH',
            'side_family'   => 'LEFT',
            'required_qty'  => 1,
            'received_qty'  => 1,
            'current_state' => 'STORE',
        ]);

        $response = $this->deleteJson("/api/v1/pending-parts/{$ecnReq->id}", [
            'bom_type' => 'ECN',
            'quantity' => 1,
        ]);

        $response->assertStatus(422);
        $this->assertStringContainsString('STORE', $response->json('message'));
    }

    public function test_audit_log_created_on_deletion(): void
    {
        $admin = $this->getAdminUser();
        $this->actingAs($admin, 'sanctum');

        $project = $this->createTestProject('AUDIT_TEST');

        $item = BomItem::create([
            'project_id'       => $project->id,
            'jig_no'           => 'J-AUDIT',
            'unit_no'          => 'U-AUDIT',
            'part_type'        => 'MFG',
            'item_no'          => 'AUDIT-1',
            'standard_part_no' => 'PART-AUDIT-LOG',
        ]);
        $req = BomRequirement::create([
            'bom_item_id'       => $item->id,
            'side'              => 'LH',
            'required_quantity' => 1,
        ]);

        $this->deleteJson("/api/v1/pending-parts/{$req->id}", [
            'bom_type' => 'MFG',
            'quantity' => 1,
            'reason'   => 'Audit test verification reason',
        ]);

        $log = SystemLog::where('module', 'PENDING_PART_DELETION')
            ->where('details->requirement_id', $req->id)
            ->latest('id')
            ->first();

        $this->assertNotNull($log);
        $this->assertEquals('WARNING', $log->severity);
        $this->assertEquals($admin->id, $log->user_id);
        $this->assertStringContainsString('PART-AUDIT-LOG', $log->message);
        $this->assertEquals('Audit test verification reason', $log->details['reason']);
    }
}
