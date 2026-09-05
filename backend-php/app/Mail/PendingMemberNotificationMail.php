<?php

namespace App\Mail;

use App\Services\SiteConfig;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

/**
 * Sent to the club's admin address when a new account self-registers (or
 * signs in via OAuth for the first time) and lands as PENDING — so an admin
 * knows someone is waiting for approval.
 */
class PendingMemberNotificationMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $memberName,
        public readonly string $memberEmail,
        public readonly string $adminUrl,
    ) {}

    public function build(): self
    {
        $siteName = app(SiteConfig::class)->siteName();

        return $this
            ->subject("Nový člen čaká na schválenie — {$siteName}")
            ->view('emails.pending-member', [
                'memberName' => $this->memberName,
                'memberEmail' => $this->memberEmail,
                'adminUrl' => $this->adminUrl,
            ]);
    }
}
