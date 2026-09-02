<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\Project;
use App\Models\Supplier;
use App\Models\SupplierPhone;
use App\Models\SupplierAssignment;
use App\Models\User;
use App\Services\SupplierImportService;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class SupplierManagementNavigationAndImportTest extends TestCase
{
    protected User $adminUser;
    protected User $purchaseUser;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'ADMIN', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'PURCHASE', 'guard_name' => 'web']);

        $dept = Department::firstOrCreate(['code' => 'PURCHASE'], ['name' => 'Purchase Dept', 'code' => 'PURCHASE']);

        $this->adminUser = User::firstOrCreate(
            ['email' => 'admin_nav_test@sparetrack.internal'],
            ['name' => 'Admin Nav Test', 'password' => 'password123', 'department_id' => $dept->id, 'is_active' => true]
        );
        if (!$this->adminUser->hasRole('ADMIN')) {
            $this->adminUser->assignRole('ADMIN');
        }

        $this->purchaseUser = User::firstOrCreate(
            ['email' => 'purchase_nav_test@sparetrack.internal'],
            ['name' => 'Purchase Nav Test', 'password' => 'password123', 'department_id' => $dept->id, 'is_active' => true]
        );
        if (!$this->purchaseUser->hasRole('PURCHASE')) {
            $this->purchaseUser->assignRole('PURCHASE');
        }
    }

    /**
     * Test manual supplier creation with multiple phone numbers and pincode.
     */
    public function test_manual_supplier_creation_with_multiple_phone_numbers()
    {
        $code = 'TEST_APEX_' . time();
        $payload = [
            'name' => 'Apex Precision Works ' . time(),
            'code' => $code,
            'contact_person' => 'Mr. Rajesh Sharma',
            'city' => 'Pune',
            'pincode' => '411026',
            'phones' => ['9011456443', '9850940544', '+91 9763805070'],
            'remarks' => 'Laser cutting and precision machining specialist',
            'is_active' => true,
            'is_test_data' => true,
        ];

        $response = $this->actingAs($this->purchaseUser)
            ->postJson('/api/v1/suppliers', $payload);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'supplier' => [
                    'code' => $code,
                    'city' => 'Pune',
                    'pincode' => '411026',
                ],
            ]);

        $supplier = Supplier::where('code', $code)->first();
        $this->assertNotNull($supplier);
        $this->assertCount(3, $supplier->phones);
        $this->assertEquals('9011456443', $supplier->phones[0]->phone_number);
        $this->assertTrue($supplier->phones[0]->is_primary);

        // Verify it appears immediately in active-list
        $activeListResponse = $this->actingAs($this->purchaseUser)
            ->getJson('/api/v1/suppliers/active-list');

        $activeListResponse->assertStatus(200);
        $this->assertTrue(collect($activeListResponse->json('suppliers'))->contains('code', $code));
    }

    /**
     * Test preview of sample BOM/supplier list 1.xlsx.
     */
    public function test_supplier_excel_import_preview_with_sample_file()
    {
        $response = $this->actingAs($this->purchaseUser)
            ->postJson('/api/v1/suppliers/import/preview', [
                'use_sample' => true,
            ]);

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'total_rows',
                'new_count',
                'duplicate_count',
                'invalid_count',
                'rows' => [
                    '*' => [
                        'sr_no',
                        'name',
                        'code',
                        'city',
                        'pincode',
                        'contact_person',
                        'phones',
                        'status',
                    ],
                ],
            ]);

        $this->assertEquals(60, $response->json('total_rows'));
    }

    /**
     * Test commit of imported rows creates suppliers and multiple phones.
     */
    public function test_supplier_excel_import_commit_transaction()
    {
        $code1 = 'TEST_DIG_' . time();
        $code2 = 'TEST_AS_' . time();

        $rowsToCommit = [
            [
                'sr_no' => 1,
                'name' => 'DIGVIJAY ENGG WORKS TEST ' . time(),
                'code' => $code1,
                'glcd' => $code1,
                'city' => 'PUNE',
                'pincode' => '411039',
                'contact_person' => 'MR.DIGVIJAY MADANE',
                'raw_phone' => '7666998446/9763805070/9850940544',
                'phones' => ['7666998446', '9763805070', '9850940544'],
                'primary_phone' => '7666998446',
                'status' => 'new',
            ],
            [
                'sr_no' => 2,
                'name' => 'A S ENGINEERING_MFG TEST ' . time(),
                'code' => $code2,
                'glcd' => $code2,
                'city' => 'Pune',
                'pincode' => '411026',
                'contact_person' => 'Mr. Arjun Suryavanshi',
                'raw_phone' => '9011456443',
                'phones' => ['9011456443'],
                'primary_phone' => '9011456443',
                'status' => 'new',
            ],
        ];

        $response = $this->actingAs($this->purchaseUser)
            ->postJson('/api/v1/suppliers/import/commit', [
                'rows' => $rowsToCommit,
            ]);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'result' => [
                    'created_count' => 2,
                ],
            ]);

        // Verify suppliers and phone normalization in database
        $digvijay = Supplier::where('code', $code1)->first();
        $this->assertNotNull($digvijay);
        $this->assertEquals('PUNE', $digvijay->city);
        $this->assertEquals('411039', $digvijay->pincode);
        $this->assertCount(3, $digvijay->phones);

        $asEng = Supplier::where('code', $code2)->first();
        $this->assertNotNull($asEng);
        $this->assertCount(1, $asEng->phones);
    }

    /**
     * Test Standalone Overview Table API.
     */
    public function test_standalone_overview_table_api()
    {
        $projectCode = 'FA-NAV-TEST-' . uniqid();
        $project = Project::create([
            'project_code' => $projectCode,
            'name' => 'FA-NAV-TEST Project',
            'is_test_data' => true,
            'is_active' => true,
        ]);

        $supplier = Supplier::create([
            'name' => 'Omni Engineering Test ' . uniqid(),
            'code' => '153OMNI_' . uniqid(),
            'is_active' => true,
            'is_test_data' => true,
        ]);

        SupplierAssignment::create([
            'project_id' => $project->id,
            'jig_no' => 'JIG-NAV-01',
            'unit_no' => '01',
            'category' => 'BASE',
            'supplier_id' => $supplier->id,
            'assignment_date' => now()->toDateString(),
            'status' => 'active',
            'assigned_by' => $this->purchaseUser->id,
        ]);

        $response = $this->actingAs($this->purchaseUser)
            ->getJson("/api/v1/supplier-allocation/overview?project_id={$project->id}");

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    '*' => [
                        'id',
                        'project_id',
                        'jig_no',
                        'unit_no',
                        'category',
                        'supplier_id',
                        'supplier',
                    ],
                ],
            ]);

        $this->assertTrue(collect($response->json('data'))->contains('jig_no', 'JIG-NAV-01'));

        SupplierAssignment::where('project_id', $project->id)->delete();
        $supplier->forceDelete();
        $project->forceDelete();
    }

    /**
     * Test Purchase Queue endpoints (/purchase/items and /purchase/queue alias).
     */
    public function test_purchase_queue_endpoints()
    {
        $response1 = $this->actingAs($this->purchaseUser)->getJson('/api/v1/purchase/items');
        $response1->assertStatus(200)
            ->assertJsonStructure(['items', 'projects']);

        $response2 = $this->actingAs($this->purchaseUser)->getJson('/api/v1/purchase/queue');
        $response2->assertStatus(200)
            ->assertJsonStructure(['items', 'projects']);
    }

    protected function tearDown(): void
    {
        // Clean up test suppliers created with TEST_ codes
        $testSupps = Supplier::withTrashed()
            ->where('code', 'LIKE', 'TEST_%')
            ->orWhere('code', 'LIKE', '153OMNI_%')
            ->get();

        foreach ($testSupps as $s) {
            $s->phones()->delete();
            $s->forceDelete();
        }

        \App\Models\SupplierImport::where('filename', 'supplier_list.xlsx')->forceDelete();

        parent::tearDown();
    }
}
