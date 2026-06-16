<?php

namespace Tests\Feature\Api;

use App\Domain\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The PENDING role + confirmation flow. See docs/AUTH-AND-PERMISSIONS.md
 * for the permission matrix.
 */
class UserConfirmationApiTest extends TestCase
{
    use RefreshDatabase;

    private function makePending(): User
    {
        return User::create([
            'name' => 'Newcomer',
            'email' => 'newcomer-'.bin2hex(random_bytes(3)).'@example.test',
            'password' => 'password123',
            'role' => UserRole::PENDING,
            'isActive' => true,
        ]);
    }

    public function test_admin_can_confirm_pending_user(): void
    {
        $pending = $this->makePending();
        $this->actingAsAdmin();

        $this->postJson("/api/v1/users/{$pending->id}/confirm")
            ->assertOk()
            ->assertJsonPath('id', $pending->id)
            ->assertJsonPath('role', UserRole::MEMBER->value);

        $this->assertSame(UserRole::MEMBER, $pending->refresh()->role);
    }

    public function test_member_cannot_confirm_pending_user(): void
    {
        $pending = $this->makePending();
        $this->actingAsMember();

        $this->postJson("/api/v1/users/{$pending->id}/confirm")
            ->assertStatus(403);
    }

    public function test_anonymous_cannot_confirm(): void
    {
        $pending = $this->makePending();
        $this->postJson("/api/v1/users/{$pending->id}/confirm")
            ->assertStatus(401);
    }

    public function test_confirm_is_idempotent_for_already_member(): void
    {
        $member = User::create([
            'name' => 'Already Member',
            'email' => 'already@example.test',
            'password' => 'password123',
            'role' => UserRole::MEMBER,
            'isActive' => true,
        ]);
        $this->actingAsAdmin();
        $this->postJson("/api/v1/users/{$member->id}/confirm")
            ->assertOk()
            ->assertJsonPath('role', UserRole::MEMBER->value);
    }

    public function test_confirm_rejects_admin_target(): void
    {
        $target = User::create([
            'name' => 'Other Admin',
            'email' => 'other-admin@example.test',
            'password' => 'password123',
            'role' => UserRole::ADMIN,
            'isActive' => true,
        ]);
        $this->actingAsAdmin();
        $this->postJson("/api/v1/users/{$target->id}/confirm")
            ->assertStatus(409);
    }

    public function test_pending_user_cannot_edit_reservation(): void
    {
        // Independent of confirm: route should reject PENDING users too,
        // not just anon.
        $this->actingAsPending();
        $this->patchJson('/api/v1/reservations/00000000-0000-0000-0000-000000000000', [
            'note' => 'hello',
        ])->assertStatus(403);
    }
}
