<?php

namespace Tests\Feature;

use App\Domain\Enums\MailNotification;
use App\Domain\Enums\ReservationStatus;
use App\Domain\Enums\ResourceType;
use App\Domain\Enums\UserRole;
use App\Mail\ReservationApprovalRequestedMail;
use App\Mail\ReservationConfirmedMail;
use App\Mail\ReservationDecidedMail;
use App\Models\Reservation;
use App\Models\Resource;
use App\Models\User;
use App\Services\MailNotificationSettings;
use App\Services\ReservationNotifier;
use App\Services\ReservationsService;
use App\Services\UserNotificationPreferences;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/** REZ-053, REZ-057 and REZ-064: who gets which booking e-mail, and which switches silence it. */
class ReservationNotifierTest extends TestCase
{
    use RefreshDatabase;

    private Resource $space;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        config(['site.admin_email' => 'admins@example.test', 'app.url' => 'https://rez.example.test']);
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

    /* ──────────────  REZ-064: booking confirmation (opt-in)  ────────────── */

    private function book(?User $creator, array $extra = []): Reservation
    {
        $resource = Resource::create(['identifier' => 'K-1', 'type' => ResourceType::SEA_KAYAK, 'name' => 'Cetus']);

        return app(ReservationsService::class)->create(array_merge([
            'resourceId' => $resource->id,
            'createdById' => $creator?->id,
            'customerName' => 'Janka',
            'startsAt' => '2027-06-02T09:00:00Z',
            'endsAt' => '2027-06-02T12:00:00Z',
            'note' => 'Ranná jazda',
        ], $extra));
    }

    private function optIn(User $user): User
    {
        app(UserNotificationPreferences::class)->update($user, ['reservation_confirmed' => true]);

        return $user;
    }

    public function test_confirmed_booking_is_not_mailed_by_default(): void
    {
        $this->book($this->user('c@example.test'));

        Mail::assertNotSent(ReservationConfirmedMail::class);
    }

    public function test_confirmed_booking_is_mailed_to_a_creator_who_opted_in(): void
    {
        config(['app.url' => 'https://rez.example.test']);
        $creator = $this->optIn($this->user('c@example.test'));

        $reservation = $this->book($creator);

        Mail::assertSent(ReservationConfirmedMail::class, function (ReservationConfirmedMail $m) use ($creator, $reservation) {
            $html = $m->render(); // runs build(): subject + attachment
            $this->assertTrue($m->hasTo($creator->email));
            $this->assertStringContainsString('K-1 – Cetus', (string) $m->subject);
            $this->assertStringContainsString('https://www.google.com/calendar/render?', $html);
            $this->assertStringContainsString("https://rez.example.test/api/v1/reservations/{$reservation->id}/ics", $html);
            $this->assertStringContainsString('Ranná jazda', $html);
            $this->assertStringContainsString('reservations?mine=1', $html);
            $this->assertNotEmpty($m->rawAttachments);
            $this->assertSame('rezervacia-'.substr($reservation->id, 0, 8).'.ics', $m->rawAttachments[0]['name']);
            $this->assertStringContainsString('BEGIN:VCALENDAR', $m->rawAttachments[0]['data']);

            return true;
        });
    }

    public function test_anonymous_booking_sends_no_confirmation(): void
    {
        $this->book(null);

        Mail::assertNotSent(ReservationConfirmedMail::class);
    }

    public function test_pending_booking_gets_no_confirmation(): void
    {
        $creator = $this->optIn($this->user('c@example.test'));

        $this->book($creator, ['resourceId' => $this->space->id]);

        Mail::assertNotSent(ReservationConfirmedMail::class);
        Mail::assertSent(ReservationApprovalRequestedMail::class);
    }

    public function test_boats_attached_to_an_event_send_no_confirmation(): void
    {
        $creator = $this->optIn($this->user('c@example.test'));
        $event = \App\Models\Event::create([
            'title' => 'Splav', 'description' => null, 'location' => 'Devín',
            'startsAt' => '2027-06-02T08:00:00Z', 'endsAt' => '2027-06-02T13:00:00Z',
        ]);

        $this->book($creator, ['eventId' => $event->id]);

        Mail::assertNotSent(ReservationConfirmedMail::class);
    }

    public function test_admin_switch_silences_confirmations_too(): void
    {
        $creator = $this->optIn($this->user('c@example.test'));
        app(MailNotificationSettings::class)->update(['reservation_confirmed' => false]);

        $this->book($creator);

        Mail::assertNotSent(ReservationConfirmedMail::class);
    }
}
