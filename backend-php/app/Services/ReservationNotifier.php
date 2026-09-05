<?php

namespace App\Services;

use App\Domain\Enums\MailNotification;
use App\Domain\Enums\ReservationStatus;
use App\Domain\Enums\UserRole;
use App\Mail\ReservationApprovalRequestedMail;
use App\Mail\ReservationDecidedMail;
use App\Models\Reservation;
use App\Models\User;
use Illuminate\Support\Facades\Log;

/**
 * E-mails around the approval workflow (REZ-053, REZ-057). Same contract as
 * AdminNotifier: failures are logged, never thrown — a broken mail server
 * must not undo the booking or the decision that triggered the notice.
 */
class ReservationNotifier
{
    public function __construct(private readonly NotificationMailer $mailer) {}

    /**
     * Tell every active approver who is still a confirmed member that a
     * request is waiting; the club address when nobody is listed. One
     * mailable per recipient — a Mailable's `to` list accumulates, so
     * sharing an instance would leak approver A's address into approver
     * B's copy.
     */
    public function approvalRequested(Reservation $reservation): void
    {
        try {
            $resource = $reservation->resource;
            $mail = fn (bool $personal = true) => new ReservationApprovalRequestedMail(
                resourceLabel: $resource->label(),
                range: $reservation->rangeLabel(),
                customerName: $reservation->customerName,
                customerContact: $reservation->customerContact,
                note: $reservation->note,
                approvalsUrl: rtrim((string) config('app.url'), '/').'/approvals',
                personal: $personal,
            );

            $approvers = $resource->approvers()
                ->where('users.isActive', true)
                ->whereIn('users.role', [UserRole::MEMBER->value, UserRole::ADMIN->value])
                ->get();
            if ($approvers->isEmpty()) {
                $to = (string) config('mail.admin_address');
                if ($to !== '') {
                    $this->mailer->send(MailNotification::RESERVATION_APPROVAL_REQUESTED, $to, $mail(false));
                }

                return;
            }

            foreach ($approvers as $approver) {
                $this->mailer->sendToUser(MailNotification::RESERVATION_APPROVAL_REQUESTED, $approver, $mail());
            }
        } catch (\Throwable $e) {
            Log::warning('Approval-requested notice failed for reservation '.$reservation->id.': '.$e->getMessage());
        }
    }

    /** Tell the account that made the booking how it was decided. */
    public function decided(Reservation $reservation): void
    {
        try {
            $creator = $reservation->creator;
            if (!$creator instanceof User) {
                return;
            }

            $this->mailer->sendToUser(
                MailNotification::RESERVATION_DECIDED,
                $creator,
                new ReservationDecidedMail(
                    approved: $reservation->status === ReservationStatus::CONFIRMED,
                    name: $creator->name,
                    resourceLabel: $reservation->resource->label(),
                    range: $reservation->rangeLabel(),
                    decisionNote: $reservation->decisionNote,
                    reservationsUrl: rtrim((string) config('app.url'), '/').'/reservations?mine=1',
                ),
            );
        } catch (\Throwable $e) {
            Log::warning('Decision notice failed for reservation '.$reservation->id.': '.$e->getMessage());
        }
    }
}
