<?php

namespace App\Services;

use App\Domain\Enums\MailNotification;
use App\Exceptions\InvalidResetTokenException;
use App\Mail\AccountInvitationMail;
use App\Mail\PasswordResetMail;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Password-reset and account-invitation tokens share one mechanism and one
 * table (password_reset_tokens). The emailed token is random; only its
 * sha256 hash is stored. Each row carries its own expiry so the short reset
 * window and the longer invitation window can coexist.
 *
 * "Forgot password" and "set your first password" (bulk import / invited
 * account) are the same flow from the user's point of view — both land on
 * the SPA's reset-password screen.
 */
class PasswordResetService
{
    public const RESET_TTL_MINUTES = 60;
    public const INVITE_TTL_DAYS = 30;

    public function __construct(private readonly NotificationMailer $mailer) {}

    /**
     * Issue + email a reset link. No-op (silently) when the email is
     * unknown or the account is inactive — callers must NOT reveal which,
     * to avoid account enumeration.
     */
    public function requestReset(string $email): void
    {
        $user = User::query()->where('email', $email)->first();
        if ($user === null || !$user->isActive) {
            return;
        }

        $plain = $this->issueToken($user->email, self::RESET_TTL_MINUTES * 60);
        $url = $this->buildUrl($user->email, $plain, invite: false);

        $this->mailer->send(
            MailNotification::PASSWORD_RESET,
            $user->email,
            new PasswordResetMail($user->email, $url, self::RESET_TTL_MINUTES),
        );
    }

    /**
     * Issue + email an invitation ("set your first password") link for an
     * account created by an admin / bulk import.
     */
    public function sendInvitation(User $user): void
    {
        $plain = $this->issueToken($user->email, self::INVITE_TTL_DAYS * 86400);
        $url = $this->buildUrl($user->email, $plain, invite: true);

        $this->mailer->send(
            MailNotification::ACCOUNT_INVITATION,
            $user->email,
            new AccountInvitationMail($user->email, $user->name, $url, self::INVITE_TTL_DAYS),
        );
    }

    /**
     * Consume a token and set the new password. Revokes all existing API
     * tokens so a leaked session can't outlive the reset. Throws
     * InvalidResetTokenException for any bad/expired/used token.
     */
    public function reset(string $email, string $plainToken, string $newPassword, array $consents = []): User
    {
        $row = DB::table('password_reset_tokens')->where('email', $email)->first();
        if ($row === null) {
            throw new InvalidResetTokenException();
        }

        $expiresAt = $row->expires_at ? Carbon::parse($row->expires_at) : null;
        if ($expiresAt !== null && $expiresAt->isPast()) {
            DB::table('password_reset_tokens')->where('email', $email)->delete();
            throw new InvalidResetTokenException();
        }

        if (!hash_equals((string) $row->token, hash('sha256', $plainToken))) {
            throw new InvalidResetTokenException();
        }

        $user = User::query()->where('email', $email)->first();
        if ($user === null) {
            throw new InvalidResetTokenException();
        }

        $user->password = $newPassword; // hashed via cast
        // First time the user sets their own password — record it and (for an
        // invited member who started inactive) activate the account. Existing
        // active users doing a normal reset are unaffected; inactive accounts
        // can't reach here via forgot-password (requestReset gates on active),
        // so only invitees get activated by this.
        $user->passwordSetAt = now();
        if (!$user->isActive) {
            $user->isActive = true;
        }
        // GDPR consents captured when an invited member sets their first
        // password (the invite link's set-password screen shows the same
        // sections as registration). Only applied when supplied.
        if (array_key_exists('privacyAck', $consents) || array_key_exists('dataConsent', $consents)) {
            $user->privacyAck = array_key_exists('privacyAck', $consents)
                ? (bool) $consents['privacyAck']
                : true;
            if (array_key_exists('dataConsent', $consents)) {
                $user->dataConsent = (bool) $consents['dataConsent'];
            }
            $user->gdprConsentAt = now();
        }
        if (array_key_exists('rulesAck', $consents)) {
            $user->rulesAck = (bool) $consents['rulesAck'];
            $user->rulesAckAt = now();
        }
        $user->save();
        $user->tokens()->delete();

        DB::table('password_reset_tokens')->where('email', $email)->delete();

        return $user;
    }

    private function issueToken(string $email, int $ttlSeconds): string
    {
        $plain = Str::random(64);

        DB::table('password_reset_tokens')->updateOrInsert(
            ['email' => $email],
            [
                'token' => hash('sha256', $plain),
                'created_at' => now(),
                'expires_at' => now()->addSeconds($ttlSeconds),
            ],
        );

        return $plain;
    }

    private function buildUrl(string $email, string $plain, bool $invite): string
    {
        $base = rtrim((string) config('app.url'), '/');
        $query = http_build_query(array_filter([
            'token' => $plain,
            'email' => $email,
            'invite' => $invite ? '1' : null,
        ]));

        return $base.'/reset-password?'.$query;
    }
}
