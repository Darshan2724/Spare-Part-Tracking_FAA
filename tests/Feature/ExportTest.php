<?php

namespace Tests\Feature;

use App\Models\User;
use Tests\TestCase;

class ExportTest extends TestCase
{
    protected function getAdminUser(): User
    {
        $role = \Spatie\Permission\Models\Role::firstOrCreate(['name' => 'ADMIN', 'guard_name' => 'web']);
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

    public function test_parts_movement_excel_export_returns_streamed_response()
    {
        $user = $this->getAdminUser();
        $this->actingAs($user, 'sanctum');

        $payload = [
            'format' => 'excel',
            'date_label' => '18-Aug-26',
            'department' => 'QC',
            'items' => [
                [
                    'standard_part_no' => '020#R00',
                    'project' => 'FA-279',
                    'side' => 'LH',
                    'quantity' => 1,
                    'department_event' => 'QC INSPECTED (APPROVED)',
                    'user' => 'QC Inspector',
                    'date' => '18-Aug-26',
                    'time' => '10:51:23 AM',
                ]
            ]
        ];

        $response = $this->postJson('/api/v1/export/movement', $payload);
        $response->assertStatus(200);
        $this->assertTrue(str_contains($response->headers->get('content-type', ''), 'spreadsheetml'));
    }

    public function test_parts_movement_pdf_export_returns_pdf_document()
    {
        $user = $this->getAdminUser();
        $this->actingAs($user, 'sanctum');

        $payload = [
            'format' => 'pdf',
            'date_label' => '18-Aug-26',
            'department' => 'QC',
            'items' => [
                [
                    'standard_part_no' => '020#R00',
                    'project' => 'FA-279',
                    'side' => 'LH',
                    'quantity' => 1,
                    'department_event' => 'QC INSPECTED (APPROVED)',
                    'user' => 'QC Inspector',
                    'date' => '18-Aug-26',
                    'time' => '10:51:23 AM',
                ]
            ]
        ];

        $response = $this->postJson('/api/v1/export/movement', $payload);
        $response->assertStatus(200);
        $this->assertTrue(str_contains($response->headers->get('content-type', ''), 'application/pdf'));
    }
}
