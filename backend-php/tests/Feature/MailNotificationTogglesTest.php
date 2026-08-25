<?php

namespace Tests\Feature;

use App\Domain\Enums\AuditEntityType;
use App\Domain\Enums\MailNotification;
use App\Domain\Enums\UserRole;
use App\Mail\AccountInvitationMail;
use App\Mail\MembershipApprovedMail;
use App\Mail\PasswordResetMail;
use App\Mail\PendingMemberNotificationMail;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\AdminNotifier;
use App\Services\CaptchaService;
use App\Services\MailNotificationSettings;
use App\Services\PasswordResetService;
use App\Services\UsersService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Every transactional e-mail can be switched off individually from the
 * admin diagnostics page. All four go through NotificationMailer, which
 * is the single place the toggle is consulted.
 */
class MailNotificationTogglesTest extends TestCase
{
    use RefreshDatabase;

    private function settings(): MailNotificationSettings
    {
        return app(MailNotificationSettings::class);
    }

    private function disable(MailNotification $type): void
    {
        $this->settings()->update([$type->value => false]);
    }

    private function member(string $email = 'clen@example.test'): User
    {
        return User::create([
            'name' => 'Test Clen', 'email' => $email,
            'password' => 'password123', 'role' => UserRole::MEMBER, 'isActive' => true,
        ]);
    }

    /* ──────────────  Defaults  ────────────── */

    public function test_every_notification_is_enabled_by_default(): void
    {
        foreach (MailNotification::cases() as $type) {
            $this->assertTrue(
                $this->settings()->isEnabled($type),
                "{$type->value} malo byt predvolene zapnute",
            );
        }
    }

    /* ──────────────  Password reset  ────────────── */

    public function test_password_reset_is_sent_when_enabled(): void
    {
        Mail::fake();
        $user = $this->member();

        app(PasswordResetService::class)->requestReset($user->email);

        Mail::assertSent(PasswordResetMail::class);
    }

    public function test_password_reset_is_skipped_when_disabled(): void
    {
        Mail::fake();
        $user = $this->member();
        $this->disable(MailNotification::PASSWORD_RESET);

        app(PasswordResetService::class)->requestReset($user->email);

        Mail::assertNotSent(PasswordResetMail::class);
    }

    /**
     * The toggle doubles as an outage kill-switch: with a broken mailer
     * the endpoint would throw, but a disabled notification never reaches
     * the transport, so the request completes cleanly.
     */
    public function test_disabled_password_reset_keeps_forgot_password_endpoint_healthy(): void
    {
        $this->member('reset@example.test');
        $this->disable(MailNotification::PASSWORD_RESET);
        config(['mail.default' => 'chybajuci-mailer']);

        $challenge = app(CaptchaService::class)->issue();
        [$a, $op, $b] = explode(' ', $challenge['question']);
        $answer = $op === '+' ? ((int) $a + (int) $b) : ((int) $a * (int) $b);

        $this->postJson('/api/v1/auth/forgot-password', [
            'email' => 'reset@example.test',
            'captchaToken' => $challenge['token'],
            'captchaAnswer' => (string) $answer,
        ])->assertOk();
    }

    /* ──────────────  Account invitation  ────────────── */

    public function test_account_invitation_is_sent_when_enabled(): void
    {
        Mail::fake();
        $user = $this->member('pozvany@example.test');

        app(PasswordResetService::class)->sendInvitation($user);

        Mail::assertSent(AccountInvitationMail::class);
    }

    public function test_account_invitation_is_skipped_when_disabled(): void
    {
        Mail::fake();
        $user = $this->member('pozvany@example.test');
        $this->disable(MailNotification::ACCOUNT_INVITATION);

        app(PasswordResetService::class)->sendInvitation($user);

        Mail::assertNotSent(AccountInvitationMail::class);
    }

    /* ──────────────  Membership approved  ────────────── */

    public function test_membership_approved_is_sent_when_enabled(): void
    {
        Mail::fake();
        $admin = $this->actingAsAdmin();
        $pending = User::create([
            'name' => 'Caka', 'email' => 'caka@example.test',
            'password' => 'password123', 'role' => UserRole::PENDING, 'isActive' => true,
        ]);

        app(UsersService::class)->confirmPending($pending->id, $admin);

        Mail::assertSent(MembershipApprovedMail::class);
    }

    public function test_membership_approved_is_skipped_when_disabled(): void
    {
        Mail::fake();
        $admin = $this->actingAsAdmin();
        $pending = User::create([
            'name' => 'Caka', 'email' => 'caka@example.test',
            'password' => 'password123', 'role' => UserRole::PENDING, 'isActive' => true,
        ]);
        $this->disable(MailNotification::MEMBERSHIP_APPROVED);

        app(UsersService::class)->confirmPending($pending->id, $admin);

        Mail::assertNotSent(MembershipApprovedMail::class);
    }

    /* ──────────────  Admin notice about a pending member  ────────────── */

    public function test_pending_member_notice_is_sent_when_enabled(): void
    {
        Mail::fake();
        $user = $this->member('novy@example.test');

        app(AdminNotifier::class)->pendingMemberAwaitingApproval($user);

        Mail::assertSent(PendingMemberNotificationMail::class);
    }

    public function test_pending_member_notice_is_skipped_when_disabled(): void
    {
        Mail::fake();
        $user = $this->member('novy@example.test');
        $this->disable(MailNotification::PENDING_MEMBER_ADMIN);

        app(AdminNotifier::class)->pendingMemberAwaitingApproval($user);

        Mail::assertNotSent(PendingMemberNotificationMail::class);
    }

    /* ──────────────  Settings API  ────────────── */

    public function test_partial_update_leaves_the_other_toggles_untouched(): void
    {
        $this->disable(MailNotification::PASSWORD_RESET);

        $this->assertFalse($this->settings()->isEnabled(MailNotification::PASSWORD_RESET));
        $this->assertTrue($this->settings()->isEnabled(MailNotification::ACCOUNT_INVITATION));
        $this->assertTrue($this->settings()->isEnabled(MailNotification::MEMBERSHIP_APPROVED));
        $this->assertTrue($this->settings()->isEnabled(MailNotification::PENDING_MEMBER_ADMIN));
    }

    public function test_endpoint_lists_all_notifications_with_metadata(): void
    {
        $this->actingAsAdmin();

        $r = $this->getJson('/api/v1/admin/mail/notifications')->assertOk();

        $this->assertCount(count(MailNotification::cases()), $r->json('notifications'));
        $r->assertJsonStructure([
            'notifications' => [['key', 'label', 'description', 'critical', 'consequence', 'enabled']],
        ]);
    }

    public function test_endpoint_toggles_a_notification(): void
    {
        $this->actingAsAdmin();

        $this->patchJson('/api/v1/admin/mail/notifications', ['password_reset' => false])
            ->assertOk();

        $this->assertFalse($this->settings()->isEnabled(MailNotification::PASSWORD_RESET));
    }

    public function test_endpoint_rejects_unknown_notification_keys(): void
    {
        $this->actingAsAdmin();

        $this->patchJson('/api/v1/admin/mail/notifications', ['vymyslene' => false])
            ->assertStatus(400);
    }

    public function test_toggle_change_is_audited(): void
    {
        $this->actingAsAdmin();

        $this->patchJson('/api/v1/admin/mail/notifications', ['password_reset' => false])
            ->assertOk();

        $this->assertTrue(
            AuditLog::query()
                ->where('entityType', AuditEntityType::SETTING->value)
                ->where('entityId', MailNotificationSettings::SETTING_KEY)
                ->exists(),
        );
    }
}
