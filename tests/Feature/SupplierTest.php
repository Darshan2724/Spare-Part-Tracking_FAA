<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Supplier;
use Tests\TestCase;

class SupplierTest extends TestCase
{
    protected function getAdminUser(): User
    {
        return User::where('email', 'admin@sparetrack.internal')->first();
    }

    public function test_supplier_list_api()
    {
        $user = $this->getAdminUser();
        $response = $this->actingAs($user, 'sanctum')->getJson('/api/v1/suppliers');
        $response->assertStatus(200);
    }

    public function test_add_supplier_successfully()
    {
        $user = $this->getAdminUser();
        $response = $this->actingAs($user, 'sanctum')->postJson('/api/v1/suppliers', [
            'name' => 'Alpha Precision Dynamics',
            'code' => 'SUP-999',
            'contact_person' => 'Vikram Malhotra',
            'phone' => '9822011223',
            'email' => 'sales@alphaprecision.com',
            'address' => 'MIDC Bhosari, Pune',
            'is_active' => true,
        ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('suppliers', [
            'name' => 'Alpha Precision Dynamics',
            'code' => 'SUP-999',
            'email' => 'sales@alphaprecision.com',
        ]);
    }

    public function test_add_supplier_validation_fails_on_duplicate_code()
    {
        $user = $this->getAdminUser();
        Supplier::create([
            'name' => 'Existing Vendor 1',
            'code' => 'SUP-101',
            'is_active' => true,
        ]);

        $response = $this->actingAs($user, 'sanctum')->postJson('/api/v1/suppliers', [
            'name' => 'Existing Vendor 2',
            'code' => 'SUP-101',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['code']);
    }

    public function test_add_supplier_validation_fails_on_missing_name()
    {
        $user = $this->getAdminUser();
        $response = $this->actingAs($user, 'sanctum')->postJson('/api/v1/suppliers', [
            'name' => '',
            'code' => 'SUP-102',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['name']);
    }

    public function test_export_suppliers_excel()
    {
        $user = $this->getAdminUser();
        $response = $this->actingAs($user, 'sanctum')->post('/api/v1/export/suppliers', [
            'format' => 'excel',
        ]);

        $response->assertStatus(200);
        $response->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    }

    public function test_export_suppliers_pdf()
    {
        $user = $this->getAdminUser();
        $response = $this->actingAs($user, 'sanctum')->post('/api/v1/export/suppliers', [
            'format' => 'pdf',
        ]);

        $response->assertStatus(200);
        $response->assertHeader('content-type', 'application/pdf');
    }
}
