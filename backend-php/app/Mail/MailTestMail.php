<?php

namespace App\Mail;

use App\Services\SiteConfig;
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
        $siteName = app(SiteConfig::class)->siteName();
        // Bypasses NotificationMailer on purpose, so set the from-name here.
        $from = (string) config('mail.from.address');
        if ($from !== '') {
            $this->from($from, $siteName);
        }

        return $this
            ->subject("Testovací e-mail — {$siteName}")
            ->view('emails.test', [
                'triggeredBy' => $this->triggeredBy,
                'sentAt' => $this->sentAt,
                'appUrl' => $this->appUrl,
            ]);
    }
}
