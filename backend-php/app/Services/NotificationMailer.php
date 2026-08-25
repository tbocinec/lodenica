<?php

namespace App\Services;

use App\Domain\Enums\MailNotification;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * The single choke point for transactional e-mail. Every notification the
 * app sends goes through here so the admin on/off switches are consulted
 * in exactly one place.
 *
 * Deliberately NOT used by the admin diagnostics test send — that one must
 * reach the transport even when every notification is switched off, since
 * its whole purpose is to prove whether the transport works.
 */
class NotificationMailer
{
    public function __construct(private readonly MailNotificationSettings $settings) {}

    /**
     * @return bool true when handed to the mailer, false when the
     *              notification is switched off
     */
    public function send(MailNotification $type, string $to, Mailable $mail): bool
    {
        if (!$this->settings->isEnabled($type)) {
            Log::info("E-mail „{$type->label()}“ preskočený (vypnutý správcom) — príjemca {$to}");

            return false;
        }

        Mail::to($to)->send($mail);

        return true;
    }
}
