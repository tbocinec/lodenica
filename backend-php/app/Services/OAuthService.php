<?php

namespace App\Services;

use App\Domain\Enums\AuditEntityType;
use App\Domain\Enums\OAuthProvider;
use App\Domain\Enums\UserRole;
use App\Exceptions\ConflictDomainException;
use App\Models\User;
use App\Models\UserIdentity;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Socialite\Contracts\User as SocialiteUser;

/**
 * Maps a Socialite (Google/Facebook) identity onto a local account.
 *
 * Resolution order on login:
 *   1. known (provider, providerUserId) → that user
 *   2. matching email on an existing account → auto-link the identity
 *      (the provider has verified the email, so it's the same person)
 *   3. otherwise → create a new PENDING account + identity
 *
 * Linking from the profile screen attaches an identity to the already
 * logged-in user, refusing if that identity is bound elsewhere.
 *
 * See docs/AUTH-AND-PERMISSIONS.md.
 */
class OAuthService
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * Find-or-create the local user for a social login.
     *
     * @return array{user: User, created: bool}
     */
    public function resolveLogin(OAuthProvider $provider, SocialiteUser $oauth): array
    {
        $providerUserId = (string) $oauth->getId();

        $identity = UserIdentity::query()
            ->where('provider', $provider->value)
            ->where('providerUserId', $providerUserId)
            ->first();
        if ($identity !== null) {
            return ['user' => $identity->user, 'created' => false];
        }

        $email = $oauth->getEmail();
        $existing = $email ? User::query()->where('email', $email)->first() : null;
        if ($existing !== null) {
            $this->createIdentity($existing, $provider, $providerUserId, $email);

            return ['user' => $existing, 'created' => false];
        }

        return DB::transaction(function () use ($provider, $providerUserId, $oauth, $email) {
            $user = User::create([
                'name' => $oauth->getName() ?: ($oauth->getNickname() ?: ($email ?: 'Nový člen')),
                'email' => $email ?: $provider->value.'_'.$providerUserId.'@oauth.local',
                'password' => Str::random(40), // unusable until they set one
                'role' => UserRole::PENDING,
                'isActive' => true,
            ]);

            $this->createIdentity($user, $provider, $providerUserId, $email);

            $this->audit->logCreate(
                AuditEntityType::USER,
                $user,
                "Registrácia cez {$provider->label()}: „{$user->name}“ ({$user->email})",
                ['name' => $user->name, 'email' => $user->email, 'role' => $user->role->value],
            );

            return ['user' => $user, 'created' => true];
        });
    }

    /**
     * Attach a social identity to an already-authenticated user (profile
     * "link account" flow). Idempotent if it's already this user's; throws
     * if the identity belongs to someone else.
     */
    public function link(User $user, OAuthProvider $provider, SocialiteUser $oauth): UserIdentity
    {
        $providerUserId = (string) $oauth->getId();

        $identity = UserIdentity::query()
            ->where('provider', $provider->value)
            ->where('providerUserId', $providerUserId)
            ->first();

        if ($identity !== null) {
            if ($identity->userId !== $user->id) {
                throw new ConflictDomainException(
                    "Tento {$provider->label()} účet je už prepojený s iným používateľom.",
                );
            }

            return $identity;
        }

        return $this->createIdentity($user, $provider, $providerUserId, $oauth->getEmail());
    }

    public function unlink(User $user, OAuthProvider $provider): void
    {
        $user->identities()
            ->where('provider', $provider->value)
            ->delete();
    }

    private function createIdentity(User $user, OAuthProvider $provider, string $providerUserId, ?string $email): UserIdentity
    {
        return UserIdentity::create([
            'userId' => $user->id,
            'provider' => $provider,
            'providerUserId' => $providerUserId,
            'email' => $email,
        ]);
    }
}
