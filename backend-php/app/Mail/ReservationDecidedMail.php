<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

/** Sent to the account that made a booking once an approver decided (REZ-057). */
class ReservationDecidedMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly bool $approved,
        public readonly ?string $name,
        public readonly string $resourceLabel,
        public readonly string $range,
        public readonly ?string $decisionNote,
        public readonly string $reservationsUrl,
    ) {}

    public function build(): self
    {
        $outcome = $this->approved ? 'schválená' : 'zamietnutá';

        return $this
            ->subject("Rezervácia {$outcome}: {$this->resourceLabel} — Lodenica KVŠ")
            ->view('emails.reservation-decided', [
                'approved' => $this->approved,
                'name' => $this->name,
                'resourceLabel' => $this->resourceLabel,
                'range' => $this->range,
                'decisionNote' => $this->decisionNote,
                'reservationsUrl' => $this->reservationsUrl,
            ]);
    }
}
