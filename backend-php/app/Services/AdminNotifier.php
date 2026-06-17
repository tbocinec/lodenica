<?php

namespace App\Services;

use App\Mail\PendingMemberNotificationMail;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Operational notifications to the club's admin address. Failures are
 * logged, never thrown — a flaky mail server must not break the user-facing
 * action that triggered the notice (registration, OAuth sign-up).
 */
class AdminNotifier
{
    public function pendingMemberAwaitingApproval(User $user): void
    {
        try {
            $to = (string) config('mail.admin_address');
            if ($to === '') {
                return;
            }
            $adminUrl = rtrim((string) config('app.url'), '/').'/admin/users';
            Mail::to($to)->send(
                new PendingMemberNotificationMail($user->name, $user->email, $adminUrl),
            );
        } catch (\Throwable $e) {
            Log::warning('Pending-member admin notice failed for '.$user->email.': '.$e->getMessage());
        }
    }
}
