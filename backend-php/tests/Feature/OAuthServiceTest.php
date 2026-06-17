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

    public function test_first_login_creates_pending_user_and_identity(): void
    {
        $service = app(OAuthService::class);

        $result = $service->resolveLogin(
            OAuthProvider::GOOGLE,
            $this->socialite('google-123', 'new@example.test', 'New Person'),
        );

        $this->assertTrue($result['created']);
        $this->assertSame(UserRole::PENDING, $result['user']->role);
        $this->assertDatabaseHas('user_identities', [
            'provider' => 'google',
            'providerUserId' => 'google-123',
        ]);
    }

    public function test_second_login_with_same_identity_returns_same_user(): void
    {
        $service = app(OAuthService::class);
        $oauth = $this->socialite('google-xyz', 'same@example.test');

        $first = $service->resolveLogin(OAuthProvider::GOOGLE, $oauth);
        $second = $service->resolveLogin(OAuthProvider::GOOGLE, $oauth);

        $this->assertSame($first['user']->id, $second['user']->id);
        $this->assertFalse($second['created']);
        $this->assertSame(1, User::count());
    }

    public function test_login_links_to_existing_account_with_matching_email(): void
    {
        $existing = User::create([
            'name' => 'Existing Member', 'email' => 'match@example.test',
            'password' => 'password123', 'role' => UserRole::MEMBER, 'isActive' => true,
        ]);

        $result = app(OAuthService::class)->resolveLogin(
            OAuthProvider::FACEBOOK,
            $this->socialite('fb-1', 'match@example.test'),
        );

        $this->assertSame($existing->id, $result['user']->id);
        $this->assertSame(UserRole::MEMBER, $result['user']->role); // unchanged
        $this->assertDatabaseHas('user_identities', [
            'userId' => $existing->id,
            'provider' => 'facebook',
        ]);
        $this->assertSame(1, User::count());
    }

    public function test_unlink_removes_identity(): void
    {
        $service = app(OAuthService::class);
        $result = $service->resolveLogin(
            OAuthProvider::GOOGLE,
            $this->socialite('g-unlink', 'unlink@example.test'),
        );
        $user = $result['user'];

        $service->unlink($user, OAuthProvider::GOOGLE);

        $this->assertDatabaseMissing('user_identities', [
            'userId' => $user->id,
            'provider' => 'google',
        ]);
    }
}
