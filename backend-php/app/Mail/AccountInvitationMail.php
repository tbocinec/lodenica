<?php

namespace App\Mail;

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
        public readonly int $expiresHours,
    ) {}

    public function build(): self
    {
        return $this
            ->subject('Boli ste pridaný do systému — Lodenica KVŠ')
            ->view('emails.account-invitation', [
                'email' => $this->email,
                'name' => $this->name,
                'setupUrl' => $this->setupUrl,
                'expiresHours' => $this->expiresHours,
            ]);
    }
}
