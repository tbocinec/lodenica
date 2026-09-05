<?php

namespace App\Mail;

use App\Services\SiteConfig;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class AccountInvitationMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $email,
        public readonly ?string $name,
        public readonly string $setupUrl,
        public readonly int $expiresDays,
    ) {}

    public function build(): self
    {
        $siteName = app(SiteConfig::class)->siteName();

        return $this
            ->subject("Boli ste pridaný do systému — {$siteName}")
            ->view('emails.account-invitation', [
                'email' => $this->email,
                'name' => $this->name,
                'setupUrl' => $this->setupUrl,
                'expiresDays' => $this->expiresDays,
            ]);
    }
}
