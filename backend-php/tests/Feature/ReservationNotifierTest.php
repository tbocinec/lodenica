<?php

namespace Tests\Feature;

use App\Domain\Enums\MailNotification;
use App\Domain\Enums\ReservationStatus;
use App\Domain\Enums\ResourceType;
use App\Domain\Enums\UserRole;
use App\Mail\ReservationApprovalRequestedMail;
use App\Mail\ReservationDecidedMail;
use App\Models\Reservation;
use App\Models\Resource;
use App\Models\User;
use App\Services\MailNotificationSettings;
use App\Services\ReservationNotifier;
use App\Services\UserNotificationPreferences;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/** REZ-053 and REZ-057: who gets which approval e-mail, and which switches silence it. */
class ReservationNotifierTest extends TestCase
{
    use RefreshDatabase;

    private Resource $space;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        config(['mail.admin_address' => 'admins@example.test', 'app.url' => 'https://rez.example.test']);
        $this->space = Resource::create([
            'identifier' => 'S-1', 'type' => ResourceType::BOATHOUSE_SPACE,
            'name' => 'Klubovňa', 'requiresApproval' => true,
        ]);
    }

    private function user(string $email, bool $active = true, UserRole $role = UserRole::MEMBER): User
    {
        return User::create([
            'name' => 'U '.$email, 'email' => $email, 'password' => 'password123',
            'role' => $role, 'isActive' => $active,
        ]);
    }

    private function pending(?User $creator = null, ReservationStatus $status = ReservationStatus::PENDING_APPROVAL): Reservation
    {
        return Reservation::create([
            'resourceId' => $this->space->id,
            'createdById' => $creator?->id,
            'customerName' => 'Žiadateľ',
            'customerContact' => 'ziadatel@example.test',
            'startsAt' => '2027-06-01 09:00:00',
            'endsAt' => '2027-06-01 12:00:00',
            'note' => 'Oslava',
            'status' => $status,
        ]);
    }

    private function notifier(): ReservationNotifier
    {
        return app(ReservationNotifier::class);
    }

    public function test_each_active_approver_gets_their_own_mail(): void
    {
        $a = $this->user('a@example.test');
        $b = $this->user('b@example.test');
        $inactive = $this->user('off@example.test', active: false);
        $this->space->approvers()->attach([$a->id, $b->id, $inactive->id]);

        $this->notifier()->approvalRequested($this->pending());

        Mail::assertSent(ReservationApprovalRequestedMail::class, 2);
        Mail::assertSent(ReservationApprovalRequestedMail::class, fn ($m) => $m->hasTo('a@example.test') && !$m->hasTo('b@example.test'));
        Mail::assertSent(ReservationApprovalRequestedMail::class, fn ($m) => $m->hasTo('b@example.test') && !$m->hasTo('a@example.test'));
        Mail::assertNotSent(ReservationApprovalRequestedMail::class, fn ($m) => $m->hasTo('off@example.test'));
        Mail::assertSent(ReservationApprovalRequestedMail::class, fn (ReservationApprovalRequestedMail $m) => $m->hasTo('a@example.test') && $m->personal === true);
    }

    public function test_an_approver_demoted_to_pending_is_not_mailed(): void
    {
        $member = $this->user('m@example.test');
        $demoted = $this->user('p@example.test', role: UserRole::PENDING);
        $this->space->approvers()->attach([$member->id, $demoted->id]);

        $this->notifier()->approvalRequested($this->pending());

        Mail::assertSent(ReservationApprovalRequestedMail::class, 1);
        Mail::assertSent(ReservationApprovalRequestedMail::class, fn ($m) => $m->hasTo('m@example.test'));
        Mail::assertNotSent(ReservationApprovalRequestedMail::class, fn ($m) => $m->hasTo('p@example.test'));
    }

    public function test_without_approvers_the_club_address_is_notified(): void
    {
        $this->notifier()->approvalRequested($this->pending());

        Mail::assertSent(ReservationApprovalRequestedMail::class, 1);
        Mail::assertSent(ReservationApprovalRequestedMail::class, fn ($m) => $m->hasTo('admins@example.test'));
        Mail::assertSent(ReservationApprovalRequestedMail::class, fn (ReservationApprovalRequestedMail $m) => $m->personal === false);
    }

    public function test_the_mail_carries_the_booking_details_and_the_approvals_link(): void
    {
        $a = $this->user('a@example.test');
        $this->space->approvers()->attach($a->id);

        $this->notifier()->approvalRequested($this->pending());

        Mail::assertSent(ReservationApprovalRequestedMail::class, function (ReservationApprovalRequestedMail $m) {
            return $m->resourceLabel === 'S-1 – Klubovňa'
                && $m->customerName === 'Žiadateľ'
                && $m->note === 'Oslava'
                && $m->approvalsUrl === 'https://rez.example.test/approvals'
                && str_contains($m->range, '2027-06-01 09:00');
        });
    }

    public function test_an_approver_who_switched_the_mail_off_is_skipped(): void
    {
        $a = $this->user('a@example.test');
        $b = $this->user('b@example.test');
        $this->space->approvers()->attach([$a->id, $b->id]);
        app(UserNotificationPreferences::class)->update($a, ['reservation_approval_requested' => false]);

        $this->notifier()->approvalRequested($this->pending());

        Mail::assertSent(ReservationApprovalRequestedMail::class, 1);
        Mail::assertSent(ReservationApprovalRequestedMail::class, fn ($m) => $m->hasTo('b@example.test'));
    }

    public function test_the_admin_switch_silences_everyone(): void
    {
        $a = $this->user('a@example.test');
        $this->space->approvers()->attach($a->id);
        app(MailNotificationSettings::class)->update([MailNotification::RESERVATION_APPROVAL_REQUESTED->value => false]);

        $this->notifier()->approvalRequested($this->pending());

        Mail::assertNothingSent();
    }

    public function test_decision_goes_to_the_creator(): void
    {
        $creator = $this->user('creator@example.test');
        $reservation = $this->pending($creator, ReservationStatus::CONFIRMED);
        $reservation->decisionNote = 'Kľúče u správcu.';
        $reservation->save();

        $this->notifier()->decided($reservation);

        Mail::assertSent(ReservationDecidedMail::class, function (ReservationDecidedMail $m) {
            return $m->hasTo('creator@example.test')
                && $m->approved === true
                && $m->decisionNote === 'Kľúče u správcu.'
                && $m->reservationsUrl === 'https://rez.example.test/reservations?mine=1';
        });
    }

    public function test_rejection_is_flagged_as_not_approved(): void
    {
        $creator = $this->user('creator@example.test');

        $this->notifier()->decided($this->pending($creator, ReservationStatus::REJECTED));

        Mail::assertSent(ReservationDecidedMail::class, fn (ReservationDecidedMail $m) => $m->approved === false);
    }

    public function test_decision_without_a_creator_sends_nothing(): void
    {
        $this->notifier()->decided($this->pending(null, ReservationStatus::CONFIRMED));

        Mail::assertNothingSent();
    }

    public function test_creator_who_switched_the_mail_off_is_skipped(): void
    {
        $creator = $this->user('creator@example.test');
        app(UserNotificationPreferences::class)->update($creator, ['reservation_decided' => false]);

        $this->notifier()->decided($this->pending($creator, ReservationStatus::CONFIRMED));

        Mail::assertNothingSent();
    }

    public function test_admin_switch_silences_decisions_too(): void
    {
        $creator = $this->user('creator@example.test');
        app(MailNotificationSettings::class)->update([MailNotification::RESERVATION_DECIDED->value => false]);

        $this->notifier()->decided($this->pending($creator, ReservationStatus::CONFIRMED));

        Mail::assertNothingSent();
    }
}
