<?php

namespace App\Mail;

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
        return $this
            ->subject('Vaše členstvo bolo schválené — Lodenica KVŠ')
            ->view('emails.membership-approved', [
                'name' => $this->name,
                'loginUrl' => $this->loginUrl,
            ]);
    }
}
