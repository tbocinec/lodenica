<?php

namespace App\Services;

use App\Domain\Enums\MailNotification;

/**
 * Reads and writes the per-notification on/off switches, stored as a JSON
 * blob under a single `settings` row.
 *
 * Missing or unparseable data reads as "everything on" — a settings row
 * that never got seeded, or one an operator mangled by hand, must not
 * silently stop the club's e-mail. Writes go through SettingsService so
 * the change lands in the audit log like every other setting.
 */
class MailNotificationSettings
{
    public const SETTING_KEY = 'mail_notifications';

    public function __construct(private readonly SettingsService $settings) {}

    /** @return array<string, bool> keyed by MailNotification value */
    public function all(): array
    {
        $decoded = json_decode((string) $this->settings->get(self::SETTING_KEY, ''), true);
        $stored = is_array($decoded) ? $decoded : [];

        $result = [];
        foreach (MailNotification::cases() as $type) {
            // Default ON: only an explicit `false` disables a notification.
            $result[$type->value] = !array_key_exists($type->value, $stored)
                || (bool) $stored[$type->value];
        }

        return $result;
    }

    public function isEnabled(MailNotification $type): bool
    {
        return $this->all()[$type->value];
    }

    /**
     * Partial update — keys absent from $changes keep their current value.
     *
     * @param  array<string, bool>  $changes
     * @return array<string, bool>  the full state after the write
     */
    public function update(array $changes): array
    {
        $next = $this->all();
        foreach ($changes as $key => $enabled) {
            if (array_key_exists($key, $next)) {
                $next[$key] = (bool) $enabled;
            }
        }

        $this->settings->set(self::SETTING_KEY, json_encode($next, JSON_THROW_ON_ERROR));

        return $next;
    }
}
