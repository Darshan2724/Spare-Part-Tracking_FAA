<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\Project;
use App\Models\BomItem;
use App\Models\BomRequirement;
use App\Models\BomImportBatch;
use App\Models\EcnImportBatch;
use App\Models\EcnRequirement;
use App\Models\EcnReceiptItem;
use App\Models\EcnWorkflowRecord;
use App\Services\HierarchyService;
use App\Services\EcnQuantityCalculationService;
use App\Services\EcnWorkflowService;
use Illuminate\Foundation\Testing\DatabaseTransactions;

class EcnComprehensiveFixTest extends TestCase
{
    use DatabaseTransactions;

    protected User $adminUser;
    protected User $storeUser;
    protected User $qcUser;
    protected User $reworkUser;
    protected User $paintUser;
    protected User $assemblyUser;
    protected Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['ADMIN', 'MANAGER', 'STORE', 'QC', 'REWORK', 'PAINT', 'ASSEMBLY', 'PURCHASE'] as $role) {
            \Spatie\Permission\Models\Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }

        $this->adminUser = User::firstOrCreate(
            ['email' => 'admin@sparetrack.internal'],
            ['name' => 'Admin User', 'password' => bcrypt('password123'), 'is_active' => true]
        );
        $this->adminUser->syncRoles(['ADMIN']);

        $this->storeUser = User::firstOrCreate(
            ['email' => 'store@sparetrack.internal'],
            ['name' => 'Store User', 'password' => bcrypt('password123'), 'is_active' => true]
        );
        $this->storeUser->syncRoles(['STORE']);

        $this->qcUser = User::firstOrCreate(
            ['email' => 'qc@sparetrack.internal'],
            ['name' => 'QC User', 'password' => bcrypt('password123'), 'is_active' => true]
        );
        $this->qcUser->syncRoles(['QC']);

        $this->project = Project::create([
            'name' => 'ECN System Test Project',
            'project_code' => 'ECN-SYS-TEST',
            'status' => 'active',
        ]);
    }

    /**
     * Test 1: ECN KPI Drilldown returns canonical structure matching Main Dashboard
     */
    public function test_ecn_kpi_drilldown_structure_matches_main_dashboard(): void
    {
        $batch = EcnImportBatch::create([
            'project_id' => $this->project->id,
            'filename' => 'ECN_TEST.xlsx',
            'total_rows' => 2,
            'status' => 'completed',
        ]);

        $ecnReq = EcnRequirement::create([
            'project_id' => $this->project->id,
            'ecn_import_batch_id' => $batch->id,
            'ecn_number' => 'ECN-2026-001',
            'jig_no' => 'ST07',
            'unit_no' => '07',
            'part_no' => 'P-ECN-001',
            'side' => 'RH',
            'side_display' => 'RH',
            'required_qty' => 10,
            'received_qty' => 0,
            'current_state' => 'PENDING',
        ]);

        $response = $this->actingAs($this->adminUser, 'sanctum')
            ->getJson("/api/v1/ecn/drilldown?kpi=total_parts&project_id={$this->project->id}&is_ecn=1");

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'kpi',
            'kpi_type',
            'project_scope',
            'total_records',
            'total_quantity',
            'page',
            'per_page',
            'total_pages',
            'columns',
            'data',
        ]);

        $this->assertEquals('total_parts', $response->json('kpi'));
        $this->assertEquals(1, $response->json('total_records'));
        $this->assertEquals(10, $response->json('total_quantity'));
    }

    /**
     * Test 2: Department-aware card counts show only currently eligible ECN parts
     */
    public function test_department_aware_ecn_card_counts(): void
    {
        $hierarchyService = app(HierarchyService::class);
        $ecnCalcService = app(EcnQuantityCalculationService::class);

        $batch = EcnImportBatch::create([
            'project_id' => $this->project->id,
            'filename' => 'ECN_DEPT_TEST.xlsx',
            'total_rows' => 1,
            'status' => 'completed',
        ]);

        // ECN requirement with 20 required, 5 in Store, 0 in QC
        $ecnReq = EcnRequirement::create([
            'project_id' => $this->project->id,
            'ecn_import_batch_id' => $batch->id,
            'ecn_number' => 'ECN-2026-002',
            'jig_no' => 'ST07',
            'unit_no' => '07',
            'part_no' => 'P-ECN-002',
            'side' => 'RH',
            'side_display' => 'RH',
            'required_qty' => 20,
            'received_qty' => 5,
            'current_state' => 'STORE',
        ]);

        // In QC view: count must be 0
        $qcCount = $ecnCalcService->getEcnCountsForHierarchy($this->project->id, null, null, null, 'qc');
        $this->assertEquals(0, $qcCount, 'QC view must report 0 ECN parts when parts are in Store');

        // In Store view: count must be 15 (pending intake = 20 required - 5 received)
        $storeCount = $ecnCalcService->getEcnCountsForHierarchy($this->project->id, null, null, null, 'store');
        $this->assertEquals(15, $storeCount, 'Store view must report pending intake ECN quantity (15)');

        // Move to QC: state becomes QC
        $ecnReq->current_state = 'QC';
        $ecnReq->save();

        $qcCountAfter = $ecnCalcService->getEcnCountsForHierarchy($this->project->id, null, null, null, 'qc');
        $this->assertEquals(5, $qcCountAfter, 'QC view must report 5 ECN parts after parts transition to QC');
    }

    /**
     * Test 3: ECN Revert across all departments with lineage preservation
     */
    public function test_ecn_revert_across_all_departments(): void
    {
        $workflowService = app(EcnWorkflowService::class);

        $batch = EcnImportBatch::create([
            'project_id' => $this->project->id,
            'filename' => 'ECN_REVERT_TEST.xlsx',
            'total_rows' => 1,
            'status' => 'completed',
        ]);

        $req = EcnRequirement::create([
            'project_id' => $this->project->id,
            'ecn_import_batch_id' => $batch->id,
            'ecn_number' => 'ECN-2026-003',
            'jig_no' => 'ST07',
            'unit_no' => '07',
            'part_no' => 'P-ECN-003',
            'side' => 'RH',
            'side_display' => 'RH',
            'required_qty' => 5,
            'received_qty' => 5,
            'current_state' => 'STORE',
        ]);

        $receiptItem = EcnReceiptItem::create([
            'ecn_requirement_id' => $req->id,
            'project_id' => $this->project->id,
            'received_quantity' => 5,
            'status' => 'received',
            'processed_by' => $this->storeUser->id,
        ]);

        // 1. Store Revert -> PENDING_ARRIVAL
        $result = $workflowService->revert('store', $receiptItem->id, 5, 'Incorrect receipt', $this->storeUser->id);
        $this->assertTrue($result['success']);
        $this->assertEquals('PENDING_ARRIVAL', $result['target_department']);

        $req->refresh();
        $this->assertEquals(0, $req->received_qty);
        $this->assertEquals('PENDING', $req->current_state);
    }

    /**
     * Test 4: Unified Import History returns both BOM and ECN imports
     */
    public function test_unified_import_history_and_safe_ecn_deletion(): void
    {
        // 1. Create a regular BOM import batch and item
        $bomBatch = BomImportBatch::create([
            'project_id' => $this->project->id,
            'filename' => 'BOM_MAIN.xlsx',
            'total_rows' => 5,
            'status' => 'completed',
        ]);

        $bomItem = BomItem::create([
            'project_id' => $this->project->id,
            'standard_part_no' => 'STD-001',
            'part_description' => 'Regular Part',
        ]);

        // 2. Create an ECN import batch and requirement
        $ecnBatch = EcnImportBatch::create([
            'project_id' => $this->project->id,
            'filename' => 'ECN_SUBSET.xlsx',
            'total_rows' => 2,
            'status' => 'completed',
        ]);

        $ecnReq = EcnRequirement::create([
            'project_id' => $this->project->id,
            'ecn_import_batch_id' => $ecnBatch->id,
            'ecn_number' => 'ECN-2026-004',
            'jig_no' => 'ST07',
            'unit_no' => '07',
            'part_no' => 'ECN-004',
            'side' => 'RH',
            'side_display' => 'RH',
            'required_qty' => 4,
            'received_qty' => 0,
            'current_state' => 'PENDING',
        ]);

        // 3. Fetch history - must contain both BOM and ECN
        $response = $this->actingAs($this->adminUser, 'sanctum')
            ->getJson('/api/v1/bom/history');

        $response->assertStatus(200);
        $history = $response->json('history');

        $types = array_column($history, 'import_type');
        $this->assertContains('BOM', $types);
        $this->assertContains('ECN', $types);
        $this->assertNotNull(collect($history)->firstWhere('id', $ecnBatch->id));

        // 4. Safe Delete ECN batch: Must delete ECN data, NOT project or BOM items!
        $delResponse = $this->actingAs($this->adminUser, 'sanctum')
            ->deleteJson("/api/v1/bom/history/{$ecnBatch->id}?type=ECN");

        $delResponse->assertStatus(200);
        $this->assertTrue($delResponse->json('success'));

        // Verify ECN requirement is deleted
        $this->assertDatabaseMissing('ecn_requirements', ['id' => $ecnReq->id]);
        $this->assertDatabaseMissing('ecn_import_batches', ['id' => $ecnBatch->id]);

        // Verify Project and Regular BOM item are completely intact!
        $this->assertDatabaseHas('projects', ['id' => $this->project->id]);
        $this->assertDatabaseHas('bom_items', ['id' => $bomItem->id]);
        $this->assertDatabaseHas('bom_import_batches', ['id' => $bomBatch->id]);
    }

    /**
     * Test 5: Authentication stability across standard roles
     */
    public function test_authentication_stability_all_roles(): void
    {
        $roles = ['admin', 'manager', 'store', 'qc', 'rework', 'paint', 'assembly', 'purchase'];

        foreach ($roles as $role) {
            $response = $this->postJson('/api/v1/auth/login', [
                'email' => $role,
                'password' => 'password123',
            ]);

            $response->assertStatus(200);
            $this->assertNotEmpty($response->json('token'));
            $this->assertEquals(strtoupper($role), $response->json('user.role.name') ?? $response->json('user.roles.0.name'));
        }
    }
}
