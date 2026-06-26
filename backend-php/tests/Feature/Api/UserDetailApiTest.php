<?php

namespace Tests\Feature\Api;

use App\Domain\Enums\OAuthProvider;
use App\Domain\Enums\UserRole;
use App\Models\User;
use App\Models\UserIdentity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserDetailApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_detail_includes_identities_and_timestamps(): void
    {
        $this->actingAsAdmin();

        $user = User::create([
            'name' => 'Member', 'email' => 'm@example.test',
            'password' => 'password123', 'role' => UserRole::MEMBER, 'isActive' => true,
            'privacyAck' => true, 'dataConsent' => false,
            'passwordSetAt' => now(), 'gdprConsentAt' => now(),
        ]);
        UserIdentity::create([
            'userId' => $user->id,
            'provider' => OAuthProvider::GOOGLE,
            'providerUserId' => 'g-123',
            'email' => 'm@gmail.test',
        ]);

        $this->getJson("/api/v1/users/{$user->id}")
            ->assertOk()
            ->assertJsonPath('privacyAck', true)
            ->assertJsonPath('dataConsent', false)
            ->assertJsonPath('identities.0.provider', 'google')
            ->assertJsonPath('identities.0.email', 'm@gmail.test')
            ->assertJsonStructure(['passwordSetAt', 'gdprConsentAt']);
    }

    public function test_member_does_not_see_identities_or_admin_fields(): void
    {
        $self = $this->actingAsMember();

        $this->getJson("/api/v1/users/{$self->id}")
            ->assertStatus(403); // index/show is admin-only
    }

    public function test_admin_can_unlink_identity(): void
    {
        $this->actingAsAdmin();

        $user = User::create([
            'name' => 'Linked', 'email' => 'l@example.test',
            'password' => 'password123', 'role' => UserRole::MEMBER, 'isActive' => true,
        ]);
        UserIdentity::create([
            'userId' => $user->id,
            'provider' => OAuthProvider::GOOGLE,
            'providerUserId' => 'g-999',
            'email' => 'l@gmail.test',
        ]);

        $this->deleteJson("/api/v1/users/{$user->id}/identities/google")
            ->assertNoContent();

        $this->assertDatabaseMissing('user_identities', [
            'userId' => $user->id,
            'provider' => 'google',
        ]);
    }

    public function test_unlink_identity_is_admin_only(): void
    {
        $this->actingAsMember();

        $other = User::create([
            'name' => 'Other', 'email' => 'o@example.test',
            'password' => 'password123', 'role' => UserRole::MEMBER, 'isActive' => true,
        ]);

        $this->deleteJson("/api/v1/users/{$other->id}/identities/google")
            ->assertStatus(403);
    }
}
