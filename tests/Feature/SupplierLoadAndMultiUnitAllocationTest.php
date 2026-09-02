<?php

namespace Tests\Feature;

use App\Models\BomItem;
use App\Models\Project;
use App\Models\Supplier;
use App\Models\SupplierAssignment;
use App\Models\SupplierAssignmentHistory;
use App\Models\SupplierImport;
use App\Models\User;
use App\Services\SupplierLoadService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SupplierLoadAndMultiUnitAllocationTest extends TestCase
{
    protected User $adminUser;
    protected Project $project;
    protected Supplier $supplierAlpha;
    protected Supplier $supplierBeta;
    protected Supplier $supplierGamma;

    protected function setUp(): void
    {
        parent::setUp();

        $adminRole = \Spatie\Permission\Models\Role::firstOrCreate(['name' => 'ADMIN', 'guard_name' => 'web']);
        $dept = \App\Models\Department::firstOrCreate(['code' => 'ADMIN'], ['name' => 'Administration', 'code' => 'ADMIN']);

        $this->adminUser = User::firstOrCreate(
            ['email' => 'admin_load_test@sparetrack.internal'],
            [
                'name' => 'Admin Load User',
                'password' => bcrypt('password'),
                'department_id' => $dept->id,
                'is_active' => true,
            ]
        );
        $this->adminUser->syncRoles([$adminRole]);

        $this->project = Project::firstOrCreate(
            ['project_code' => 'TEST-PROJ-LOAD'],
            [
                'name' => 'Test Project for Supplier Load',
                'status' => 'active',
            ]
        );

        $this->supplierAlpha = Supplier::firstOrCreate(
            ['code' => 'SUP-ALPHA-TEST'],
            [
                'name' => 'Alpha Industrial Supplies',
                'is_active' => true,
                'is_test_data' => true,
            ]
        );

        $this->supplierBeta = Supplier::firstOrCreate(
            ['code' => 'SUP-BETA-TEST'],
            [
                'name' => 'Beta Engineering Components',
                'is_active' => true,
                'is_test_data' => true,
            ]
        );

        $this->supplierGamma = Supplier::firstOrCreate(
            ['code' => 'SUP-GAMMA-TEST'],
            [
                'name' => 'Gamma Precision Works',
                'is_active' => true,
                'is_test_data' => true,
            ]
        );
    }

    protected function tearDown(): void
    {
        // Clean up test supplier assignments and history
        SupplierAssignmentHistory::where('project_id', $this->project->id)->delete();
        SupplierAssignment::where('project_id', $this->project->id)->delete();

        // Clean up fixture suppliers
        Supplier::whereIn('code', [
            'SUP-ALPHA-TEST',
            'SUP-BETA-TEST',
            'SUP-GAMMA-TEST',
            'SUP-ACT-DEP-TEST',
        ])->forceDelete();

        // Clean up test projects
        $this->project->forceDelete();

        parent::tearDown();
    }

    public function test_multi_unit_atomic_assignment()
    {
        $today = Carbon::today()->format('Y-m-d');

        $payload = [
            'project_id' => $this->project->id,
            'jig_no' => 'JIG-TEST-01',
            'units' => [
                [
                    'unit_no' => 'Unit 01',
                    'categories' => [
                        ['category' => 'BASE', 'supplier_id' => $this->supplierAlpha->id, 'assignment_date' => $today],
                        ['category' => 'WELDMENT', 'supplier_id' => $this->supplierBeta->id, 'assignment_date' => $today],
                    ],
                ],
                [
                    'unit_no' => 'Unit 02',
                    'categories' => [
                        ['category' => 'BASE', 'supplier_id' => $this->supplierAlpha->id, 'assignment_date' => $today],
                        ['category' => 'CHILD_PART', 'supplier_id' => $this->supplierGamma->id, 'assignment_date' => $today],
                    ],
                ],
            ],
        ];

        $response = $this->actingAs($this->adminUser, 'sanctum')
            ->postJson('/api/v1/supplier-allocation/multi-unit-assign', $payload);

        $response->assertStatus(200);
        $response->assertJson(['success' => true, 'unit_count' => 2]);

        // Verify database state
        $this->assertDatabaseHas('supplier_assignments', [
            'project_id' => $this->project->id,
            'jig_no' => 'JIG-TEST-01',
            'unit_no' => 'Unit 01',
            'category' => 'BASE',
            'supplier_id' => $this->supplierAlpha->id,
            'status' => 'active',
        ]);

        $this->assertDatabaseHas('supplier_assignments', [
            'project_id' => $this->project->id,
            'jig_no' => 'JIG-TEST-01',
            'unit_no' => 'Unit 02',
            'category' => 'CHILD_PART',
            'supplier_id' => $this->supplierGamma->id,
            'status' => 'active',
        ]);
    }

    public function test_supplier_load_kpi_calculation_and_ranking()
    {
        $loadService = new SupplierLoadService();
        $loadData = $loadService->getSupplierLoad();

        $this->assertIsArray($loadData);
        $this->assertArrayHasKey('suppliers', $loadData);
        $this->assertArrayHasKey('total_assignments', $loadData);
        $this->assertArrayHasKey('highest_load', $loadData);
        $this->assertArrayHasKey('lowest_load', $loadData);

        // API Endpoint test
        $response = $this->actingAs($this->adminUser, 'sanctum')
            ->getJson('/api/v1/supplier-analytics/load');

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'success',
            'load' => [
                'suppliers',
                'total_assignments',
                'highest_load',
                'lowest_load',
            ],
        ]);
    }

    public function test_individual_supplier_deletion_dependency_protection()
    {
        $uniqueSuffix = uniqid();

        // 1. Supplier with active assignment -> should be deactivated, not hard-deleted
        $activeSupplier = Supplier::create([
            'name' => 'Active Dependency Supplier ' . $uniqueSuffix,
            'code' => 'SUP-ACT-' . $uniqueSuffix,
            'is_active' => true,
            'is_test_data' => true,
        ]);

        SupplierAssignment::create([
            'project_id' => $this->project->id,
            'jig_no' => 'JIG-TEST-01',
            'unit_no' => 'Unit ' . $uniqueSuffix,
            'category' => 'BASE',
            'supplier_id' => $activeSupplier->id,
            'assignment_date' => Carbon::today()->format('Y-m-d'),
            'status' => 'active',
            'created_by' => $this->adminUser->id,
        ]);

        $response = $this->actingAs($this->adminUser, 'sanctum')
            ->deleteJson("/api/v1/suppliers/{$activeSupplier->id}");

        $response->assertStatus(200);
        $response->assertJson(['success' => true, 'action' => 'deactivated']);

        // Assert supplier is still in DB with is_active = false
        $this->assertDatabaseHas('suppliers', [
            'id' => $activeSupplier->id,
            'is_active' => false,
        ]);

        // 2. Unused supplier -> can be soft-deleted safely
        $unusedSupplier = Supplier::create([
            'name' => 'Unused Disposable Supplier ' . $uniqueSuffix,
            'code' => 'SUP-UNUSED-' . $uniqueSuffix,
            'is_active' => true,
            'is_test_data' => true,
        ]);

        $response2 = $this->actingAs($this->adminUser, 'sanctum')
            ->deleteJson("/api/v1/suppliers/{$unusedSupplier->id}");

        $response2->assertStatus(200);
        $response2->assertJson(['success' => true, 'action' => 'deleted']);
    }

    public function test_supplier_import_scoped_deletion()
    {
        $uniqueSuffix = uniqid();

        // Create an import batch
        $import = SupplierImport::create([
            'filename' => 'test_disposable_import_' . $uniqueSuffix . '.xlsx',
            'total_rows' => 2,
            'created_count' => 2,
            'imported_by' => $this->adminUser->id,
        ]);

        // Create 2 suppliers belonging to this import
        $supp1 = Supplier::create([
            'name' => 'Imported Supplier One ' . $uniqueSuffix,
            'code' => 'SUP-IMP-01-' . $uniqueSuffix,
            'is_active' => true,
            'is_test_data' => true,
            'supplier_import_id' => $import->id,
        ]);

        $supp2 = Supplier::create([
            'name' => 'Imported Supplier Two ' . $uniqueSuffix,
            'code' => 'SUP-IMP-02-' . $uniqueSuffix,
            'is_active' => true,
            'is_test_data' => true,
            'supplier_import_id' => $import->id,
        ]);

        // Pre-existing supplier with null or different import_id
        $preExisting = Supplier::create([
            'name' => 'Pre-existing Independent Supplier ' . $uniqueSuffix,
            'code' => 'SUP-PRE-' . $uniqueSuffix,
            'is_active' => true,
            'is_test_data' => true,
            'supplier_import_id' => null,
        ]);

        // Delete the import batch
        $response = $this->actingAs($this->adminUser, 'sanctum')
            ->deleteJson("/api/v1/suppliers/import/{$import->id}");

        $response->assertStatus(200);
        $response->assertJson(['success' => true]);

        // Assert imported suppliers are deleted / marked inactive
        $this->assertSoftDeleted('suppliers', ['id' => $supp1->id]);
        $this->assertSoftDeleted('suppliers', ['id' => $supp2->id]);
        $this->assertDatabaseHas('suppliers', ['id' => $supp1->id, 'is_active' => false]);
        $this->assertDatabaseHas('suppliers', ['id' => $supp2->id, 'is_active' => false]);

        // Verify active list API does not return supp1 or supp2
        $activeListRes = $this->actingAs($this->adminUser, 'sanctum')
            ->getJson('/api/v1/suppliers/active-list');
        $activeListRes->assertStatus(200);
        $activeIds = collect($activeListRes->json('suppliers'))->pluck('id')->toArray();

        $this->assertNotContains($supp1->id, $activeIds);
        $this->assertNotContains($supp2->id, $activeIds);
        $this->assertContains($preExisting->id, $activeIds);

        // Clean up test records
        $supp1->forceDelete();
        $supp2->forceDelete();
        $preExisting->forceDelete();
        $import->forceDelete();
    }
}
