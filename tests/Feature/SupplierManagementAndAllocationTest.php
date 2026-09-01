<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\Project;
use App\Models\Supplier;
use App\Models\BomItem;
use App\Models\BomRequirement;
use App\Models\SupplierAssignment;
use App\Models\SupplierAssignmentHistory;
use App\Models\ReworkRecord;
use App\Models\ReceiptItem;
use App\Models\Department;
use Spatie\Permission\Models\Role;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class SupplierManagementAndAllocationTest extends TestCase
{
    protected User $adminUser;
    protected User $managerUser;
    protected User $purchaseUser;
    protected User $storeUser;
    protected Project $testProject;
    protected Supplier $testSupplierAlpha;
    protected Supplier $testSupplierBeta;

    protected function setUp(): void
    {
        parent::setUp();

        // Roles & Users
        $adminRole = Role::firstOrCreate(['name' => 'ADMIN', 'guard_name' => 'web']);
        $managerRole = Role::firstOrCreate(['name' => 'MANAGER', 'guard_name' => 'web']);
        $purchaseRole = Role::firstOrCreate(['name' => 'PURCHASE', 'guard_name' => 'web']);
        $storeRole = Role::firstOrCreate(['name' => 'STORE', 'guard_name' => 'web']);

        $dept = Department::firstOrCreate(['code' => 'ADMIN'], ['name' => 'Administration', 'code' => 'ADMIN']);

        $this->adminUser = User::firstOrCreate(
            ['email' => 'admin_test_supp@sparetrack.internal'],
            ['name' => 'Admin Test Supp', 'password' => 'password123', 'department_id' => $dept->id, 'is_active' => true]
        );
        $this->adminUser->syncRoles([$adminRole]);

        $this->managerUser = User::firstOrCreate(
            ['email' => 'manager_test_supp@sparetrack.internal'],
            ['name' => 'Manager Test Supp', 'password' => 'password123', 'department_id' => $dept->id, 'is_active' => true]
        );
        $this->managerUser->syncRoles([$managerRole]);

        $this->purchaseUser = User::firstOrCreate(
            ['email' => 'purchase_test_supp@sparetrack.internal'],
            ['name' => 'Purchase Test Supp', 'password' => 'password123', 'department_id' => $dept->id, 'is_active' => true]
        );
        $this->purchaseUser->syncRoles([$purchaseRole]);

        $this->storeUser = User::firstOrCreate(
            ['email' => 'store_test_supp@sparetrack.internal'],
            ['name' => 'Store Test Supp', 'password' => 'password123', 'department_id' => $dept->id, 'is_active' => true]
        );
        $this->storeUser->syncRoles([$storeRole]);

        // Create isolated test Project
        $this->testProject = Project::firstOrCreate(
            ['project_code' => 'PROJ-TEST-SUPP-01'],
            ['name' => 'Test Supplier Fixture Project', 'status' => 'active']
        );

        // Create test suppliers
        $this->testSupplierAlpha = Supplier::firstOrCreate(
            ['code' => 'SUPP-T-ALPHA'],
            [
                'name' => 'Alpha Precision Test',
                'contact_person' => 'Alpha Tester',
                'phone' => '1112223334',
                'email' => 'alpha@test.internal',
                'city' => 'Pune',
                'state' => 'Maharashtra',
                'country' => 'India',
                'is_active' => true,
                'is_test_data' => true,
            ]
        );

        $this->testSupplierBeta = Supplier::firstOrCreate(
            ['code' => 'SUPP-T-BETA'],
            [
                'name' => 'Beta Tooling Test',
                'contact_person' => 'Beta Tester',
                'phone' => '2223334445',
                'email' => 'beta@test.internal',
                'city' => 'Mumbai',
                'state' => 'Maharashtra',
                'country' => 'India',
                'is_active' => true,
                'is_test_data' => true,
            ]
        );

        // Create sample BOM items in test project for Jig 'JIG-999@' and Unit 'Unit 01'
        $bomItem = BomItem::firstOrCreate(
            ['project_id' => $this->testProject->id, 'jig_no' => 'JIG-999@', 'unit_no' => 'Unit 01', 'standard_part_no' => 'PART-TEST-001'],
            ['supplier_id' => $this->testSupplierAlpha->id, 'item_no' => '010']
        );

        BomRequirement::firstOrCreate(
            ['bom_item_id' => $bomItem->id, 'side' => 'RH'],
            ['required_quantity' => 2]
        );
    }

    protected function tearDown(): void
    {
        // Clean up test assignments and history created specifically in tests
        SupplierAssignmentHistory::where('project_id', $this->testProject->id)->delete();
        SupplierAssignment::where('project_id', $this->testProject->id)->delete();
        Supplier::withTrashed()->where('code', 'SUPP-T-GAMMA')->forceDelete();

        parent::tearDown();
    }

    public function test_supplier_master_crud_and_dropdown_list(): void
    {
        // 1. Get active list
        $res = $this->actingAs($this->purchaseUser)->getJson('/api/v1/suppliers/active-list');
        $res->assertStatus(200);
        $res->assertJsonFragment(['name' => 'Alpha Precision Test']);

        // 2. Create new supplier
        $createRes = $this->actingAs($this->purchaseUser)->postJson('/api/v1/suppliers', [
            'name' => 'Gamma Fabrication Test',
            'code' => 'SUPP-T-GAMMA',
            'contact_person' => 'Gamma Officer',
            'city' => 'Bengaluru',
            'is_active' => true,
            'is_test_data' => true,
        ]);
        $createRes->assertStatus(200);
        $createRes->assertJsonFragment(['name' => 'Gamma Fabrication Test']);

        $gamma = Supplier::where('code', 'SUPP-T-GAMMA')->first();
        $this->assertNotNull($gamma);

        // 3. Update supplier
        $updateRes = $this->actingAs($this->purchaseUser)->putJson("/api/v1/suppliers/{$gamma->id}", [
            'name' => 'Gamma Fabrication Test Updated',
            'code' => 'SUPP-T-GAMMA',
            'city' => 'Chennai',
            'is_active' => true,
            'is_test_data' => true,
        ]);
        $updateRes->assertStatus(200);
        $this->assertEquals('Chennai', $gamma->fresh()->city);

        // 4. Delete supplier
        $delRes = $this->actingAs($this->adminUser)->deleteJson("/api/v1/suppliers/{$gamma->id}");
        $delRes->assertStatus(200);
        $this->assertTrue($gamma->fresh()->trashed());
    }

    public function test_supplier_allocation_hierarchy_returns_proper_structure(): void
    {
        $res = $this->actingAs($this->purchaseUser)->getJson("/api/v1/supplier-allocation/hierarchy?project_id={$this->testProject->id}");
        $res->assertStatus(200);
        $res->assertJsonStructure([
            'projects',
            'hierarchy' => [
                'project_id',
                'jigs' => [
                    '*' => [
                        'jig_no',
                        'total_units',
                        'assigned_slots',
                        'total_slots',
                        'allocation_pct',
                        'units' => [
                            '*' => [
                                'unit_no',
                                'categories' => [
                                    'BASE',
                                    'WELDMENT',
                                    'CHILD_PART',
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ]);
    }

    public function test_single_category_assignment_with_7_day_date_validation(): void
    {
        $today = Carbon::today();
        $todayStr = $today->format('Y-m-d');
        $validPast = $today->copy()->subDays(3)->format('Y-m-d');
        $validFuture = $today->copy()->addDays(3)->format('Y-m-d');
        $invalidPast = $today->copy()->subDays(4)->format('Y-m-d');
        $invalidFuture = $today->copy()->addDays(4)->format('Y-m-d');

        // 1. Assign with TODAY -> Success
        $resToday = $this->actingAs($this->purchaseUser)->postJson('/api/v1/supplier-allocation/assign', [
            'project_id' => $this->testProject->id,
            'jig_no' => 'JIG-999@',
            'unit_no' => 'Unit 01',
            'category' => 'BASE',
            'supplier_id' => $this->testSupplierAlpha->id,
            'assignment_date' => $todayStr,
        ]);
        $resToday->assertStatus(200);
        $this->assertDatabaseHas('supplier_assignments', [
            'project_id' => $this->testProject->id,
            'jig_no' => 'JIG-999@',
            'unit_no' => 'Unit 01',
            'category' => 'BASE',
            'supplier_id' => $this->testSupplierAlpha->id,
            'status' => 'active',
        ]);

        // 2. Assign with 3 days back -> Success (WELDMENT)
        $resPast = $this->actingAs($this->purchaseUser)->postJson('/api/v1/supplier-allocation/assign', [
            'project_id' => $this->testProject->id,
            'jig_no' => 'JIG-999@',
            'unit_no' => 'Unit 01',
            'category' => 'WELDMENT',
            'supplier_id' => $this->testSupplierBeta->id,
            'assignment_date' => $validPast,
        ]);
        $resPast->assertStatus(200);

        // 3. Assign with 3 days future -> Success (CHILD_PART)
        $resFuture = $this->actingAs($this->purchaseUser)->postJson('/api/v1/supplier-allocation/assign', [
            'project_id' => $this->testProject->id,
            'jig_no' => 'JIG-999@',
            'unit_no' => 'Unit 01',
            'category' => 'CHILD_PART',
            'supplier_id' => $this->testSupplierAlpha->id,
            'assignment_date' => $validFuture,
        ]);
        $resFuture->assertStatus(200);

        // 4. Assign with 4 days back -> 422 Rejection
        $resInvalidPast = $this->actingAs($this->purchaseUser)->postJson('/api/v1/supplier-allocation/assign', [
            'project_id' => $this->testProject->id,
            'jig_no' => 'JIG-999@',
            'unit_no' => 'Unit 01',
            'category' => 'BASE',
            'supplier_id' => $this->testSupplierAlpha->id,
            'assignment_date' => $invalidPast,
        ]);
        $resInvalidPast->assertStatus(422);

        // 5. Assign with 4 days future -> 422 Rejection
        $resInvalidFuture = $this->actingAs($this->purchaseUser)->postJson('/api/v1/supplier-allocation/assign', [
            'project_id' => $this->testProject->id,
            'jig_no' => 'JIG-999@',
            'unit_no' => 'Unit 01',
            'category' => 'BASE',
            'supplier_id' => $this->testSupplierAlpha->id,
            'assignment_date' => $invalidFuture,
        ]);
        $resInvalidFuture->assertStatus(422);
    }

    public function test_assignment_update_preserves_audit_history_and_supersedes_active(): void
    {
        $todayStr = Carbon::today()->format('Y-m-d');
        $tomorrowStr = Carbon::today()->addDay()->format('Y-m-d');

        // Initial assignment
        $this->actingAs($this->purchaseUser)->postJson('/api/v1/supplier-allocation/assign', [
            'project_id' => $this->testProject->id,
            'jig_no' => 'JIG-999@',
            'unit_no' => 'Unit 01',
            'category' => 'BASE',
            'supplier_id' => $this->testSupplierAlpha->id,
            'assignment_date' => $todayStr,
        ]);

        // Reassign to Supplier Beta with tomorrow's date
        $updateRes = $this->actingAs($this->purchaseUser)->postJson('/api/v1/supplier-allocation/assign', [
            'project_id' => $this->testProject->id,
            'jig_no' => 'JIG-999@',
            'unit_no' => 'Unit 01',
            'category' => 'BASE',
            'supplier_id' => $this->testSupplierBeta->id,
            'assignment_date' => $tomorrowStr,
        ]);
        $updateRes->assertStatus(200);

        // Verify active assignment count is exactly 1 for this (project, jig, unit, category)
        $activeAssignments = SupplierAssignment::where('project_id', $this->testProject->id)
            ->where('jig_no', 'JIG-999@')
            ->where('unit_no', 'Unit 01')
            ->where('category', 'BASE')
            ->where('status', 'active')
            ->get();
        $this->assertCount(1, $activeAssignments);
        $this->assertEquals($this->testSupplierBeta->id, $activeAssignments->first()->supplier_id);

        // Verify superseded assignment exists
        $superseded = SupplierAssignment::where('project_id', $this->testProject->id)
            ->where('jig_no', 'JIG-999@')
            ->where('unit_no', 'Unit 01')
            ->where('category', 'BASE')
            ->where('status', 'superseded')
            ->get();
        $this->assertCount(1, $superseded);

        // Verify history audit logs
        $history = SupplierAssignmentHistory::where('project_id', $this->testProject->id)
            ->where('jig_no', 'JIG-999@')
            ->where('unit_no', 'Unit 01')
            ->where('category', 'BASE')
            ->orderBy('created_at')
            ->get();

        $this->assertCount(2, $history);
        $this->assertEquals('created', $history[0]->action);
        $this->assertEquals('updated', $history[1]->action);
        $this->assertEquals($this->testSupplierAlpha->id, $history[1]->previous_supplier_id);
        $this->assertEquals($this->testSupplierBeta->id, $history[1]->new_supplier_id);
    }

    public function test_bulk_assign_and_removal(): void
    {
        $todayStr = Carbon::today()->format('Y-m-d');

        $bulkRes = $this->actingAs($this->purchaseUser)->postJson('/api/v1/supplier-allocation/bulk-assign', [
            'project_id' => $this->testProject->id,
            'jig_no' => 'JIG-999@',
            'unit_no' => 'Unit 01',
            'categories' => [
                ['category' => 'BASE', 'supplier_id' => $this->testSupplierAlpha->id, 'assignment_date' => $todayStr],
                ['category' => 'WELDMENT', 'supplier_id' => $this->testSupplierBeta->id, 'assignment_date' => $todayStr],
                ['category' => 'CHILD_PART', 'supplier_id' => $this->testSupplierAlpha->id, 'assignment_date' => $todayStr],
            ],
        ]);
        $bulkRes->assertStatus(200);

        $activeAssignments = SupplierAssignment::where('project_id', $this->testProject->id)
            ->where('jig_no', 'JIG-999@')
            ->where('unit_no', 'Unit 01')
            ->where('status', 'active')
            ->get();
        $this->assertCount(3, $activeAssignments);

        // Test removal
        $baseAssign = $activeAssignments->firstWhere('category', 'BASE');
        $delRes = $this->actingAs($this->purchaseUser)->deleteJson("/api/v1/supplier-allocation/assignments/{$baseAssign->id}");
        $delRes->assertStatus(200);

        $this->assertEquals('removed', $baseAssign->fresh()->status);
    }

    public function test_main_dashboard_jig_suppliers_visibility_and_role_security(): void
    {
        $todayStr = Carbon::today()->format('Y-m-d');

        // Create assignment for JIG-999@
        $this->actingAs($this->purchaseUser)->postJson('/api/v1/supplier-allocation/assign', [
            'project_id' => $this->testProject->id,
            'jig_no' => 'JIG-999@',
            'unit_no' => 'Unit 01',
            'category' => 'BASE',
            'supplier_id' => $this->testSupplierAlpha->id,
            'assignment_date' => $todayStr,
        ]);

        // 1. Admin can access
        $adminRes = $this->actingAs($this->adminUser)->getJson("/api/v1/dashboard/jig-suppliers?project_id={$this->testProject->id}&jig_no=JIG-999@");
        $adminRes->assertStatus(200);
        $adminRes->assertJsonFragment(['supplier_name' => 'Alpha Precision Test']);

        // 2. Manager can access
        $mgrRes = $this->actingAs($this->managerUser)->getJson("/api/v1/dashboard/jig-suppliers?project_id={$this->testProject->id}&jig_no=JIG-999@");
        $mgrRes->assertStatus(200);

        // 3. Purchase can access
        $purRes = $this->actingAs($this->purchaseUser)->getJson("/api/v1/dashboard/jig-suppliers?project_id={$this->testProject->id}&jig_no=JIG-999@");
        $purRes->assertStatus(200);

        // 4. Store role is restricted (403)
        $storeRes = $this->actingAs($this->storeUser)->getJson("/api/v1/dashboard/jig-suppliers?project_id={$this->testProject->id}&jig_no=JIG-999@");
        $storeRes->assertStatus(403);
    }

    public function test_supplier_analytics_kpis_and_rankings_endpoints(): void
    {
        $todayStr = Carbon::today()->format('Y-m-d');

        $this->actingAs($this->purchaseUser)->postJson('/api/v1/supplier-allocation/assign', [
            'project_id' => $this->testProject->id,
            'jig_no' => 'JIG-999@',
            'unit_no' => 'Unit 01',
            'category' => 'BASE',
            'supplier_id' => $this->testSupplierAlpha->id,
            'assignment_date' => $todayStr,
        ]);

        // KPIs
        $kpiRes = $this->actingAs($this->managerUser)->getJson('/api/v1/supplier-analytics/kpis');
        $kpiRes->assertStatus(200);
        $kpiRes->assertJsonStructure([
            'success',
            'kpis' => [
                'total_suppliers',
                'active_suppliers',
                'suppliers_in_use',
                'projects_with_allocation',
                'jigs_with_allocation',
                'units_with_allocation',
                'total_active_assignments',
                'suppliers_with_rework',
            ],
        ]);
        $this->assertGreaterThanOrEqual(1, $kpiRes->json('kpis.suppliers_in_use'));

        // Rankings
        $rankRes = $this->actingAs($this->managerUser)->getJson('/api/v1/supplier-analytics/ranking?sort_by=usage');
        $rankRes->assertStatus(200);
        $rankRes->assertJsonStructure([
            'success',
            'rankings' => [
                '*' => [
                    'supplier_id',
                    'supplier_name',
                    'total_assignments',
                    'rework_rate',
                    'rank',
                ],
            ],
        ]);

        // Rework Analysis
        $reworkRes = $this->actingAs($this->managerUser)->getJson('/api/v1/supplier-analytics/rework');
        $reworkRes->assertStatus(200);
        $reworkRes->assertJsonStructure(['success', 'rework' => ['summary', 'recent_events']]);

        // History
        $histRes = $this->actingAs($this->managerUser)->getJson('/api/v1/supplier-analytics/history');
        $histRes->assertStatus(200);
        $this->assertGreaterThanOrEqual(1, $histRes->json('total'));
    }

    public function test_supplier_assignment_produces_zero_mutation_on_bom_and_workflow_quantities(): void
    {
        // Snapshot BOM and Requirement state before assignment
        $bomCountBefore = BomItem::count();
        $totalReqBefore = BomRequirement::sum('required_quantity');
        $receiptsBefore = ReceiptItem::count();
        $reworkBefore = ReworkRecord::count();

        $todayStr = Carbon::today()->format('Y-m-d');

        // Create assignment
        $this->actingAs($this->purchaseUser)->postJson('/api/v1/supplier-allocation/assign', [
            'project_id' => $this->testProject->id,
            'jig_no' => 'JIG-999@',
            'unit_no' => 'Unit 01',
            'category' => 'BASE',
            'supplier_id' => $this->testSupplierAlpha->id,
            'assignment_date' => $todayStr,
        ]);

        // Assert zero mutation on BOM or physical workflow counts
        $this->assertEquals($bomCountBefore, BomItem::count(), 'BOM item count must remain unchanged.');
        $this->assertEquals($totalReqBefore, BomRequirement::sum('required_quantity'), 'Total required quantity must remain strictly unchanged.');
        $this->assertEquals($receiptsBefore, ReceiptItem::count(), 'Receipt items count must remain strictly unchanged.');
        $this->assertEquals($reworkBefore, ReworkRecord::count(), 'Rework records count must remain strictly unchanged.');
    }

    public function test_supplier_assignment_reflection_and_direct_unit_sync(): void
    {
        $todayStr = Carbon::today()->format('Y-m-d');

        // Assign BASE and WELDMENT
        $this->actingAs($this->purchaseUser)->postJson('/api/v1/supplier-allocation/assign', [
            'project_id' => $this->testProject->id,
            'jig_no' => 'JIG-999@',
            'unit_no' => 'Unit 01',
            'category' => 'BASE',
            'supplier_id' => $this->testSupplierAlpha->id,
            'assignment_date' => $todayStr,
        ])->assertStatus(200);

        $this->actingAs($this->purchaseUser)->postJson('/api/v1/supplier-allocation/assign', [
            'project_id' => $this->testProject->id,
            'jig_no' => 'JIG-999@',
            'unit_no' => 'Unit 01',
            'category' => 'WELDMENT',
            'supplier_id' => $this->testSupplierBeta->id,
            'assignment_date' => $todayStr,
        ])->assertStatus(200);

        // 1. Direct unit query reflection
        $directRes = $this->actingAs($this->purchaseUser)->getJson("/api/v1/supplier-allocation/assignments?project_id={$this->testProject->id}&jig_no=JIG-999@&unit_no=Unit%2001");
        $directRes->assertStatus(200);
        $directRes->assertJsonCount(2, 'assignments');

        // 2. Hierarchy query reflection with category slots populated
        $hierRes = $this->actingAs($this->purchaseUser)->getJson("/api/v1/supplier-allocation/hierarchy?project_id={$this->testProject->id}");
        $hierRes->assertStatus(200);
        
        $jigs = $hierRes->json('hierarchy.jigs');
        $jig = collect($jigs)->firstWhere('jig_no', 'JIG-999@');
        $this->assertNotNull($jig);
        
        $unit = collect($jig['units'])->firstWhere('unit_no', 'Unit 01');
        $this->assertNotNull($unit);
        $this->assertEquals(2, $unit['assigned_count']);
        $this->assertEquals('Alpha Precision Test', $unit['categories']['BASE']['supplier_name']);
        $this->assertEquals('Beta Tooling Test', $unit['categories']['WELDMENT']['supplier_name']);
        $this->assertNull($unit['categories']['CHILD_PART']);
    }
}
