<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

/**
 * Sent to each approver of a resource (or the club address when none is
 * listed) when somebody requests a booking of it (REZ-053).
 */
class ReservationApprovalRequestedMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $resourceLabel,
        public readonly string $range,
        public readonly string $customerName,
        public readonly ?string $customerContact,
        public readonly ?string $note,
        public readonly string $approvalsUrl,
        public readonly bool $personal = true,
    ) {}

    public function build(): self
    {
        return $this
            ->subject("Rezervácia čaká na schválenie: {$this->resourceLabel} — Lodenica KVŠ")
            ->view('emails.reservation-approval-requested', [
                'resourceLabel' => $this->resourceLabel,
                'range' => $this->range,
                'customerName' => $this->customerName,
                'customerContact' => $this->customerContact,
                'note' => $this->note,
                'approvalsUrl' => $this->approvalsUrl,
                'personal' => $this->personal,
            ]);
    }
}
