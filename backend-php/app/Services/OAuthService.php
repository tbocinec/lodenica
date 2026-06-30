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
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly AdminNotifier $notifier,
        private readonly MemberRosterService $roster,
    ) {}

    /**
     * Log in an EXISTING social user without creating anything. Returns the
     * user when the identity is already known, or when the provider's email
     * matches an existing account (auto-linking the identity). Returns null
     * for a brand-new person — the controller then routes them through the
     * GDPR consent gate before `completeRegistration` creates the account.
     */
    public function attemptLogin(OAuthProvider $provider, SocialiteUser $oauth): ?User
    {
        $providerUserId = (string) $oauth->getId();

        $identity = UserIdentity::query()
            ->where('provider', $provider->value)
            ->where('providerUserId', $providerUserId)
            ->first();
        if ($identity !== null) {
            return $identity->user;
        }

        $email = $oauth->getEmail();
        $existing = $email ? User::query()->where('email', $email)->first() : null;
        if ($existing !== null) {
            $this->createIdentity($existing, $provider, $providerUserId, $email);

            return $existing;
        }

        return null;
    }

    /**
     * Create the PENDING account for a first-time social user AFTER they've
     * accepted the GDPR consents. Idempotent: if the identity or a matching
     * email already exists (e.g. a double submit), returns that user without
     * creating a duplicate.
     *
     * @param  array{privacyAck: bool, dataConsent: bool, rulesAck?: bool}  $consents
     */
    public function completeRegistration(
        OAuthProvider $provider,
        string $providerUserId,
        ?string $email,
        ?string $name,
        array $consents,
    ): User {
        $identity = UserIdentity::query()
            ->where('provider', $provider->value)
            ->where('providerUserId', $providerUserId)
            ->first();
        if ($identity !== null) {
            return $identity->user;
        }
        $existing = $email ? User::query()->where('email', $email)->first() : null;
        if ($existing !== null) {
            $this->createIdentity($existing, $provider, $providerUserId, $email);

            return $existing;
        }

        // Member roster ("číselník"): a pre-listed email is auto-approved as a
        // MEMBER with the roster's member ID; otherwise the account is PENDING.
        $match = $email ? $this->roster->findMatch($email) : null;

        return DB::transaction(function () use ($provider, $providerUserId, $email, $name, $consents, $match) {
            $user = User::create([
                'name' => $name ?: ($email ?: 'Nový člen'),
                'email' => $email ?: $provider->value.'_'.$providerUserId.'@oauth.local',
                'password' => Str::random(40), // unusable until they set one
                'role' => $match !== null ? UserRole::MEMBER : UserRole::PENDING,
                'isActive' => true,
                'memberId' => $match?->memberId,
                'privacyAck' => $consents['privacyAck'] ?? true,
                'dataConsent' => $consents['dataConsent'] ?? false,
                'gdprConsentAt' => now(),
                'rulesAck' => $consents['rulesAck'] ?? true,
                'rulesAckAt' => now(),
            ]);

            $this->createIdentity($user, $provider, $providerUserId, $email);

            $this->audit->logCreate(
                AuditEntityType::USER,
                $user,
                "Registrácia cez {$provider->label()}: „{$user->name}“ ({$user->email})",
                ['name' => $user->name, 'email' => $user->email, 'role' => $user->role->value],
            );

            if ($match !== null) {
                $this->roster->markRegistered($match, $user);
            } else {
                // New OAuth account is PENDING — notify an admin it's waiting.
                $this->notifier->pendingMemberAwaitingApproval($user);
            }

            return $user;
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
