<?php

namespace App\Mail;

use App\Services\SiteConfig;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class MembershipApprovedMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly ?string $name,
        public readonly string $loginUrl,
    ) {}

    public function build(): self
    {
        $siteName = app(SiteConfig::class)->siteName();

        return $this
            ->subject("Vaše členstvo bolo schválené — {$siteName}")
            ->view('emails.membership-approved', [
                'name' => $this->name,
                'loginUrl' => $this->loginUrl,
            ]);
    }
}
