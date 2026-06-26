<?php

namespace Tests\Feature;

use App\Domain\Enums\OAuthProvider;
use App\Domain\Enums\UserRole;
use App\Models\User;
use App\Services\OAuthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Socialite\Contracts\User as SocialiteUser;
use Tests\TestCase;

class OAuthServiceTest extends TestCase
{
    use RefreshDatabase;

    private function socialite(string $id, ?string $email, string $name = 'OAuth User'): SocialiteUser
    {
        return new class($id, $email, $name) implements SocialiteUser
        {
            public function __construct(private string $id, private ?string $email, private string $name) {}

            public function getId() { return $this->id; }
            public function getNickname() { return null; }
            public function getName() { return $this->name; }
            public function getEmail() { return $this->email; }
            public function getAvatar() { return null; }
        };
    }

    public function test_first_time_login_returns_null_and_creates_nothing(): void
    {
        // Brand-new person → no account yet; they must pass the consent gate.
        $user = app(OAuthService::class)->attemptLogin(
            OAuthProvider::GOOGLE,
            $this->socialite('google-123', 'new@example.test', 'New Person'),
        );

        $this->assertNull($user);
        $this->assertSame(0, User::count());
        $this->assertDatabaseMissing('user_identities', ['provider' => 'google']);
    }

    public function test_complete_registration_creates_pending_user_with_consents(): void
    {
        $user = app(OAuthService::class)->completeRegistration(
            OAuthProvider::GOOGLE,
            'google-123',
            'new@example.test',
            'New Person',
            ['privacyAck' => true, 'dataConsent' => false],
        );

        $this->assertSame(UserRole::PENDING, $user->role);
        $this->assertTrue((bool) $user->privacyAck);
        $this->assertFalse((bool) $user->dataConsent);
        $this->assertDatabaseHas('user_identities', [
            'provider' => 'google',
            'providerUserId' => 'google-123',
        ]);
    }

    public function test_login_after_registration_returns_same_user(): void
    {
        $service = app(OAuthService::class);
        $oauth = $this->socialite('google-xyz', 'same@example.test');

        $service->completeRegistration(OAuthProvider::GOOGLE, 'google-xyz', 'same@example.test', 'X', ['privacyAck' => true, 'dataConsent' => true]);
        $again = $service->attemptLogin(OAuthProvider::GOOGLE, $oauth);

        $this->assertNotNull($again);
        $this->assertSame(1, User::count());
    }

    public function test_login_links_to_existing_account_with_matching_email(): void
    {
        $existing = User::create([
            'name' => 'Existing Member', 'email' => 'match@example.test',
            'password' => 'password123', 'role' => UserRole::MEMBER, 'isActive' => true,
        ]);

        $user = app(OAuthService::class)->attemptLogin(
            OAuthProvider::FACEBOOK,
            $this->socialite('fb-1', 'match@example.test'),
        );

        $this->assertNotNull($user);
        $this->assertSame($existing->id, $user->id);
        $this->assertSame(UserRole::MEMBER, $user->role); // unchanged, no consent gate
        $this->assertDatabaseHas('user_identities', [
            'userId' => $existing->id,
            'provider' => 'facebook',
        ]);
        $this->assertSame(1, User::count());
    }

    public function test_complete_registration_is_idempotent(): void
    {
        $service = app(OAuthService::class);
        $a = $service->completeRegistration(OAuthProvider::GOOGLE, 'g-dup', 'dup@example.test', 'Dup', ['privacyAck' => true, 'dataConsent' => true]);
        $b = $service->completeRegistration(OAuthProvider::GOOGLE, 'g-dup', 'dup@example.test', 'Dup', ['privacyAck' => true, 'dataConsent' => true]);

        $this->assertSame($a->id, $b->id);
        $this->assertSame(1, User::count());
    }

    public function test_unlink_removes_identity(): void
    {
        $service = app(OAuthService::class);
        $user = $service->completeRegistration(OAuthProvider::GOOGLE, 'g-unlink', 'unlink@example.test', 'U', ['privacyAck' => true, 'dataConsent' => true]);

        $service->unlink($user, OAuthProvider::GOOGLE);

        $this->assertDatabaseMissing('user_identities', [
            'userId' => $user->id,
            'provider' => 'google',
        ]);
    }
}
