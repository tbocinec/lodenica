<?php

namespace App\Services;

use Illuminate\Support\Facades\Storage;

/**
 * The club-specific values of this installation: name, contacts, links,
 * feature switches, default theme, logo. One codebase serves several
 * clubs, so everything that differs between them lives here and never as
 * a literal in code (SITE-004).
 *
 * Resolution per field (SITE-001): value stored by an admin in the
 * `site_config` settings row → config('site.*') from .env → built-in
 * default. {@see update()} merges partially; a `null` (or '') removes the
 * stored key so the default applies again.
 *
 * See docs/spec/13-site-configuration.md.
 */
final class SiteConfig
{
    public const SETTING_KEY = 'site_config';
    public const LOGO_SETTING_KEY = 'site_logo_path';
    public const LOGO_DIR = 'site';
    public const LOGO_EXTENSIONS = ['png', 'jpg', 'jpeg', 'webp'];

    /**
     * field => [config key, built-in default]. A null default is derived
     * from another field (see {@see get()}).
     */
    private const FIELDS = [
        'siteName' => ['site.name', 'Lodenica'],
        'shortName' => ['site.short_name', null],
        'clubName' => ['site.club_name', ''],
        'contactEmail' => ['site.contact_email', null],
        'adminEmail' => ['site.admin_email', null],
        'address' => ['site.address', ''],
        'mapsUrl' => ['site.maps_url', null],
        'websiteUrl' => ['site.website_url', null],
        'rulesUrl' => ['site.rules_url', null],
        'gdprNoticeUrl' => ['site.gdpr_notice_url', null],
        'gdprConsentUrl' => ['site.gdpr_consent_url', null],
        'statutesUrl' => ['site.statutes_url', null],
        'operatorNotice' => ['site.operator_notice', ''],
        'memberIdExample' => ['site.member_id_example', '001'],
        'theme' => ['site.theme', 'ocean'],
    ];

    /** feature => [config key, built-in default] */
    private const FEATURES = [
        'paddlingTrafficLight' => ['site.features.paddling_traffic_light', false],
        'expeditions' => ['site.features.expeditions', true],
    ];

    public function __construct(private readonly SettingsService $settings) {}

    /** @return list<string> */
    public static function fieldNames(): array
    {
        return array_keys(self::FIELDS);
    }

    /** @return list<string> */
    public static function featureNames(): array
    {
        return array_keys(self::FEATURES);
    }

    public function get(string $field): mixed
    {
        if (!array_key_exists($field, self::FIELDS)) {
            throw new \InvalidArgumentException("Unknown site field '{$field}'.");
        }

        $stored = $this->stored();
        if (array_key_exists($field, $stored) && $stored[$field] !== null && $stored[$field] !== '') {
            return $stored[$field];
        }

        [$configKey, $default] = self::FIELDS[$field];
        $fromEnv = config($configKey);
        if ($fromEnv !== null && $fromEnv !== '') {
            return $fromEnv;
        }

        // Derived defaults.
        return match ($field) {
            'shortName' => $this->get('siteName'),
            'adminEmail' => $this->get('contactEmail'),
            default => $default,
        };
    }

    public function feature(string $name): bool
    {
        if (!array_key_exists($name, self::FEATURES)) {
            throw new \InvalidArgumentException("Unknown site feature '{$name}'.");
        }

        $stored = $this->stored()['features'] ?? null;
        if (is_array($stored) && array_key_exists($name, $stored) && $stored[$name] !== null) {
            return (bool) $stored[$name];
        }

        [$configKey, $default] = self::FEATURES[$name];
        $fromEnv = config($configKey);
        if ($fromEnv === null || $fromEnv === '') {
            return $default;
        }

        return filter_var($fromEnv, FILTER_VALIDATE_BOOLEAN);
    }

    public function siteName(): string
    {
        return (string) $this->get('siteName');
    }

    public function shortName(): string
    {
        return (string) $this->get('shortName');
    }

    public function contactEmail(): ?string
    {
        return $this->get('contactEmail');
    }

    public function adminEmail(): ?string
    {
        return $this->get('adminEmail');
    }

    public function theme(): string
    {
        return (string) $this->get('theme');
    }

    /**
     * Everything resolved, adminEmail included. For admins.
     *
     * @return array<string, mixed>
     */
    public function all(): array
    {
        $out = [];
        foreach (self::fieldNames() as $field) {
            $out[$field] = $this->get($field);
        }
        $out['features'] = [];
        foreach (self::featureNames() as $name) {
            $out['features'][$name] = $this->feature($name);
        }
        $out['logoUrl'] = $this->logoUrl();

        return $out;
    }

    /**
     * What anyone may read (SITE-002: no adminEmail).
     *
     * @return array<string, mixed>
     */
    public function publicPayload(): array
    {
        $all = $this->all();
        unset($all['adminEmail']);

        return $all;
    }

    /**
     * Partial update. Only keys present in $changes are touched; a null or
     * empty string removes the stored key so the default applies again.
     * `features` merges per switch. Unknown keys are ignored (the
     * FormRequest rejects them before they get here).
     *
     * @param  array<string, mixed>  $changes
     * @return array<string, mixed>  the resolved config after the write
     */
    public function update(array $changes): array
    {
        $stored = $this->stored();

        foreach ($changes as $key => $value) {
            if ($key === 'features') {
                $features = is_array($stored['features'] ?? null) ? $stored['features'] : [];
                foreach ((array) $value as $name => $enabled) {
                    if (!array_key_exists($name, self::FEATURES)) {
                        continue;
                    }
                    if ($enabled === null) {
                        unset($features[$name]);
                    } else {
                        $features[$name] = (bool) $enabled;
                    }
                }
                if ($features === []) {
                    unset($stored['features']);
                } else {
                    $stored['features'] = $features;
                }
                continue;
            }

            if (!array_key_exists($key, self::FIELDS)) {
                continue;
            }
            if ($value === null || $value === '') {
                unset($stored[$key]);
            } else {
                $stored[$key] = is_string($value) ? trim($value) : $value;
            }
        }

        $this->settings->set(
            self::SETTING_KEY,
            json_encode($stored, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
        );

        return $this->all();
    }

    /**
     * Path of the logo on the `local` disk, or null. A file dropped at
     * `site/logo.<ext>` at deploy time counts even without a DB row, so a
     * client's logo can ship with the first install.
     */
    public function logoPath(): ?string
    {
        $stored = $this->settings->get(self::LOGO_SETTING_KEY);
        if ($stored !== null && $stored !== '' && Storage::disk('local')->exists($stored)) {
            return $stored;
        }
        foreach (self::LOGO_EXTENSIONS as $ext) {
            $candidate = self::LOGO_DIR.'/logo.'.$ext;
            if (Storage::disk('local')->exists($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    /** Absolute URL of the logo endpoint with a cache-busting version, or null. */
    public function logoUrl(): ?string
    {
        $path = $this->logoPath();
        if ($path === null) {
            return null;
        }

        return url('/api/v1/site/logo').'?v='.Storage::disk('local')->lastModified($path);
    }

    public function setLogoPath(?string $path): void
    {
        $this->settings->set(self::LOGO_SETTING_KEY, $path);
    }

    /** @return array<string, mixed> */
    private function stored(): array
    {
        $decoded = json_decode((string) $this->settings->get(self::SETTING_KEY, ''), true);

        return is_array($decoded) ? $decoded : [];
    }
}
