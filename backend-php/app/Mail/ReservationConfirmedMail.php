<?php

namespace App\Mail;

use App\Services\SiteConfig;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

/**
 * Sent to the signed-in account that made a booking which was confirmed
 * right away (REZ-064). Opt-in per user. Carries the calendar links and
 * the .ics as an attachment so one tap adds it to a phone calendar.
 */
class ReservationConfirmedMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly ?string $name,
        public readonly string $customerName,
        public readonly string $resourceLabel,
        public readonly string $range,
        public readonly ?string $note,
        public readonly string $googleCalendarUrl,
        public readonly string $icsUrl,
        public readonly string $reservationsUrl,
        public readonly string $icsBody,
        public readonly string $icsFilename,
    ) {}

    public function build(): self
    {
        $siteName = app(SiteConfig::class)->siteName();

        return $this
            ->subject("Rezervácia vytvorená: {$this->resourceLabel} — {$siteName}")
            ->view('emails.reservation-confirmed', [
                'name' => $this->name,
                'customerName' => $this->customerName,
                'resourceLabel' => $this->resourceLabel,
                'range' => $this->range,
                'note' => $this->note,
                'googleCalendarUrl' => $this->googleCalendarUrl,
                'icsUrl' => $this->icsUrl,
                'reservationsUrl' => $this->reservationsUrl,
            ])
            ->attachData($this->icsBody, $this->icsFilename, ['mime' => 'text/calendar; charset=utf-8']);
    }
}
