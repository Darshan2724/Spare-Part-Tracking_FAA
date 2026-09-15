<?php

namespace Tests\Feature;

use App\Models\AssemblyAllocation;
use App\Models\AssemblyRecord;
use App\Models\BomItem;
use App\Models\BomRequirement;
use App\Models\PaintRecord;
use App\Models\Project;
use App\Models\QcInspection;
use App\Models\Receipt;
use App\Models\ReceiptItem;
use App\Models\Supplier;
use App\Models\User;
use App\Services\BopIntakeService;
use App\Services\StdIntakeService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AssemblyAllocationTest extends TestCase
{
    use DatabaseTransactions;

    protected User $adminUser;
    protected User $managerUser;
    protected User $assemblyUser;
    protected User $storeUser;
    protected Supplier $supplier;
    protected Project $project;
    protected string $bopPartNo;
    protected string $stdPartNo;
    protected string $mfgPartNo;
    protected BomItem $bopUnit1;
    protected BomItem $bopUnit2;
    protected BomItem $stdUnit1;
    protected BomItem $stdUnit2;
    protected BomItem $mfgItem;

    protected function setUp(): void
    {
        parent::setUp();

        // Roles
        $adminRole = Role::firstOrCreate(['name' => 'ADMIN', 'guard_name' => 'web']);
        $managerRole = Role::firstOrCreate(['name' => 'MANAGER', 'guard_name' => 'web']);
        $assemblyRole = Role::firstOrCreate(['name' => 'ASSEMBLY', 'guard_name' => 'web']);
        $storeRole = Role::firstOrCreate(['name' => 'STORE', 'guard_name' => 'web']);

        // Users
        $this->adminUser = User::create([
            'name' => 'Admin User ' . uniqid(),
            'email' => 'admin_' . uniqid() . '@example.com',
            'password' => bcrypt('secret'),
        ]);
        $this->adminUser->assignRole($adminRole);

        $this->managerUser = User::create([
            'name' => 'Manager User ' . uniqid(),
            'email' => 'manager_' . uniqid() . '@example.com',
            'password' => bcrypt('secret'),
        ]);
        $this->managerUser->assignRole($managerRole);

        $this->assemblyUser = User::create([
            'name' => 'Assembly User ' . uniqid(),
            'email' => 'assembly_' . uniqid() . '@example.com',
            'password' => bcrypt('secret'),
        ]);
        $this->assemblyUser->assignRole($assemblyRole);

        $this->storeUser = User::create([
            'name' => 'Store User ' . uniqid(),
            'email' => 'store_' . uniqid() . '@example.com',
            'password' => bcrypt('secret'),
        ]);
        $this->storeUser->assignRole($storeRole);

        // Project & Supplier
        $this->supplier = Supplier::create([
            'name' => 'Test Supplier ' . uniqid(),
            'code' => 'SUP-' . uniqid(),
            'is_active' => true,
        ]);

        $this->project = Project::create([
            'name' => 'Alloc Test Project ' . uniqid(),
            'project_code' => 'PRJ-ALLOC-' . uniqid(),
            'status' => 'active',
        ]);

        $this->bopPartNo = 'BOP-BEARING-' . uniqid();
        $this->stdPartNo = 'STD-BOLT-M8-' . uniqid();
        $this->mfgPartNo = 'MFG-PLATE-' . uniqid();

        // BOP: Unit 1 (req: 10) & Unit 2 (req: 10)
        $this->bopUnit1 = BomItem::create([
            'project_id' => $this->project->id,
            'standard_part_no' => $this->bopPartNo,
            'part_type' => 'BOP',
            'supplier_id' => $this->supplier->id,
            'jig_no' => 'JIG-01',
            'unit_no' => 'Unit 1',
        ]);
        BomRequirement::create([
            'bom_item_id' => $this->bopUnit1->id,
            'side' => 'COMMON',
            'required_quantity' => 10,
        ]);

        $this->bopUnit2 = BomItem::create([
            'project_id' => $this->project->id,
            'standard_part_no' => $this->bopPartNo,
            'part_type' => 'BOP',
            'supplier_id' => $this->supplier->id,
            'jig_no' => 'JIG-01',
            'unit_no' => 'Unit 2',
        ]);
        BomRequirement::create([
            'bom_item_id' => $this->bopUnit2->id,
            'side' => 'COMMON',
            'required_quantity' => 10,
        ]);

        // STD: Unit 1 (req: 8) & Unit 2 (req: 8)
        $this->stdUnit1 = BomItem::create([
            'project_id' => $this->project->id,
            'standard_part_no' => $this->stdPartNo,
            'part_type' => 'STD',
            'supplier_id' => $this->supplier->id,
            'jig_no' => 'JIG-01',
            'unit_no' => 'Unit 1',
        ]);
        BomRequirement::create([
            'bom_item_id' => $this->stdUnit1->id,
            'side' => 'COMMON',
            'required_quantity' => 8,
        ]);

        $this->stdUnit2 = BomItem::create([
            'project_id' => $this->project->id,
            'standard_part_no' => $this->stdPartNo,
            'part_type' => 'STD',
            'supplier_id' => $this->supplier->id,
            'jig_no' => 'JIG-01',
            'unit_no' => 'Unit 2',
        ]);
        BomRequirement::create([
            'bom_item_id' => $this->stdUnit2->id,
            'side' => 'COMMON',
            'required_quantity' => 8,
        ]);

        // MFG Item (to verify exclusion)
        $this->mfgItem = BomItem::create([
            'project_id' => $this->project->id,
            'standard_part_no' => $this->mfgPartNo,
            'part_type' => 'MFG',
            'jig_no' => 'JIG-01',
            'unit_no' => 'Unit 1',
        ]);
        BomRequirement::create([
            'bom_item_id' => $this->mfgItem->id,
            'side' => 'COMMON',
            'required_quantity' => 5,
        ]);
    }

    /**
     * Test role authorization: ADMIN, MANAGER, ASSEMBLY permitted; STORE forbidden; Guest unauthenticated.
     */
    public function test_role_authorization_on_allocation_endpoints(): void
    {
        // 1. Guest -> 401
        $response = $this->getJson('/api/v1/assembly-allocation/context?standard_part_no=' . $this->bopPartNo . '&bom_type=BOP');
        $response->assertStatus(401);

        // 2. STORE user -> 403 Forbidden
        Sanctum::actingAs($this->storeUser);
        $response = $this->getJson('/api/v1/assembly-allocation/context?standard_part_no=' . $this->bopPartNo . '&bom_type=BOP');
        $response->assertStatus(403);

        // 3. ASSEMBLY user -> 200 OK
        Sanctum::actingAs($this->assemblyUser);
        $response = $this->getJson('/api/v1/assembly-allocation/context?standard_part_no=' . $this->bopPartNo . '&bom_type=BOP');
        $response->assertStatus(200);

        // 4. MANAGER user -> 200 OK
        Sanctum::actingAs($this->managerUser);
        $response = $this->getJson('/api/v1/assembly-allocation/context?standard_part_no=' . $this->bopPartNo . '&bom_type=BOP');
        $response->assertStatus(200);

        // 5. ADMIN user -> 200 OK
        Sanctum::actingAs($this->adminUser);
        $response = $this->getJson('/api/v1/assembly-allocation/context?standard_part_no=' . $this->bopPartNo . '&bom_type=BOP');
        $response->assertStatus(200);
    }

    /**
     * Test context endpoint returns correct assembly-ready stock and unit requirements.
     */
    public function test_get_bop_allocation_context(): void
    {
        Sanctum::actingAs($this->managerUser);

        // Receive 10 parts into store, move 6 to in_assembly
        $receipt = Receipt::create([
            'project_id' => $this->project->id,
            'supplier_id' => $this->supplier->id,
            'delivery_note_number' => 'DN-BOP-1',
            'received_by' => $this->adminUser->id,
        ]);
        ReceiptItem::create([
            'receipt_id' => $receipt->id,
            'bom_item_id' => $this->bopUnit1->id,
            'side' => 'COMMON',
            'received_quantity' => 6,
            'status' => 'in_assembly',
        ]);
        ReceiptItem::create([
            'receipt_id' => $receipt->id,
            'bom_item_id' => $this->bopUnit1->id,
            'side' => 'COMMON',
            'received_quantity' => 4,
            'status' => 'received', // still in store
        ]);

        $response = $this->getJson('/api/v1/assembly-allocation/context?standard_part_no=' . $this->bopPartNo . '&bom_type=BOP&project_id=' . $this->project->id);
        $response->assertStatus(200);
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('data.part_summary.total_required', 20);
        $response->assertJsonPath('data.part_summary.total_assembly_ready', 6);
        $response->assertJsonPath('data.part_summary.total_allocated', 0);
        $response->assertJsonPath('data.part_summary.unallocated_assembly_ready', 6);
        $this->assertCount(2, $response->json('data.units'));
    }

    /**
     * Test manager allocates parts to a specific unit successfully.
     */
    public function test_allocate_parts_to_specific_unit(): void
    {
        Sanctum::actingAs($this->managerUser);

        // Put 8 pcs in_assembly
        $receipt = Receipt::create([
            'project_id' => $this->project->id,
            'supplier_id' => $this->supplier->id,
            'delivery_note_number' => 'DN-BOP-2',
            'received_by' => $this->adminUser->id,
        ]);
        ReceiptItem::create([
            'receipt_id' => $receipt->id,
            'bom_item_id' => $this->bopUnit1->id,
            'side' => 'COMMON',
            'received_quantity' => 8,
            'status' => 'in_assembly',
        ]);

        // Allocate 5 pcs to Unit 2 (even though received against Unit 1's BOM item)
        $response = $this->postJson('/api/v1/assembly-allocation/allocate', [
            'bom_item_id' => $this->bopUnit2->id,
            'side' => 'COMMON',
            'quantity' => 5,
            'remarks' => 'Priority for Unit 2 assembly line',
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('data.allocated_quantity', 5);
        $response->assertJsonPath('data.status', 'active');

        $this->assertDatabaseHas('assembly_allocations', [
            'bom_item_id' => $this->bopUnit2->id,
            'side' => 'COMMON',
            'allocated_quantity' => 5,
            'status' => 'active',
            'allocated_by' => $this->managerUser->id,
        ]);

        // Context should now show 5 allocated, 3 unallocated
        $contextRes = $this->getJson('/api/v1/assembly-allocation/context?standard_part_no=' . $this->bopPartNo . '&bom_type=BOP&project_id=' . $this->project->id);
        $contextRes->assertJsonPath('data.part_summary.total_allocated', 5);
        $contextRes->assertJsonPath('data.part_summary.unallocated_assembly_ready', 3);
    }

    /**
     * Test allocation cannot exceed unit's remaining need.
     */
    public function test_cannot_allocate_exceeding_unit_need(): void
    {
        Sanctum::actingAs($this->managerUser);

        // 20 pcs in_assembly
        $receipt = Receipt::create([
            'project_id' => $this->project->id,
            'supplier_id' => $this->supplier->id,
            'delivery_note_number' => 'DN-BOP-3',
            'received_by' => $this->adminUser->id,
        ]);
        ReceiptItem::create([
            'receipt_id' => $receipt->id,
            'bom_item_id' => $this->bopUnit1->id,
            'side' => 'COMMON',
            'received_quantity' => 20,
            'status' => 'in_assembly',
        ]);

        // Unit 1 requires 10 pcs. Attempting to allocate 12 pcs must fail with 422
        $response = $this->postJson('/api/v1/assembly-allocation/allocate', [
            'bom_item_id' => $this->bopUnit1->id,
            'side' => 'COMMON',
            'quantity' => 12,
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('quantity');
    }

    /**
     * Test allocation cannot exceed available assembly ready stock across units.
     */
    public function test_cannot_allocate_exceeding_available_stock(): void
    {
        Sanctum::actingAs($this->managerUser);

        // Only 4 pcs in_assembly
        $receipt = Receipt::create([
            'project_id' => $this->project->id,
            'supplier_id' => $this->supplier->id,
            'delivery_note_number' => 'DN-BOP-4',
            'received_by' => $this->adminUser->id,
        ]);
        ReceiptItem::create([
            'receipt_id' => $receipt->id,
            'bom_item_id' => $this->bopUnit1->id,
            'side' => 'COMMON',
            'received_quantity' => 4,
            'status' => 'in_assembly',
        ]);

        // Allocate 3 pcs to Unit 1 (leaves 1 available)
        $this->postJson('/api/v1/assembly-allocation/allocate', [
            'bom_item_id' => $this->bopUnit1->id,
            'side' => 'COMMON',
            'quantity' => 3,
        ])->assertStatus(200);

        // Attempt to allocate 3 pcs to Unit 2 (only 1 remaining) -> 422
        $response = $this->postJson('/api/v1/assembly-allocation/allocate', [
            'bom_item_id' => $this->bopUnit2->id,
            'side' => 'COMMON',
            'quantity' => 3,
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('quantity');
    }

    /**
     * Test allocation strictly rejects MFG parts.
     */
    public function test_mfg_parts_cannot_be_allocated(): void
    {
        Sanctum::actingAs($this->managerUser);

        $response = $this->postJson('/api/v1/assembly-allocation/allocate', [
            'bom_item_id' => $this->mfgItem->id,
            'side' => 'COMMON',
            'quantity' => 2,
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('part_type');
    }

    /**
     * Test deallocation releases stock back to the available pool.
     */
    public function test_deallocate_releases_stock(): void
    {
        Sanctum::actingAs($this->managerUser);

        $receipt = Receipt::create([
            'project_id' => $this->project->id,
            'supplier_id' => $this->supplier->id,
            'delivery_note_number' => 'DN-BOP-5',
            'received_by' => $this->adminUser->id,
        ]);
        ReceiptItem::create([
            'receipt_id' => $receipt->id,
            'bom_item_id' => $this->bopUnit1->id,
            'side' => 'COMMON',
            'received_quantity' => 5,
            'status' => 'in_assembly',
        ]);

        // Allocate 5 pcs to Unit 1
        $allocRes = $this->postJson('/api/v1/assembly-allocation/allocate', [
            'bom_item_id' => $this->bopUnit1->id,
            'side' => 'COMMON',
            'quantity' => 5,
        ]);
        $allocId = $allocRes->json('data.id');

        // Pool is now 0 available
        $c1 = $this->getJson('/api/v1/assembly-allocation/context?standard_part_no=' . $this->bopPartNo . '&bom_type=BOP&project_id=' . $this->project->id);
        $c1->assertJsonPath('data.part_summary.unallocated_assembly_ready', 0);

        // Deallocate
        $deallocRes = $this->postJson('/api/v1/assembly-allocation/deallocate', [
            'allocation_id' => $allocId,
            'remarks' => 'Releasing for priority shift',
        ]);
        $deallocRes->assertStatus(200);
        $deallocRes->assertJsonPath('data.status', 'released');

        // Pool is now back to 5 available
        $c2 = $this->getJson('/api/v1/assembly-allocation/context?standard_part_no=' . $this->bopPartNo . '&bom_type=BOP&project_id=' . $this->project->id);
        $c2->assertJsonPath('data.part_summary.unallocated_assembly_ready', 5);
        $c2->assertJsonPath('data.part_summary.total_allocated', 0);
    }

    /**
     * Test adjusting allocation quantity.
     */
    public function test_adjust_allocation_quantity(): void
    {
        Sanctum::actingAs($this->managerUser);

        $receipt = Receipt::create([
            'project_id' => $this->project->id,
            'supplier_id' => $this->supplier->id,
            'delivery_note_number' => 'DN-BOP-6',
            'received_by' => $this->adminUser->id,
        ]);
        ReceiptItem::create([
            'receipt_id' => $receipt->id,
            'bom_item_id' => $this->bopUnit1->id,
            'side' => 'COMMON',
            'received_quantity' => 10,
            'status' => 'in_assembly',
        ]);

        // Allocate 4 pcs to Unit 1
        $allocRes = $this->postJson('/api/v1/assembly-allocation/allocate', [
            'bom_item_id' => $this->bopUnit1->id,
            'side' => 'COMMON',
            'quantity' => 4,
        ]);
        $allocId = $allocRes->json('data.id');

        // Adjust to 7 pcs
        $adjRes = $this->postJson('/api/v1/assembly-allocation/adjust', [
            'allocation_id' => $allocId,
            'quantity' => 7,
        ]);
        $adjRes->assertStatus(200);
        $adjRes->assertJsonPath('data.allocated_quantity', 7);

        // Adjust to 0 releases allocation
        $zeroRes = $this->postJson('/api/v1/assembly-allocation/adjust', [
            'allocation_id' => $allocId,
            'quantity' => 0,
        ]);
        $zeroRes->assertStatus(200);
        $zeroRes->assertJsonPath('data.status', 'released');
    }

    /**
     * Test auto-consumption when BOP assembly completes.
     */
    public function test_auto_consumption_on_bop_assembly_completion(): void
    {
        Sanctum::actingAs($this->managerUser);

        // Put 6 pcs in_assembly
        $receipt = Receipt::create([
            'project_id' => $this->project->id,
            'supplier_id' => $this->supplier->id,
            'delivery_note_number' => 'DN-BOP-7',
            'received_by' => $this->adminUser->id,
        ]);
        ReceiptItem::create([
            'receipt_id' => $receipt->id,
            'bom_item_id' => $this->bopUnit1->id,
            'side' => 'COMMON',
            'received_quantity' => 6,
            'status' => 'in_assembly',
        ]);

        // Allocate 4 pcs to Unit 1
        $allocRes = $this->postJson('/api/v1/assembly-allocation/allocate', [
            'bom_item_id' => $this->bopUnit1->id,
            'side' => 'COMMON',
            'quantity' => 4,
        ]);
        $allocId = $allocRes->json('data.id');

        // Complete 2 pcs via BopIntakeService
        $bopService = app(BopIntakeService::class);
        $bopService->transitionQuantity(
            partNo: $this->bopPartNo,
            fromDept: 'assembly',
            toDept: 'completed',
            quantity: 2,
            projectId: $this->project->id,
            userId: $this->managerUser->id
        );

        // Allocation should be decremented to 2 pcs and remain active
        $alloc = AssemblyAllocation::find($allocId);
        $this->assertEquals(2, $alloc->allocated_quantity);
        $this->assertEquals('active', $alloc->status);

        // Complete another 2 pcs
        $bopService->transitionQuantity(
            partNo: $this->bopPartNo,
            fromDept: 'assembly',
            toDept: 'completed',
            quantity: 2,
            projectId: $this->project->id,
            userId: $this->managerUser->id
        );

        // Allocation should now be consumed
        $alloc->refresh();
        $this->assertEquals('consumed', $alloc->status);
    }

    /**
     * Test STD parts allocation and auto-consumption on completion.
     */
    public function test_std_parts_allocation_and_auto_consumption(): void
    {
        Sanctum::actingAs($this->managerUser);

        // STD part ready for assembly via direct QC inspection approval
        $receipt = Receipt::create([
            'project_id' => $this->project->id,
            'supplier_id' => $this->supplier->id,
            'delivery_note_number' => 'DN-STD-1',
            'received_by' => $this->adminUser->id,
        ]);
        $recItem = ReceiptItem::create([
            'receipt_id' => $receipt->id,
            'bom_item_id' => $this->stdUnit1->id,
            'side' => 'COMMON',
            'received_quantity' => 8,
            'status' => 'qc_approved',
        ]);
        QcInspection::create([
            'receipt_item_id' => $recItem->id,
            'bom_item_id' => $this->stdUnit1->id,
            'side' => 'COMMON',
            'inspected_quantity' => 8,
            'approved_quantity' => 8,
            'rejected_quantity' => 0,
            'rework_quantity' => 0,
            'result' => 'approved',
            'destination' => 'ASSEMBLY',
            'inspector_id' => $this->adminUser->id,
            'inspected_by' => $this->adminUser->id,
            'inspection_date' => now(),
        ]);

        // Context should show 8 pcs assembly ready for STD
        $context = $this->getJson('/api/v1/assembly-allocation/context?standard_part_no=' . $this->stdPartNo . '&bom_type=STD&project_id=' . $this->project->id);
        $context->assertStatus(200);
        $context->assertJsonPath('data.part_summary.total_assembly_ready', 8);

        // Allocate 5 pcs to Unit 1
        $allocRes = $this->postJson('/api/v1/assembly-allocation/allocate', [
            'bom_item_id' => $this->stdUnit1->id,
            'side' => 'COMMON',
            'quantity' => 5,
        ]);
        $allocRes->assertStatus(200);
        $allocId = $allocRes->json('data.id');

        // Complete 5 pcs via StdIntakeService
        $stdService = app(StdIntakeService::class);
        $stdService->transitionQuantity(
            partNo: $this->stdPartNo,
            fromDept: 'assembly',
            toDept: 'completed',
            quantity: 5,
            options: [
                'project_id' => $this->project->id,
                'user_id' => $this->managerUser->id,
            ]
        );

        // Allocation should be marked consumed
        $alloc = AssemblyAllocation::find($allocId);
        $this->assertEquals('consumed', $alloc->status);
    }

    /**
     * Test project allocation summary endpoint.
     */
    public function test_project_allocation_summary_endpoint(): void
    {
        Sanctum::actingAs($this->managerUser);

        // Put 6 pcs in_assembly
        $receipt = Receipt::create([
            'project_id' => $this->project->id,
            'supplier_id' => $this->supplier->id,
            'delivery_note_number' => 'DN-BOP-8',
            'received_by' => $this->adminUser->id,
        ]);
        ReceiptItem::create([
            'receipt_id' => $receipt->id,
            'bom_item_id' => $this->bopUnit1->id,
            'side' => 'COMMON',
            'received_quantity' => 6,
            'status' => 'in_assembly',
        ]);

        $this->postJson('/api/v1/assembly-allocation/allocate', [
            'bom_item_id' => $this->bopUnit1->id,
            'side' => 'COMMON',
            'quantity' => 3,
        ])->assertStatus(200);

        $summaryRes = $this->getJson('/api/v1/assembly-allocation/summary?project_id=' . $this->project->id);
        $summaryRes->assertStatus(200);
        $summaryRes->assertJsonPath('success', true);
        $summaryRes->assertJsonPath('data.total_active_allocations', 1);
        $summaryRes->assertJsonPath('data.total_allocated_quantity', 3);
    }
}
