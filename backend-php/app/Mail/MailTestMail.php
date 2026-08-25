<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class MailTestMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $triggeredBy,
        public readonly string $sentAt,
        public readonly string $appUrl,
    ) {}

    public function build(): self
    {
        return $this
            ->subject('Testovací e-mail — Lodenica KVŠ')
            ->view('emails.test', [
                'triggeredBy' => $this->triggeredBy,
                'sentAt' => $this->sentAt,
                'appUrl' => $this->appUrl,
            ]);
    }
}
