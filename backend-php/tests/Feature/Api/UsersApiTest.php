<?php

namespace Tests\Feature\Api;

use App\Domain\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UsersApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_anonymous_cannot_list_users(): void
    {
        $this->getJson('/api/v1/users')->assertStatus(401);
    }

    public function test_member_cannot_list_users(): void
    {
        $this->actingAsMember();
        $this->getJson('/api/v1/users')->assertStatus(403);
    }

    public function test_admin_can_list_users(): void
    {
        $this->actingAsAdmin();
        $this->getJson('/api/v1/users')
            ->assertOk()
            ->assertJsonStructure(['items' => [['id', 'name', 'email', 'role', 'isActive']], 'total']);
    }

    public function test_admin_can_create_user(): void
    {
        $this->actingAsAdmin();
        $this->postJson('/api/v1/users', [
            'name' => 'Janka',
            'email' => 'janka@example.test',
            'password' => 'password123',
            'role' => 'MEMBER',
        ])
            ->assertCreated()
            ->assertJsonPath('email', 'janka@example.test')
            ->assertJsonPath('role', 'MEMBER');
    }

    public function test_admin_cannot_create_user_with_duplicate_email(): void
    {
        $this->actingAsAdmin();
        User::create([
            'name' => 'X', 'email' => 'dup@example.test', 'password' => 'p12345678',
            'role' => UserRole::MEMBER, 'isActive' => true,
        ]);
        $this->postJson('/api/v1/users', [
            'name' => 'Y', 'email' => 'dup@example.test', 'password' => 'p12345678',
            'role' => 'MEMBER',
        ])
            ->assertStatus(400)
            ->assertJsonPath('code', 'VALIDATION_ERROR');
    }

    public function test_admin_can_update_user_role_and_password(): void
    {
        $this->actingAsAdmin();
        $member = User::create([
            'name' => 'Member', 'email' => 'm@example.test', 'password' => 'oldpassword',
            'role' => UserRole::MEMBER, 'isActive' => true,
        ]);
        $this->patchJson("/api/v1/users/{$member->id}", [
            'role' => 'ADMIN',
            'password' => 'newpassword',
        ])
            ->assertOk()
            ->assertJsonPath('role', 'ADMIN');

        // Verify the new password works.
        $this->postJson('/api/v1/auth/login', [
            'email' => 'm@example.test',
            'password' => 'newpassword',
        ])->assertOk();
    }

    public function test_admin_cannot_demote_self(): void
    {
        $admin = $this->actingAsAdmin();
        $this->patchJson("/api/v1/users/{$admin->id}", ['role' => 'MEMBER'])
            ->assertStatus(403)
            ->assertJsonPath('code', 'FORBIDDEN');
    }

    public function test_admin_cannot_deactivate_self(): void
    {
        $admin = $this->actingAsAdmin();
        $this->patchJson("/api/v1/users/{$admin->id}", ['isActive' => false])
            ->assertStatus(403);
    }

    public function test_admin_cannot_delete_self(): void
    {
        $admin = $this->actingAsAdmin();
        $this->deleteJson("/api/v1/users/{$admin->id}")
            ->assertStatus(403);
    }

    public function test_admin_can_delete_other_user(): void
    {
        $this->actingAsAdmin();
        $other = User::create([
            'name' => 'X', 'email' => 'del@example.test', 'password' => 'p12345678',
            'role' => UserRole::MEMBER, 'isActive' => true,
        ]);
        $this->deleteJson("/api/v1/users/{$other->id}")->assertStatus(204);
        $this->getJson("/api/v1/users/{$other->id}")->assertStatus(404);
    }
}
