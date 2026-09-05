<?php

namespace Tests\Feature;

use App\Domain\Enums\AuditEntityType;
use App\Domain\Enums\MailNotification;
use App\Domain\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\UserNotificationPreferences;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** REZ-062: a member's own e-mail switches. Missing key = on; admin switch still wins (tested in ReservationNotifierTest). */
class UserNotificationPreferencesTest extends TestCase
{
    use RefreshDatabase;

    private function prefs(): UserNotificationPreferences
    {
        return app(UserNotificationPreferences::class);
    }

    private function member(): User
    {
        return User::create([
            'name' => 'Clen', 'email' => 'clen@example.test',
            'password' => 'password123', 'role' => UserRole::MEMBER, 'isActive' => true,
        ]);
    }

    public function test_everything_configurable_is_on_by_default(): void
    {
        $all = $this->prefs()->all($this->member());

        $this->assertSame(
            ['reservation_approval_requested' => true, 'reservation_decided' => true],
            $all,
        );
    }

    public function test_non_configurable_notifications_are_always_wanted(): void
    {
        $user = $this->member();
        $user->notificationPrefs = ['password_reset' => false];
        $user->save();

        $this->assertTrue($this->prefs()->wants($user, MailNotification::PASSWORD_RESET));
    }

    public function test_partial_update_persists_and_keeps_the_rest(): void
    {
        $user = $this->member();

        $state = $this->prefs()->update($user, ['reservation_decided' => false]);

        $this->assertFalse($state['reservation_decided']);
        $this->assertTrue($state['reservation_approval_requested']);
        $this->assertFalse($this->prefs()->wants($user->refresh(), MailNotification::RESERVATION_DECIDED));
        $this->assertTrue($this->prefs()->wants($user, MailNotification::RESERVATION_APPROVAL_REQUESTED));
    }

    public function test_unknown_keys_are_ignored(): void
    {
        $user = $this->member();

        $state = $this->prefs()->update($user, ['vymyslene' => false, 'password_reset' => false]);

        $this->assertArrayNotHasKey('vymyslene', $state);
        $this->assertArrayNotHasKey('password_reset', $state);
    }

    public function test_a_change_is_audited_on_the_user(): void
    {
        $user = $this->member();

        $this->prefs()->update($user, ['reservation_decided' => false]);

        $this->assertTrue(
            AuditLog::query()
                ->where('entityType', AuditEntityType::USER->value)
                ->where('entityId', $user->id)
                ->exists(),
        );
    }
}
