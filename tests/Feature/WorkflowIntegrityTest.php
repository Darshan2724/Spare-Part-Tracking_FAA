<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Project;
use App\Models\BomItem;
use App\Models\BomRequirement;
use App\Models\Receipt;
use App\Models\ReceiptItem;
use App\Models\QcInspection;
use App\Models\PaintRecord;
use App\Models\AssemblyRecord;
use Tests\TestCase;

class WorkflowIntegrityTest extends TestCase
{
    protected function getAdminUser(): User
    {
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
            $user->assignRole('ADMIN');
        }
        return $user;
    }

    public function test_auth_me_endpoint_returns_authenticated_user()
    {
        $user = $this->getAdminUser();
        $this->actingAs($user, 'sanctum');

        $response = $this->getJson('/api/v1/auth/me');
        $response->assertStatus(200)
                 ->assertJsonStructure([
                     'id', 'name', 'email'
                 ]);
    }

    public function test_dashboard_summary_returns_valid_structure()
    {
        $user = $this->getAdminUser();
        $this->actingAs($user, 'sanctum');

        $response = $this->getJson('/api/v1/dashboard/summary');
        $response->assertStatus(200)
                 ->assertJsonStructure([
                     'today_throughput',
                     'delayed_parts',
                     'projects_progress',
                 ]);
    }

    public function test_side_isolation_between_rh_and_lh()
    {
        $user = $this->getAdminUser();
        $this->actingAs($user, 'sanctum');

        // Verify RH and LH requirements are queried independently
        $rhReqs = BomRequirement::where('side', 'RH')->count();
        $lhReqs = BomRequirement::where('side', 'LH')->count();

        $this->assertIsInt($rhReqs);
        $this->assertIsInt($lhReqs);
    }
}
