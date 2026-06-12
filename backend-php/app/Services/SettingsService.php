<?php

namespace App\Services;

use App\Domain\Enums\AuditAction;
use App\Domain\Enums\AuditEntityType;
use App\Models\Setting;

/**
 * Single-row-per-key settings. Get-or-default for reads (so a freshly
 * migrated DB without a seeded row still answers something sensible);
 * upsert + audit on writes.
 */
class SettingsService
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function get(string $key, ?string $default = null): ?string
    {
        $row = Setting::find($key);

        return $row?->value ?? $default;
    }

    public function set(string $key, ?string $value): Setting
    {
        $before = Setting::find($key);
        $beforeValue = $before?->value;

        // Defence-in-depth: strip <script> blocks before persisting.
        // Admins are trusted (RBAC enforces it), but a compromised admin
        // session shouldn't trivially XSS other admins viewing the page.
        // Keep this minimal — proper HTMLPurifier integration is overkill
        // for the current attack surface.
        if ($value !== null) {
            $value = preg_replace('#<script\b[^>]*>.*?</script>#is', '', $value) ?? $value;
            $value = preg_replace('#\son\w+\s*=\s*"[^"]*"#i', '', $value) ?? $value;
            $value = preg_replace("#\son\w+\s*=\s*'[^']*'#i", '', $value) ?? $value;
        }

        $row = Setting::updateOrCreate(
            ['key' => $key],
            ['value' => $value, 'updatedAt' => now()],
        );

        // Only log if the value actually changed.
        if ($beforeValue !== $value) {
            $this->audit->logAction(
                AuditEntityType::SETTING,
                $key,
                AuditAction::UPDATE,
                "Upravené nastavenie „{$key}“",
                [
                    'before' => ['value' => $beforeValue],
                    'after' => ['value' => $value],
                ],
            );
        }

        return $row;
    }
}
