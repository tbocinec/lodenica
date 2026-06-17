<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class PasswordResetMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $email,
        public readonly string $resetUrl,
        public readonly int $expiresMinutes,
    ) {}

    public function build(): self
    {
        return $this
            ->subject('Obnova hesla — Lodenica KVŠ')
            ->view('emails.password-reset', [
                'email' => $this->email,
                'resetUrl' => $this->resetUrl,
                'expiresMinutes' => $this->expiresMinutes,
            ]);
    }
}
