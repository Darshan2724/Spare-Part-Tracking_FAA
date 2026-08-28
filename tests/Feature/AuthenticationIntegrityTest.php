<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AuthenticationIntegrityTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        // Seed roles & departments
        $dept = Department::firstOrCreate(['code' => 'ADMIN'], ['name' => 'Administration', 'code' => 'ADMIN']);
        Role::firstOrCreate(['name' => 'ADMIN', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'MANAGER', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'STORE', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'QC', 'guard_name' => 'web']);

        $user = User::updateOrCreate(
            ['email' => 'admin@sparetrack.internal'],
            [
                'name' => 'System Admin',
                'password' => 'password123',
                'department_id' => $dept->id,
                'is_active' => true,
            ]
        );
        $user->assignRole('ADMIN');
    }

    public function test_valid_credentials_login_succeeds(): void
    {
        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'admin@sparetrack.internal',
            'password' => 'password123',
        ]);

        $response->assertStatus(200)
            ->assertJsonStructure([
                'token',
                'user' => ['id', 'name', 'email', 'roles'],
            ]);

        $this->assertEquals('admin@sparetrack.internal', $response->json('user.email'));
    }

    public function test_case_insensitive_email_login_succeeds(): void
    {
        $response = $this->postJson('/api/v1/auth/login', [
            'email' => '  ADMIN@SPARETRACK.INTERNAL  ',
            'password' => 'password123',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('user.email', 'admin@sparetrack.internal');
    }

    public function test_invalid_password_fails(): void
    {
        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'admin@sparetrack.internal',
            'password' => 'wrongpassword',
        ]);

        $response->assertStatus(401)
            ->assertJsonPath('message', 'Invalid credentials');
    }

    public function test_unknown_user_fails(): void
    {
        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'unknown@sparetrack.internal',
            'password' => 'password123',
        ]);

        $response->assertStatus(401)
            ->assertJsonPath('message', 'Invalid credentials');
    }

    public function test_deactivated_user_is_blocked(): void
    {
        $user = User::where('email', 'admin@sparetrack.internal')->first();
        $user->is_active = false;
        $user->save();

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'admin@sparetrack.internal',
            'password' => 'password123',
        ]);

        $response->assertStatus(403);
    }

    public function test_user_model_is_immune_to_double_hashing_with_bcrypt_input(): void
    {
        $dept = Department::first();
        $preHashed = bcrypt('password123');

        $user = User::create([
            'name' => 'Prehashed User',
            'email' => 'prehashed@sparetrack.internal',
            'password' => $preHashed,
            'department_id' => $dept->id,
            'is_active' => true,
        ]);

        // Assert the hash was not hashed again
        $this->assertEquals($preHashed, $user->password);
        $this->assertTrue(Hash::check('password123', $user->password));

        // Test login
        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'prehashed@sparetrack.internal',
            'password' => 'password123',
        ]);

        $response->assertStatus(200);
    }

    public function test_self_healing_recovers_double_hashed_or_legacy_records(): void
    {
        $dept = Department::first();
        $user = new User();
        $user->name = 'Legacy User';
        $user->email = 'legacy@sparetrack.internal';
        $user->department_id = $dept->id;
        $user->is_active = true;
        // Force direct assignment
        $user->setRawAttributes(array_merge($user->getAttributes(), [
            'password' => 'password123', // plain text stored directly
        ]));
        $user->save();

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'legacy@sparetrack.internal',
            'password' => 'password123',
        ]);

        $response->assertStatus(200);

        // Verify password was auto-healed into a valid bcrypt hash
        $user->refresh();
        $this->assertNotEquals('password123', $user->password);
        $this->assertTrue(Hash::check('password123', $user->password));
    }

    public function test_multi_device_independent_tokens(): void
    {
        // Device 1 login
        $res1 = $this->postJson('/api/v1/auth/login', [
            'email' => 'admin@sparetrack.internal',
            'password' => 'password123',
        ]);
        $token1 = $res1->json('token');

        // Device 2 login
        $res2 = $this->postJson('/api/v1/auth/login', [
            'email' => 'admin@sparetrack.internal',
            'password' => 'password123',
        ]);
        $token2 = $res2->json('token');

        $this->assertNotEquals($token1, $token2);

        // Both tokens can access protected endpoint
        $me1 = $this->withHeader('Authorization', 'Bearer ' . $token1)->getJson('/api/v1/auth/me');
        $me1->assertStatus(200);

        $me2 = $this->withHeader('Authorization', 'Bearer ' . $token2)->getJson('/api/v1/auth/me');
        $me2->assertStatus(200);

        // Logout from Device 1
        $logout1 = $this->withHeader('Authorization', 'Bearer ' . $token1)->postJson('/api/v1/auth/logout');
        $logout1->assertStatus(200);

        // Device 2 token remains active and valid
        $me2After = $this->withHeader('Authorization', 'Bearer ' . $token2)->getJson('/api/v1/auth/me');
        $me2After->assertStatus(200);
    }
}
