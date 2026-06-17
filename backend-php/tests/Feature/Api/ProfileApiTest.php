<?php

namespace Tests\Feature\Api;

use App\Domain\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ProfileApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_change_own_password_with_correct_current(): void
    {
        $user = User::create([
            'name' => 'Self', 'email' => 'self@example.test',
            'password' => 'currentpass1', 'role' => UserRole::MEMBER, 'isActive' => true,
        ]);
        \Laravel\Sanctum\Sanctum::actingAs($user, ['*']);

        $this->postJson('/api/v1/profile/change-password', [
            'currentPassword' => 'currentpass1',
            'newPassword' => 'newpass12345',
        ])->assertOk();

        $user->refresh();
        $this->assertTrue(Hash::check('newpass12345', $user->password));
    }

    public function test_change_password_rejects_wrong_current(): void
    {
        $user = User::create([
            'name' => 'Self', 'email' => 'self2@example.test',
            'password' => 'currentpass1', 'role' => UserRole::MEMBER, 'isActive' => true,
        ]);
        \Laravel\Sanctum\Sanctum::actingAs($user, ['*']);

        $this->postJson('/api/v1/profile/change-password', [
            'currentPassword' => 'wrongone',
            'newPassword' => 'newpass12345',
        ])->assertStatus(400)
            ->assertJsonPath('code', 'VALIDATION_ERROR');

        $user->refresh();
        $this->assertTrue(Hash::check('currentpass1', $user->password));
    }

    public function test_pending_user_can_also_change_own_password(): void
    {
        $user = $this->actingAsPending();
        $user->password = 'pendingpass1';
        $user->save();
        \Laravel\Sanctum\Sanctum::actingAs($user, ['*']);

        $this->postJson('/api/v1/profile/change-password', [
            'currentPassword' => 'pendingpass1',
            'newPassword' => 'pendingnew123',
        ])->assertOk();
    }

    public function test_change_password_requires_auth(): void
    {
        $this->postJson('/api/v1/profile/change-password', [
            'currentPassword' => 'x', 'newPassword' => 'yyyyyyyy1',
        ])->assertStatus(401);
    }

    public function test_identities_endpoint_returns_empty_when_no_social_logins(): void
    {
        $this->actingAsMember();
        // JsonResource::withoutWrapping() is set app-wide, so a collection
        // serializes as a bare top-level array (no "data" envelope).
        $this->getJson('/api/v1/profile/identities')
            ->assertOk()
            ->assertJsonCount(0);
    }
}
