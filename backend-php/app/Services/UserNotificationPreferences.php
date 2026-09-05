<?php

namespace App\Services;

use App\Domain\Enums\AuditEntityType;
use App\Domain\Enums\MailNotification;
use App\Models\User;

/**
 * A member's own e-mail switches (REZ-062), stored as JSON in
 * `users.notificationPrefs`. Only user-configurable notifications
 * ({@see MailNotification::isUserConfigurable}) are exposed; a missing key
 * reads as ON, mirroring MailNotificationSettings for the admin switches.
 *
 * The admin switch is consulted separately in NotificationMailer — a user
 * preference can only ever turn an e-mail OFF, never back on.
 */
class UserNotificationPreferences
{
    public function __construct(private readonly AuditLogger $audit) {}

    /** @return array<string, bool> keyed by MailNotification value, configurable types only */
    public function all(User $user): array
    {
        $stored = is_array($user->notificationPrefs) ? $user->notificationPrefs : [];

        $result = [];
        foreach (self::configurable() as $type) {
            $result[$type->value] = !array_key_exists($type->value, $stored)
                || (bool) $stored[$type->value];
        }

        return $result;
    }

    public function wants(User $user, MailNotification $type): bool
    {
        if (!$type->isUserConfigurable()) {
            return true;
        }

        return $this->all($user)[$type->value];
    }

    /**
     * Partial update — keys absent from $changes keep their value, keys that
     * are not user-configurable are ignored (the controller rejects them up
     * front with a validation error).
     *
     * @param  array<string, bool>  $changes
     * @return array<string, bool>  the full state after the write
     */
    public function update(User $user, array $changes): array
    {
        $before = $this->all($user);
        $next = $before;
        foreach ($changes as $key => $enabled) {
            if (array_key_exists($key, $next)) {
                $next[$key] = (bool) $enabled;
            }
        }

        if ($next === $before) {
            return $next;
        }

        $user->notificationPrefs = $next;
        $user->save();

        $this->audit->logUpdate(
            AuditEntityType::USER,
            $user,
            "Upravené e-mailové notifikácie používateľa „{$user->name}“",
            ['notificationPrefs' => $before],
            ['notificationPrefs' => $next],
        );

        return $next;
    }

    /** @return list<MailNotification> */
    public static function configurable(): array
    {
        return array_values(array_filter(
            MailNotification::cases(),
            fn (MailNotification $t) => $t->isUserConfigurable(),
        ));
    }
}
