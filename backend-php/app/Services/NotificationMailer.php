<?php

namespace App\Services;

use App\Domain\Enums\MailNotification;
use App\Models\User;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * The single choke point for transactional e-mail. Every notification the
 * app sends goes through here so the admin on/off switches are consulted
 * in exactly one place. `send()` checks the admin switch; `sendToUser()`
 * additionally checks the recipient's own preference.
 *
 * Deliberately NOT used by the admin diagnostics test send — that one must
 * reach the transport even when every notification is switched off, since
 * its whole purpose is to prove whether the transport works.
 */
class NotificationMailer
{
    public function __construct(
        private readonly MailNotificationSettings $settings,
        private readonly UserNotificationPreferences $preferences,
    ) {}

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

    /**
     * Like {@see send()}, but for a recipient who has an account: also
     * honours their own preference for notifications they may switch off
     * (REZ-062). Both switches are checked here so no caller has to remember
     * the second one.
     */
    public function sendToUser(MailNotification $type, User $user, Mailable $mail): bool
    {
        if (!$this->preferences->wants($user, $type)) {
            Log::info("E-mail „{$type->label()}“ preskočený (vypnutý používateľom) — príjemca {$user->email}");

            return false;
        }

        return $this->send($type, $user->email, $mail);
    }
}
