# Multi-client Site Configuration Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make every club-specific value an admin-editable site setting with install-time defaults, group the admin navigation, add per-user colour themes, and make a clean install per client one secrets file + one deploy run.

**Architecture:** A `SiteConfig` service resolves each field from a `site_config` JSON settings row → `config('site.*')` (env) → built-in default; a public `GET /site` feeds a Pinia `site` store the SPA reads everywhere the club identity used to be hard-coded. Tailwind brand colours become CSS variables driven by a theme table; the user's theme lives in `users.theme`. Seeding splits into `AdminSeeder` / `ContentSeeder` / `DemoDataSeeder`, and the deploy script reads all branding from the per-client secrets file.

**Tech Stack:** Laravel 13 (PHP 8.3, Eloquent, Sanctum, Blade mail, PHPUnit on SQLite), Vue 3 + TypeScript + Pinia + Vue Router + Tailwind 3, Vitest + @vue/test-utils, pnpm, bash + lftp deploy.

**Spec:** `docs/superpowers/specs/2026-09-05-multi-client-site-config-design.md` — read it first; tasks cite its sections.

## Global Constraints

- Migrations are **additive only** (new nullable columns/tables). Existing seed migrations may be turned into no-ops because they are guarded and already applied everywhere; never `migrate:fresh` on prod.
- Columns are `camelCase` (CORE-011); UUID PKs (CORE-010).
- Every user-facing string is **Slovak**; code, comments, commit messages English.
- API errors: validation → 400 `VALIDATION_ERROR` (CORE-023); 401/403 per CORE-025.
- Public routes resolve the caller with `$request->user('sanctum')` (CORE-031) — not needed here, `/site` has no PII.
- Commit style: `<type>(<scope>): summary` (lowercase, ≤72 chars) + trailer `Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>`. Stage files explicitly. `docs/spec/` is the owner's untracked WIP — stage only `docs/spec/13-site-configuration.md` from it.
- Run backend commands from `backend-php/` (`vendor/bin/phpunit`), frontend from `frontend/` (`pnpm test`, `pnpm typecheck`). Never composer/npm at repo root.
- `grep` is aliased to ugrep in this shell — pass patterns with `-e`.
- Baseline: backend 370 tests (365 pass, 5 skipped); frontend 49 pass; typecheck clean. Both stay green after every task.

---

## File map

**Backend (`backend-php/`)**

| File | Responsibility |
|---|---|
| `config/site.php` | env defaults for every site field, admin bootstrap creds, demo flag (new) |
| `app/Services/SiteConfig.php` | layered resolution, update, logo path/url (new) |
| `app/Http/Requests/UpdateSiteConfigRequest.php` | PATCH validation, unknown-key rejection (new) |
| `app/Http/Controllers/Api/SiteController.php` | public show/logo, admin show/update/logo upload/remove (new) |
| `app/Http/Middleware/EnsureFeature.php` | 404 when a feature is off (new) |
| `bootstrap/app.php` | `feature` middleware alias |
| `routes/api.php` | new routes + feature gates |
| `app/Services/NotificationMailer.php`, `app/Mail/*.php`, `resources/views/emails/layout.blade.php`, `app/Providers/AppServiceProvider.php` | site name in subjects, from-name, layout |
| `app/Services/AdminNotifier.php`, `app/Http/Controllers/Api/MailDiagnosticsController.php`, `config/mail.php` | admin e-mail from SiteConfig |
| `app/Http/Controllers/Api/ReservationsController.php` | ICS from config |
| `app/Http/Controllers/Api/AdminDataController.php` | neutral export file names |
| `database/migrations/2026_09_06_000000_add_theme_to_users.php` | `users.theme` (new) |
| `app/Http/Controllers/Api/ProfileController.php`, `app/Http/Resources/UserResource.php` | appearance endpoint + `theme` |
| `database/seeders/{AdminSeeder,ContentSeeder,DemoDataSeeder,DatabaseSeeder}.php`, `database/seeders/content/*.html` | seeding split |
| `database/migrations/2026_06_12_220000_create_settings_table.php`, `…_seed_privacy_policy_setting.php`, `…_seed_faq_setting.php` | content inserts removed |
| `tests/Feature/SiteConfigTest.php`, `tests/Feature/Api/SiteApiTest.php`, `tests/Feature/Api/FeatureGateTest.php`, `tests/Feature/MailBrandingTest.php`, `tests/Feature/Api/ProfileAppearanceApiTest.php`, `tests/Feature/SeedersTest.php`, `tests/Feature/DeployScriptIsClientNeutralTest.php` | new tests |

**Frontend (`frontend/src/`)**

| File | Responsibility |
|---|---|
| `api/site.api.ts` | types, defaults, client (new) |
| `stores/site.store.ts` | config + cache (new) |
| `theme/themes.ts` | palettes, `applyTheme`, cache (new) |
| `composables/useSiteChrome.ts` | theme sync, favicon, theme-color (new) |
| `components/layout/NavGroup.vue` | collapsible nav group with sub-groups (new) |
| `components/layout/AppShell.vue` | nav tree, header brand |
| `router/index.ts` | title, feature guard, `/admin/site` |
| `main.ts`, `App.vue` | bootstrap order, chrome composable |
| `views/AdminSiteSettingsView.vue` | settings form (new) |
| `views/ProfileView.vue`, `api/profile.api.ts`, `api/types.ts` | theme card, `theme` on User |
| `tailwind.config.js`, `styles/main.css`, `index.html`, `public/favicon.svg` | CSS variables, neutral chrome |
| many views/components | hard-coded strings → store (Task 11 table) |

**Ops / docs**: `scripts/deploy-rezervacie.sh`, `scripts/deploy-all.sh` (new), `.github/workflows/deploy-rezervacie.yml`, `.deploy-secrets.example`, `docker-compose.yml`, `docker/backend/entrypoint.sh`, `backend-php/.env.example`, `branding/kvs/*.png` (moved), `docs/CLIENT-ONBOARDING.md` (new), `AGENTS.md`, `README.md`, `docs/AUTH-AND-PERMISSIONS.md`, `docs/spec/13-site-configuration.md` (new).

---

## Part A — Backend

### Task 1: `config/site.php` + `SiteConfig` service

**Files:**
- Create: `backend-php/config/site.php`
- Create: `backend-php/app/Services/SiteConfig.php`
- Test: `backend-php/tests/Feature/SiteConfigTest.php`

**Interfaces (produces):**
```php
final class SiteConfig {
  public const SETTING_KEY = 'site_config';
  public const LOGO_SETTING_KEY = 'site_logo_path';
  public function __construct(SettingsService $settings);
  public function get(string $field): mixed;          // scalar fields, resolved
  public function feature(string $name): bool;        // 'paddlingTrafficLight' | 'expeditions'
  public function siteName(): string; public function shortName(): string;
  public function contactEmail(): ?string; public function adminEmail(): ?string; public function theme(): string;
  /** @return array<string,mixed> incl. adminEmail, features, logoUrl */ public function all(): array;
  /** @return array<string,mixed> all() without adminEmail */ public function publicPayload(): array;
  /** @param array<string,mixed> $changes  null removes a stored key */ public function update(array $changes): array;
  public function logoPath(): ?string; public function logoUrl(): ?string; public function setLogoPath(?string $path): void;
  /** @return list<string> */ public static function fieldNames(): array;
}
```

- [ ] **Step 1: Write the failing test**

```php
<?php
// backend-php/tests/Feature/SiteConfigTest.php
namespace Tests\Feature;

use App\Models\Setting;
use App\Services\SiteConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class SiteConfigTest extends TestCase
{
    use RefreshDatabase;

    private function site(): SiteConfig { return app(SiteConfig::class); }

    public function test_built_in_defaults_apply_when_nothing_is_configured(): void
    {
        config(['site.name' => null, 'site.short_name' => null, 'site.theme' => null]);
        $this->assertSame('Lodenica', $this->site()->siteName());
        $this->assertSame('Lodenica', $this->site()->shortName());
        $this->assertSame('ocean', $this->site()->theme());
        $this->assertNull($this->site()->contactEmail());
        $this->assertFalse($this->site()->feature('paddlingTrafficLight'));
        $this->assertTrue($this->site()->feature('expeditions'));
    }

    public function test_env_config_beats_built_in_default(): void
    {
        config(['site.name' => 'Klub Env', 'site.contact_email' => 'env@example.test', 'site.features.paddling_traffic_light' => true]);
        $this->assertSame('Klub Env', $this->site()->siteName());
        $this->assertSame('Klub Env', $this->site()->shortName(), 'shortName falls back to siteName');
        $this->assertSame('env@example.test', $this->site()->contactEmail());
        $this->assertTrue($this->site()->feature('paddlingTrafficLight'));
    }

    public function test_stored_value_beats_env_and_null_clears_it(): void
    {
        config(['site.name' => 'Klub Env']);
        $this->site()->update(['siteName' => 'Klub DB', 'features' => ['paddlingTrafficLight' => true]]);
        $this->assertSame('Klub DB', $this->site()->siteName());
        $this->assertTrue($this->site()->feature('paddlingTrafficLight'));

        $this->site()->update(['siteName' => null]);
        $this->assertSame('Klub Env', $this->site()->siteName());
        $this->assertTrue($this->site()->feature('paddlingTrafficLight'), 'untouched keys survive');
        $stored = json_decode(Setting::find(SiteConfig::SETTING_KEY)->value, true);
        $this->assertArrayNotHasKey('siteName', $stored);
    }

    public function test_empty_string_is_treated_as_clear(): void
    {
        $this->site()->update(['contactEmail' => 'a@example.test']);
        $this->site()->update(['contactEmail' => '']);
        $this->assertNull($this->site()->contactEmail());
    }

    public function test_admin_email_falls_back_to_contact_email(): void
    {
        config(['site.admin_email' => null]);
        $this->site()->update(['contactEmail' => 'klub@example.test']);
        $this->assertSame('klub@example.test', $this->site()->adminEmail());
        $this->site()->update(['adminEmail' => 'spravca@example.test']);
        $this->assertSame('spravca@example.test', $this->site()->adminEmail());
    }

    public function test_public_payload_hides_admin_email(): void
    {
        $this->site()->update(['adminEmail' => 'spravca@example.test']);
        $public = $this->site()->publicPayload();
        $this->assertArrayNotHasKey('adminEmail', $public);
        $this->assertArrayHasKey('adminEmail', $this->site()->all());
        $this->assertSame(['paddlingTrafficLight' => false, 'expeditions' => true], $public['features']);
        $this->assertNull($public['logoUrl']);
    }

    public function test_logo_path_falls_back_to_a_file_on_disk(): void
    {
        Storage::fake('local');
        $this->assertNull($this->site()->logoPath());
        Storage::disk('local')->put('site/logo.png', 'png');
        $this->assertSame('site/logo.png', $this->site()->logoPath());
        $this->assertStringContainsString('/api/v1/site/logo?v=', (string) $this->site()->logoUrl());

        $this->site()->setLogoPath('site/logo.webp');
        $this->assertSame('site/logo.webp', $this->site()->logoPath());
    }

    public function test_unknown_feature_is_a_programming_error(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->site()->feature('nope');
    }
}
```

- [ ] **Step 2: Run to verify it fails**

Run: `cd backend-php && vendor/bin/phpunit tests/Feature/SiteConfigTest.php`
Expected: errors "Class App\Services\SiteConfig not found".

- [ ] **Step 3: Create `config/site.php`**

```php
<?php

/*
|--------------------------------------------------------------------------
| Site (client) configuration — install-time defaults
|--------------------------------------------------------------------------
| Every value here is the DEFAULT for the matching field of the
| `site_config` settings row that an admin edits in the SPA (Administrácia →
| Systém → Nastavenia stránky). Resolution order, per field:
|   stored in settings  →  this file (from .env)  →  built-in default.
| See App\Services\SiteConfig. Written by scripts/deploy-rezervacie.sh from
| the per-client .deploy-secrets.<client> file.
*/

return [
    'name' => env('SITE_NAME'),
    'short_name' => env('SITE_SHORT_NAME'),
    'club_name' => env('SITE_CLUB_NAME'),
    'contact_email' => env('SITE_CONTACT_EMAIL'),
    // Where "new member waiting" notices go. Falls back to contact_email.
    'admin_email' => env('MAIL_ADMIN_ADDRESS'),
    'address' => env('SITE_ADDRESS'),
    'maps_url' => env('SITE_MAPS_URL'),
    'website_url' => env('SITE_WEBSITE_URL'),
    'rules_url' => env('SITE_RULES_URL'),
    'gdpr_notice_url' => env('SITE_GDPR_NOTICE_URL'),
    'gdpr_consent_url' => env('SITE_GDPR_CONSENT_URL'),
    'statutes_url' => env('SITE_STATUTES_URL'),
    'operator_notice' => env('SITE_OPERATOR_NOTICE'),
    'member_id_example' => env('SITE_MEMBER_ID_EXAMPLE'),
    'theme' => env('SITE_THEME'),

    'features' => [
        'paddling_traffic_light' => env('SITE_FEATURE_TRAFFIC_LIGHT'),
        'expeditions' => env('SITE_FEATURE_EXPEDITIONS'),
    ],

    // First-install admin account (database/seeders/AdminSeeder.php).
    'admin' => [
        'email' => env('ADMIN_EMAIL'),
        'password' => env('ADMIN_PASSWORD'),
        'name' => env('ADMIN_NAME'),
    ],

    // Demo boats/reservations outside `local` only when explicitly asked.
    'seed_demo_data' => (bool) env('SEED_DEMO_DATA', false),
];
```

- [ ] **Step 4: Create `app/Services/SiteConfig.php`**

```php
<?php

namespace App\Services;

use Illuminate\Support\Facades\Storage;

/**
 * The club-specific values of this installation (name, contacts, links,
 * feature switches, default theme, logo). One codebase serves several
 * clubs; everything that differs between them lives here, never as a
 * literal in code (SITE-004).
 *
 * Resolution per field (SITE-001): value stored by an admin in the
 * `site_config` settings row → config('site.*') from .env → built-in
 * default. `update()` merges partially; a `null` (or '') removes the stored
 * key so the default applies again.
 */
final class SiteConfig
{
    public const SETTING_KEY = 'site_config';
    public const LOGO_SETTING_KEY = 'site_logo_path';
    public const LOGO_DIR = 'site';
    public const LOGO_EXTENSIONS = ['png', 'jpg', 'jpeg', 'webp'];

    /** field => [config key, built-in default]. null default = derived (see resolve()). */
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

        $stored = $this->stored()['features'] ?? [];
        if (is_array($stored) && array_key_exists($name, $stored) && $stored[$name] !== null) {
            return (bool) $stored[$name];
        }

        [$configKey, $default] = self::FEATURES[$name];
        $fromEnv = config($configKey);

        return $fromEnv === null || $fromEnv === '' ? $default : filter_var($fromEnv, FILTER_VALIDATE_BOOLEAN);
    }

    public function siteName(): string { return (string) $this->get('siteName'); }
    public function shortName(): string { return (string) $this->get('shortName'); }
    public function contactEmail(): ?string { return $this->get('contactEmail'); }
    public function adminEmail(): ?string { return $this->get('adminEmail'); }
    public function theme(): string { return (string) $this->get('theme'); }

    /** @return array<string, mixed> */
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

    /** @return array<string, mixed> */
    public function publicPayload(): array
    {
        $all = $this->all();
        unset($all['adminEmail']);

        return $all;
    }

    /**
     * Partial update. Only keys present in $changes are touched; a null or
     * empty string removes the stored key. `features` merges per switch.
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
                $stored['features'] = $features;
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

        if (($stored['features'] ?? null) === []) {
            unset($stored['features']);
        }

        $this->settings->set(self::SETTING_KEY, json_encode($stored, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));

        return $this->all();
    }

    /** Path on the `local` disk, or null. A file placed at deploy time counts. */
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

    public function logoUrl(): ?string
    {
        $path = $this->logoPath();
        if ($path === null) {
            return null;
        }
        $version = Storage::disk('local')->lastModified($path);

        return url('/api/v1/site/logo').'?v='.$version;
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
```

- [ ] **Step 5: Run the test**

Run: `cd backend-php && vendor/bin/phpunit tests/Feature/SiteConfigTest.php`
Expected: 8 tests pass. (`SettingsService::set` strips `<script>` from values — JSON is unaffected.)

- [ ] **Step 6: Commit**

```bash
git add backend-php/config/site.php backend-php/app/Services/SiteConfig.php backend-php/tests/Feature/SiteConfigTest.php
git commit -m "feat(site): SiteConfig service with layered defaults for club-specific values"
```

---

### Task 2: Site API — public payload, admin update, logo

**Files:**
- Create: `backend-php/app/Http/Requests/UpdateSiteConfigRequest.php`
- Create: `backend-php/app/Http/Controllers/Api/SiteController.php`
- Modify: `backend-php/routes/api.php`
- Test: `backend-php/tests/Feature/Api/SiteApiTest.php`

**Interfaces:** consumes `SiteConfig` (Task 1). Produces routes `GET /site`, `GET /site/logo`, `GET|PATCH /admin/site`, `POST|DELETE /admin/site/logo`.

- [ ] **Step 1: Write the failing test**

```php
<?php
// backend-php/tests/Feature/Api/SiteApiTest.php
namespace Tests\Feature\Api;

use App\Services\SiteConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class SiteApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_payload_has_branding_without_admin_email(): void
    {
        app(SiteConfig::class)->update(['siteName' => 'Klub Test', 'adminEmail' => 'spravca@example.test']);

        $this->getJson('/api/v1/site')
            ->assertOk()
            ->assertJsonPath('siteName', 'Klub Test')
            ->assertJsonPath('features.expeditions', true)
            ->assertJsonPath('logoUrl', null)
            ->assertJsonMissingPath('adminEmail');
    }

    public function test_admin_endpoints_are_admin_only(): void
    {
        $this->getJson('/api/v1/admin/site')->assertStatus(401);
        $this->patchJson('/api/v1/admin/site', ['siteName' => 'x'])->assertStatus(401);

        $this->actingAsMember();
        $this->getJson('/api/v1/admin/site')->assertStatus(403);
        $this->patchJson('/api/v1/admin/site', ['siteName' => 'x'])->assertStatus(403);
        $this->postJson('/api/v1/admin/site/logo')->assertStatus(403);
        $this->deleteJson('/api/v1/admin/site/logo')->assertStatus(403);
    }

    public function test_admin_reads_full_config_and_patches_partially(): void
    {
        $this->actingAsAdmin();
        $this->getJson('/api/v1/admin/site')->assertOk()->assertJsonPath('adminEmail', null);

        $this->patchJson('/api/v1/admin/site', [
            'siteName' => 'Klub Test',
            'contactEmail' => 'klub@example.test',
            'features' => ['paddlingTrafficLight' => true],
        ])->assertOk()
            ->assertJsonPath('siteName', 'Klub Test')
            ->assertJsonPath('shortName', 'Klub Test')
            ->assertJsonPath('adminEmail', 'klub@example.test')
            ->assertJsonPath('features.paddlingTrafficLight', true);

        // Second partial patch leaves the rest alone; null clears.
        $this->patchJson('/api/v1/admin/site', ['contactEmail' => null, 'theme' => 'forest'])
            ->assertOk()
            ->assertJsonPath('siteName', 'Klub Test')
            ->assertJsonPath('contactEmail', null)
            ->assertJsonPath('theme', 'forest');
    }

    public function test_patch_rejects_invalid_values_and_unknown_keys(): void
    {
        $this->actingAsAdmin();
        $this->patchJson('/api/v1/admin/site', ['contactEmail' => 'not-an-email'])
            ->assertStatus(400)->assertJsonPath('code', 'VALIDATION_ERROR');
        $this->patchJson('/api/v1/admin/site', ['rulesUrl' => 'javascript:alert(1)'])
            ->assertStatus(400);
        $this->patchJson('/api/v1/admin/site', ['theme' => 'Not Valid'])
            ->assertStatus(400);
        $this->patchJson('/api/v1/admin/site', ['siteName' => 'ok', 'bogus' => 1])
            ->assertStatus(400)->assertJsonPath('code', 'VALIDATION_ERROR');
        // Nothing from the rejected payload was applied (SITE-003).
        $this->assertSame('Lodenica', app(SiteConfig::class)->siteName());
    }

    public function test_logo_upload_serve_and_remove(): void
    {
        Storage::fake('local');
        $this->getJson('/api/v1/site/logo')->assertStatus(404);

        $this->actingAsAdmin();
        $this->post('/api/v1/admin/site/logo', ['logo' => UploadedFile::fake()->image('logo.png', 64, 64)])
            ->assertOk()
            ->assertJsonPath('logoUrl', fn ($url) => is_string($url) && str_contains($url, '/api/v1/site/logo?v='));
        Storage::disk('local')->assertExists('site/logo.png');

        $this->get('/api/v1/site/logo')->assertOk()->assertHeader('Cache-Control', 'max-age=86400, public');

        // Replacing with another format removes the old file.
        $this->post('/api/v1/admin/site/logo', ['logo' => UploadedFile::fake()->image('logo.webp', 64, 64)])->assertOk();
        Storage::disk('local')->assertMissing('site/logo.png');
        Storage::disk('local')->assertExists('site/logo.webp');

        $this->post('/api/v1/admin/site/logo', ['logo' => UploadedFile::fake()->create('logo.svg', 10, 'image/svg+xml')])
            ->assertStatus(400);

        $this->deleteJson('/api/v1/admin/site/logo')->assertNoContent();
        Storage::disk('local')->assertMissing('site/logo.webp');
        $this->getJson('/api/v1/site')->assertJsonPath('logoUrl', null);
    }
}
```

- [ ] **Step 2: Run to verify it fails**

Run: `cd backend-php && vendor/bin/phpunit tests/Feature/Api/SiteApiTest.php`
Expected: 404s / failures (routes missing).

- [ ] **Step 3: Create the FormRequest**

```php
<?php
// backend-php/app/Http/Requests/UpdateSiteConfigRequest.php
namespace App\Http\Requests;

use App\Services\SiteConfig;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Partial update of the site configuration (SITE-003). Every field is
 * optional; unknown keys are rejected so a typo in the SPA cannot be
 * silently dropped.
 */
class UpdateSiteConfigRequest extends FormRequest
{
    private const URL = ['sometimes', 'nullable', 'url:http,https', 'max:500'];

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'siteName' => ['sometimes', 'nullable', 'string', 'min:1', 'max:80'],
            'shortName' => ['sometimes', 'nullable', 'string', 'min:1', 'max:40'],
            'clubName' => ['sometimes', 'nullable', 'string', 'max:160'],
            'contactEmail' => ['sometimes', 'nullable', 'email', 'max:200'],
            'adminEmail' => ['sometimes', 'nullable', 'email', 'max:200'],
            'address' => ['sometimes', 'nullable', 'string', 'max:200'],
            'mapsUrl' => self::URL,
            'websiteUrl' => self::URL,
            'rulesUrl' => self::URL,
            'gdprNoticeUrl' => self::URL,
            'gdprConsentUrl' => self::URL,
            'statutesUrl' => self::URL,
            'operatorNotice' => ['sometimes', 'nullable', 'string', 'max:500'],
            'memberIdExample' => ['sometimes', 'nullable', 'string', 'max:40'],
            'features' => ['sometimes', 'array'],
            'features.paddlingTrafficLight' => ['sometimes', 'nullable', 'boolean'],
            'features.expeditions' => ['sometimes', 'nullable', 'boolean'],
            'theme' => ['sometimes', 'nullable', 'string', 'regex:/^[a-z][a-z0-9-]{1,31}$/'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v): void {
            $allowed = [...SiteConfig::fieldNames(), 'features'];
            foreach (array_diff(array_keys($this->all()), $allowed) as $unknown) {
                $v->errors()->add((string) $unknown, "Neznáme pole „{$unknown}“.");
            }
            $features = $this->input('features');
            if (is_array($features)) {
                foreach (array_diff(array_keys($features), SiteConfig::featureNames()) as $unknown) {
                    $v->errors()->add("features.{$unknown}", "Neznámy modul „{$unknown}“.");
                }
            }
        });
    }

    public function messages(): array
    {
        return [
            '*.email' => 'Zadajte platnú e-mailovú adresu.',
            '*.url' => 'Zadajte platnú adresu začínajúcu http:// alebo https://.',
            'theme.regex' => 'Kľúč témy môže obsahovať len malé písmená, číslice a pomlčky.',
        ];
    }
}
```

- [ ] **Step 4: Create the controller**

```php
<?php
// backend-php/app/Http/Controllers/Api/SiteController.php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateSiteConfigRequest;
use App\Services\SiteConfig;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The club identity of this installation. Public read (the SPA needs the
 * name, links and feature switches before anyone logs in); admin write.
 * See docs/spec/13-site-configuration.md.
 */
class SiteController extends Controller
{
    public function __construct(private readonly SiteConfig $site) {}

    /** GET /api/v1/site — public, without adminEmail (SITE-002). */
    public function show(): JsonResponse
    {
        return new JsonResponse($this->site->publicPayload());
    }

    /** GET /api/v1/site/logo — public stream, 404 when no logo. */
    public function logo(): BinaryFileResponse
    {
        $path = $this->site->logoPath();
        if ($path === null) {
            throw new NotFoundHttpException('Logo nie je nastavené.');
        }

        return response()->file(Storage::disk('local')->path($path), [
            'Cache-Control' => 'public, max-age=86400',
            'X-Robots-Tag' => 'noindex, nofollow',
        ]);
    }

    /** GET /api/v1/admin/site — everything, adminEmail included. */
    public function adminShow(): JsonResponse
    {
        return new JsonResponse($this->site->all());
    }

    /** PATCH /api/v1/admin/site — partial update, returns the resolved config. */
    public function update(UpdateSiteConfigRequest $request): JsonResponse
    {
        return new JsonResponse($this->site->update($request->validated()));
    }

    /** POST /api/v1/admin/site/logo — multipart `logo` (png/jpg/webp ≤ 2 MB). */
    public function uploadLogo(Request $request): JsonResponse
    {
        $request->validate([
            'logo' => ['required', 'image', 'mimes:png,jpg,jpeg,webp', 'max:2048'],
        ]);

        $file = $request->file('logo');
        $ext = strtolower($file->extension() ?: $file->getClientOriginalExtension() ?: 'png');
        $this->deleteLogoFiles();
        Storage::disk('local')->putFileAs(SiteConfig::LOGO_DIR, $file, "logo.{$ext}");
        $this->site->setLogoPath(SiteConfig::LOGO_DIR."/logo.{$ext}");

        return new JsonResponse($this->site->all());
    }

    /** DELETE /api/v1/admin/site/logo */
    public function removeLogo(): JsonResponse
    {
        $this->deleteLogoFiles();
        $this->site->setLogoPath(null);

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }

    private function deleteLogoFiles(): void
    {
        foreach (SiteConfig::LOGO_EXTENSIONS as $ext) {
            Storage::disk('local')->delete(SiteConfig::LOGO_DIR."/logo.{$ext}");
        }
    }
}
```

- [ ] **Step 5: Routes** — in `routes/api.php` add after the `auth/*` public block:

```php
use App\Http\Controllers\Api\SiteController;
// …
// Site identity — name, links, feature switches, logo. Public because the
// SPA paints the header before anyone logs in. Writes are in the admin group.
Route::get('site', [SiteController::class, 'show']);
Route::get('site/logo', [SiteController::class, 'logo']);
```
and inside the admin group:
```php
    Route::get('admin/site', [SiteController::class, 'adminShow']);
    Route::patch('admin/site', [SiteController::class, 'update']);
    Route::post('admin/site/logo', [SiteController::class, 'uploadLogo']);
    Route::delete('admin/site/logo', [SiteController::class, 'removeLogo']);
```

- [ ] **Step 6: Run the test, then the whole suite**

Run: `cd backend-php && vendor/bin/phpunit tests/Feature/Api/SiteApiTest.php && vendor/bin/phpunit`
Expected: 5 new tests pass; suite green. If the `Cache-Control` header order differs, assert with `assertHeader('Cache-Control', 'max-age=86400, public')` (Symfony normalises it that way).

- [ ] **Step 7: Commit**

```bash
git add backend-php/app/Http/Requests/UpdateSiteConfigRequest.php backend-php/app/Http/Controllers/Api/SiteController.php backend-php/routes/api.php backend-php/tests/Feature/Api/SiteApiTest.php
git commit -m "feat(site): public site payload, admin update and logo endpoints"
```

---

### Task 3: Feature switches gate the API

**Files:**
- Create: `backend-php/app/Http/Middleware/EnsureFeature.php`
- Modify: `backend-php/bootstrap/app.php` (alias), `backend-php/routes/api.php`
- Test: `backend-php/tests/Feature/Api/FeatureGateTest.php`

- [ ] **Step 1: Failing test**

```php
<?php
// backend-php/tests/Feature/Api/FeatureGateTest.php
namespace Tests\Feature\Api;

use App\Services\SiteConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class FeatureGateTest extends TestCase
{
    use RefreshDatabase;

    public function test_traffic_light_is_404_while_the_feature_is_off(): void
    {
        config(['site.features.paddling_traffic_light' => false]);
        $this->getJson('/api/v1/paddling-traffic-light')->assertStatus(404);
    }

    public function test_traffic_light_answers_when_the_feature_is_on(): void
    {
        Http::fake(['*' => Http::response(['status' => 'ok', 'data' => ['x' => 1]])]);
        app(SiteConfig::class)->update(['features' => ['paddlingTrafficLight' => true]]);
        $this->getJson('/api/v1/paddling-traffic-light')->assertOk();
    }

    public function test_expeditions_are_404_while_the_feature_is_off(): void
    {
        $this->actingAsMember();
        $this->getJson('/api/v1/expeditions')->assertOk();

        app(SiteConfig::class)->update(['features' => ['expeditions' => false]]);
        $this->getJson('/api/v1/expeditions')->assertStatus(404);
        $this->postJson('/api/v1/expeditions', [])->assertStatus(404);
    }
}
```

- [ ] **Step 2: Run** — expected: first and third fail (200 instead of 404).

- [ ] **Step 3: Middleware**

```php
<?php
// backend-php/app/Http/Middleware/EnsureFeature.php
namespace App\Http\Middleware;

use App\Services\SiteConfig;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * `feature:<name>` — the route exists only on installations that switched
 * the module on (Administrácia → Nastavenia stránky → Moduly). Off means a
 * plain 404, the same answer as a route that was never registered.
 */
class EnsureFeature
{
    public function __construct(private readonly SiteConfig $site) {}

    public function handle(Request $request, Closure $next, string $feature): Response
    {
        if (!$this->site->feature($feature)) {
            throw new NotFoundHttpException('Táto funkcia nie je na tejto inštalácii zapnutá.');
        }

        return $next($request);
    }
}
```

`bootstrap/app.php` alias list: add `'feature' => \App\Http\Middleware\EnsureFeature::class,`.

- [ ] **Step 4: Routes** — in `routes/api.php`:
  - `Route::get('paddling-traffic-light', …)->middleware('feature:paddlingTrafficLight');`
  - `Route::get('expeditions/{id}/photos/{photoId}', …)->middleware('feature:expeditions');`
  - Wrap the six expedition routes inside the member group in `Route::middleware('feature:expeditions')->group(function () { … });`.

- [ ] **Step 5: Run** the test file and the full suite. Expected: green (existing `ExpeditionsApiTest` still passes because the default is on; `PaddlingTrafficLightApiTest` must now enable the feature — add `config(['site.features.paddling_traffic_light' => true]);` in its `setUp()` or at the top of each test).

- [ ] **Step 6: Commit**

```bash
git add backend-php/app/Http/Middleware/EnsureFeature.php backend-php/bootstrap/app.php backend-php/routes/api.php backend-php/tests/Feature/Api/FeatureGateTest.php backend-php/tests/Feature/Api/PaddlingTrafficLightApiTest.php
git commit -m "feat(site): feature switches hide the traffic light and expeditions API"
```

---

### Task 4: Mail branding from SiteConfig

**Files:**
- Modify: `backend-php/app/Services/NotificationMailer.php`, `backend-php/app/Services/AdminNotifier.php`, `backend-php/app/Providers/AppServiceProvider.php`, `backend-php/resources/views/emails/layout.blade.php`, `backend-php/config/mail.php`, `backend-php/app/Http/Controllers/Api/MailDiagnosticsController.php`, all 7 files in `backend-php/app/Mail/`
- Modify test: `backend-php/tests/Feature/Api/MailDiagnosticsApiTest.php` (line 62/73)
- Test: `backend-php/tests/Feature/MailBrandingTest.php`

- [ ] **Step 1: Failing test**

```php
<?php
// backend-php/tests/Feature/MailBrandingTest.php
namespace Tests\Feature;

use App\Domain\Enums\MailNotification;
use App\Mail\MembershipApprovedMail;
use App\Mail\PasswordResetMail;
use App\Services\NotificationMailer;
use App\Services\SiteConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class MailBrandingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(SiteConfig::class)->update(['siteName' => 'Klub Test']);
        config(['mail.from.address' => 'robot@example.test', 'mail.from.name' => 'ignored']);
    }

    public function test_subject_and_layout_carry_the_site_name(): void
    {
        $mail = new PasswordResetMail('a@example.test', 'https://x.test/reset', 30);
        $this->assertStringEndsWith('— Klub Test', $mail->build()->subject);

        $html = $mail->render();
        $this->assertStringContainsString('Klub Test', $html);
        $this->assertStringNotContainsString('KVŠ', $html);
    }

    public function test_notification_mailer_sets_the_from_name(): void
    {
        Mail::fake();
        app(NotificationMailer::class)->send(
            MailNotification::MEMBERSHIP_APPROVED,
            'm@example.test',
            new MembershipApprovedMail('Meno', 'https://x.test'),
        );
        Mail::assertSent(MembershipApprovedMail::class, fn ($m) => $m->hasFrom('robot@example.test', 'Klub Test'));
    }

    public function test_admin_notifier_uses_the_configured_admin_email(): void
    {
        Mail::fake();
        app(SiteConfig::class)->update(['contactEmail' => 'klub@example.test']);
        $user = $this->actingAsPending();
        app(\App\Services\AdminNotifier::class)->pendingMemberAwaitingApproval($user);
        Mail::assertSent(\App\Mail\PendingMemberNotificationMail::class, fn ($m) => $m->hasTo('klub@example.test'));
    }
}
```
Check `MembershipApprovedMail`'s constructor before writing the second test and pass its real arguments.

- [ ] **Step 2: Run** — expected failures on 'KVŠ' in subject / from-name.

- [ ] **Step 3: Implement**

Every mailable's `build()`:
```php
$siteName = app(SiteConfig::class)->siteName();
return $this->subject("Obnova hesla — {$siteName}")->view(…);
```
(`MailTestMail` additionally: `$from = (string) config('mail.from.address'); if ($from !== '') { $this->from($from, $siteName); }`.)

`NotificationMailer`:
```php
public function __construct(
    private readonly MailNotificationSettings $settings,
    private readonly UserNotificationPreferences $preferences,
    private readonly SiteConfig $site,
) {}
// in send(), before Mail::to():
$from = (string) config('mail.from.address');
if ($from !== '') {
    $mail->from($from, $this->site->siteName());
}
```

`AppServiceProvider::boot()`:
```php
// E-mail layout header/title follow the installation's name.
View::composer('emails.layout', function (\Illuminate\View\View $view): void {
    $view->with('siteName', app(SiteConfig::class)->siteName());
});
```
`layout.blade.php`: `<title>{{ $title ?? $siteName }}</title>` and the header span `{{ $siteName }}`.

`AdminNotifier`: inject `SiteConfig $site`; `$to = (string) $this->site->adminEmail();`.
`MailDiagnosticsController::config()`: inject `SiteConfig $site` in the method; `'adminAddress' => $site->adminEmail(),`.
`config/mail.php`: `'admin_address' => env('MAIL_ADMIN_ADDRESS'),` (no KVŠ default; superseded by `config/site.php` `admin_email` — keep the key so old code paths compile).
`MailDiagnosticsApiTest`: replace `'mail.admin_address' => 'admin@example.test'` with `'site.admin_email' => 'admin@example.test'`.

- [ ] **Step 4: Run** `vendor/bin/phpunit`. Expected: green.

- [ ] **Step 5: Commit**

```bash
git add backend-php/app/Mail backend-php/app/Services/NotificationMailer.php backend-php/app/Services/AdminNotifier.php backend-php/app/Providers/AppServiceProvider.php backend-php/resources/views/emails/layout.blade.php backend-php/config/mail.php backend-php/app/Http/Controllers/Api/MailDiagnosticsController.php backend-php/tests/Feature/MailBrandingTest.php backend-php/tests/Feature/Api/MailDiagnosticsApiTest.php
git commit -m "feat(mail): subjects, from-name, layout and admin address follow the site config"
```

---

### Task 5: ICS export and file names from config

**Files:**
- Modify: `backend-php/app/Http/Controllers/Api/ReservationsController.php:160-215`, `backend-php/app/Http/Controllers/Api/AdminDataController.php:70,83,115,153`
- Modify test: `backend-php/tests/Feature/Api/ReservationIcsApiTest.php`

- [ ] **Step 1: Update the test** — replace `test_ics_returns_calendar_attachment_with_event` assertions:

```php
config(['app.url' => 'https://rezervacie.example.test']);
app(SiteConfig::class)->update(['siteName' => 'Klub Test', 'address' => 'Prístav 1, 900 01 Mesto', 'mapsUrl' => 'https://maps.example.test/x']);
// … create as before …
$this->assertStringContainsString('UID:'.$reservation->id.'@rezervacie.example.test', $body);
$this->assertStringContainsString('PRODID:-//Klub Test//SK', $body);
$this->assertStringContainsString('SUMMARY:Klub Test: K-ICS – P&H Cetus', $body);
$this->assertStringContainsString('LOCATION:Prístav 1\, 900 01 Mesto', $body);
$this->assertStringContainsString('https://maps.example.test/x', $body);
$this->assertStringContainsString('Janka Tester', $body);
```
Add:
```php
public function test_ics_omits_location_and_maps_when_not_configured(): void
{
    $resource = Resource::create(['identifier' => 'K-2', 'type' => ResourceType::SEA_KAYAK, 'name' => 'X']);
    $reservation = app(ReservationsService::class)->create(['resourceId' => $resource->id, 'customerName' => 'A', 'startsAt' => '2099-08-12T09:00:00Z', 'endsAt' => '2099-08-12T12:00:00Z']);
    $body = $this->get("/api/v1/reservations/{$reservation->id}/ics")->getContent();
    $this->assertStringNotContainsString('LOCATION:', $body);
    $this->assertStringNotContainsString('maps.', $body);
}
```

- [ ] **Step 2: Run** — expected failures (KVŠ literals).

- [ ] **Step 3: Implement** in `ics()` (inject `SiteConfig $site` as a method parameter):

```php
$siteName = $site->siteName();
$summary = $resource ? "{$siteName}: {$resource->identifier} – {$resource->name}" : "{$siteName}: rezervácia";
$description = trim(implode("\\n", array_filter([
    $site->get('mapsUrl'),
    'Rezervácia pre: '.$reservation->customerName,
    // … unchanged …
])));
$host = parse_url((string) config('app.url'), PHP_URL_HOST) ?: 'localhost';
$address = (string) $site->get('address');
$lines = [
    'BEGIN:VCALENDAR', 'VERSION:2.0',
    'PRODID:-//'.$this->icalEscape($siteName).'//SK',
    'CALSCALE:GREGORIAN', 'METHOD:PUBLISH', 'BEGIN:VEVENT',
    'UID:'.$reservation->id.'@'.$host,
    // … DTSTAMP/DTSTART/DTEND/SUMMARY/DESCRIPTION unchanged …
];
if ($address !== '') {
    $lines[] = 'LOCATION:'.$this->icalEscape($address);
}
$lines[] = 'STATUS:'.match (…) { … };
$lines[] = 'END:VEVENT';
$lines[] = 'END:VCALENDAR';
```
`AdminDataController` file names: `zaloha-`, `rezervacie-`, `lode-`, `clenovia-` (drop `lodenica-`).

- [ ] **Step 4: Run** the suite. **Step 5: Commit**

```bash
git add backend-php/app/Http/Controllers/Api/ReservationsController.php backend-php/app/Http/Controllers/Api/AdminDataController.php backend-php/tests/Feature/Api/ReservationIcsApiTest.php
git commit -m "feat(reservations): ICS summary, location and maps link come from the site config"
```

---

### Task 6: Per-user theme

**Files:**
- Create: `backend-php/database/migrations/2026_09_06_000000_add_theme_to_users.php`
- Modify: `backend-php/app/Http/Controllers/Api/ProfileController.php`, `backend-php/app/Http/Resources/UserResource.php`, `backend-php/routes/api.php`
- Test: `backend-php/tests/Feature/Api/ProfileAppearanceApiTest.php`

- [ ] **Step 1: Failing test**

```php
<?php
namespace Tests\Feature\Api;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProfileAppearanceApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_anonymous_cannot_set_a_theme(): void
    {
        $this->patchJson('/api/v1/profile/appearance', ['theme' => 'forest'])->assertStatus(401);
    }

    public function test_user_sets_and_clears_their_theme(): void
    {
        $user = $this->actingAsPending();
        $this->patchJson('/api/v1/profile/appearance', ['theme' => 'forest'])
            ->assertOk()->assertJsonPath('theme', 'forest');
        $this->getJson('/api/v1/auth/me')->assertJsonPath('theme', 'forest');
        $this->assertSame('forest', $user->fresh()->theme);

        $this->patchJson('/api/v1/profile/appearance', ['theme' => null])
            ->assertOk()->assertJsonPath('theme', null);
    }

    public function test_theme_must_be_a_slug(): void
    {
        $this->actingAsMember();
        $this->patchJson('/api/v1/profile/appearance', ['theme' => 'Bad Theme'])->assertStatus(400);
        $this->patchJson('/api/v1/profile/appearance', [])->assertStatus(400);
    }
}
```

- [ ] **Step 2: Run** → 404/500. **Step 3: Implement**

Migration:
```php
return new class extends Migration {
    public function up(): void { Schema::table('users', fn (Blueprint $t) => $t->string('theme', 32)->nullable()); }
    public function down(): void { Schema::table('users', fn (Blueprint $t) => $t->dropColumn('theme')); }
};
```
`ProfileController`:
```php
/** PATCH /api/v1/profile/appearance — `{ theme: slug|null }`; null = site default (THEME-001). */
public function updateAppearance(Request $request, AuditLogger $audit): UserResource
{
    $data = $request->validate(['theme' => ['present', 'nullable', 'string', 'regex:/^[a-z][a-z0-9-]{1,31}$/']]);
    /** @var User $user */
    $user = $request->user();
    $before = $user->theme;
    if ($before !== $data['theme']) {
        $user->theme = $data['theme'];
        $user->save();
        $audit->logUpdate(AuditEntityType::USER, $user, "Zmenená téma vzhľadu používateľa „{$user->name}“", ['theme' => $before], ['theme' => $data['theme']]);
    }
    return new UserResource($user);
}
```
`UserResource`: add `'theme' => $this->theme,` after `isActive`. Route (auth group): `Route::patch('profile/appearance', [ProfileController::class, 'updateAppearance']);`.

- [ ] **Step 4: Run** suite. **Step 5: Commit** `feat(profile): users pick their own colour theme`.

---

### Task 7: Seeders — admin from secrets, templated content, demo only locally

**Files:**
- Create: `backend-php/database/seeders/AdminSeeder.php`, `ContentSeeder.php`, `DemoDataSeeder.php`, `content/reservation-rules.html`, `content/faq.html`, `content/privacy-policy.html`
- Modify: `backend-php/database/seeders/DatabaseSeeder.php`, migrations `2026_06_12_220000_create_settings_table.php`, `2026_06_17_040000_seed_privacy_policy_setting.php`, `2026_06_17_050000_seed_faq_setting.php`
- Modify test: `backend-php/tests/Feature/Api/ReservationRulesApiTest.php::test_default_rules_seed_is_present`
- Test: `backend-php/tests/Feature/SeedersTest.php`

- [ ] **Step 1: Failing test**

```php
<?php
namespace Tests\Feature;

use App\Domain\Enums\UserRole;
use App\Models\Resource;
use App\Models\User;
use App\Services\SiteConfig;
use Database\Seeders\AdminSeeder;
use Database\Seeders\ContentSeeder;
use Database\Seeders\DemoDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SeedersTest extends TestCase
{
    use RefreshDatabase;

    private function env(string $name): void { $this->app['env'] = $name; }

    public function test_admin_is_created_from_config_on_an_empty_database(): void
    {
        $this->env('production');
        config(['site.admin.email' => 'boss@example.test', 'site.admin.password' => 'S3cret!pass', 'site.admin.name' => 'Boss']);
        $this->seed(AdminSeeder::class);
        $admin = User::where('email', 'boss@example.test')->firstOrFail();
        $this->assertSame(UserRole::ADMIN, $admin->role);
        $this->assertTrue(password_verify('S3cret!pass', $admin->password));
    }

    public function test_production_without_credentials_and_without_admin_fails_loudly(): void
    {
        $this->env('production');
        config(['site.admin.email' => null, 'site.admin.password' => null]);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('ADMIN_EMAIL');
        $this->seed(AdminSeeder::class);
    }

    public function test_existing_admin_means_no_op(): void
    {
        $this->env('production');
        $this->actingAsAdmin();
        config(['site.admin.email' => null, 'site.admin.password' => null]);
        $this->seed(AdminSeeder::class); // must not throw
        $this->assertSame(1, User::where('role', UserRole::ADMIN)->count());
    }

    public function test_local_environment_falls_back_to_dev_admin(): void
    {
        $this->env('local');
        config(['site.admin.email' => null, 'site.admin.password' => null]);
        $this->seed(AdminSeeder::class);
        $this->assertTrue(User::where('email', 'admin@lodenica.sk')->exists());
    }

    public function test_demo_data_only_in_local_or_when_asked(): void
    {
        $this->env('production');
        config(['site.seed_demo_data' => false]);
        $this->seed(DemoDataSeeder::class);
        $this->assertSame(0, Resource::count());

        config(['site.seed_demo_data' => true]);
        $this->seed(DemoDataSeeder::class);
        $this->assertGreaterThan(0, Resource::count());
        $count = Resource::count();
        $this->seed(DemoDataSeeder::class);
        $this->assertSame($count, Resource::count(), 'idempotent');
    }

    public function test_content_seeder_substitutes_placeholders_and_keeps_admin_edits(): void
    {
        app(SiteConfig::class)->update(['siteName' => 'Klub Test', 'clubName' => 'Vodácky klub Test', 'contactEmail' => 'klub@example.test']);
        $this->seed(ContentSeeder::class);

        $faq = DB::table('settings')->where('key', 'faq')->value('value');
        $this->assertStringContainsString('klub@example.test', $faq);
        $this->assertStringNotContainsString('{{', $faq);
        $rules = DB::table('settings')->where('key', 'reservation_rules')->value('value');
        $this->assertStringContainsString('Vodácky klub Test', $rules);
        $this->assertTrue(DB::table('settings')->where('key', 'privacy_policy')->exists());

        DB::table('settings')->where('key', 'faq')->update(['value' => '<p>upravené</p>']);
        $this->seed(ContentSeeder::class);
        $this->assertSame('<p>upravené</p>', DB::table('settings')->where('key', 'faq')->value('value'));
    }

    public function test_render_unwraps_anchors_without_a_target(): void
    {
        $html = ContentSeeder::render('<p>Píšte na <a href="mailto:{{contactEmail}}">{{contactEmail}}</a> alebo <a href="{{rulesUrl}}">poriadok</a>.</p>', ['contactEmail' => '', 'rulesUrl' => '']);
        $this->assertSame('<p>Píšte na  alebo poriadok.</p>', $html);
    }
}
```

- [ ] **Step 2: Run** → class not found.

- [ ] **Step 3: Seeders**

`AdminSeeder.php`:
```php
<?php
namespace Database\Seeders;

use App\Domain\Enums\UserRole;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Guarantees one admin account exists (INST-002). Runs on every deploy.
 *  - an ADMIN already exists → nothing (the configured account is re-enabled if it was deactivated)
 *  - none → create from ADMIN_EMAIL/ADMIN_PASSWORD; local/testing may fall back to the dev account
 *  - none and no credentials outside local → throw, so install.php fails visibly
 */
class AdminSeeder extends Seeder
{
    public function run(): void
    {
        $email = (string) (config('site.admin.email') ?? '');
        $password = (string) (config('site.admin.password') ?? '');
        $name = (string) (config('site.admin.name') ?: 'Administrátor');

        if (User::query()->where('role', UserRole::ADMIN)->exists()) {
            if ($email !== '') {
                $configured = User::query()->where('email', $email)->first();
                if ($configured && (!$configured->isActive || !$configured->isAdmin())) {
                    $configured->isActive = true;
                    $configured->role = UserRole::ADMIN;
                    $configured->save();
                    $this->command?->info("Re-enabled admin {$email}.");
                }
            }
            $this->command?->info('Admin already present.');
            return;
        }

        if ($email === '' || $password === '') {
            if (app()->environment('local', 'testing')) {
                $email = 'admin@lodenica.sk';
                $password = 'Lodenica2026!';
                $this->command?->warn("No ADMIN_EMAIL/ADMIN_PASSWORD — using the dev account {$email} / {$password}.");
            } else {
                throw new \RuntimeException(
                    'No admin account exists and ADMIN_EMAIL / ADMIN_PASSWORD are not set. '
                    .'A first install needs both in the deploy secrets (see docs/CLIENT-ONBOARDING.md).'
                );
            }
        }

        User::create(['name' => $name, 'email' => $email, 'password' => $password, 'role' => UserRole::ADMIN, 'isActive' => true]);
        $this->command?->warn("Created admin {$email} — change the password after the first login.");
    }
}
```

`ContentSeeder.php`:
```php
<?php
namespace Database\Seeders;

use App\Services\SiteConfig;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Default content pages for a fresh installation. Inserts a page only when
 * its settings row is missing, so an admin's edits survive every deploy.
 * Templates live in database/seeders/content/ and use {{siteName}},
 * {{clubName}}, {{contactEmail}}, {{rulesUrl}}.
 */
class ContentSeeder extends Seeder
{
    private const PAGES = [
        'reservation_rules' => 'reservation-rules.html',
        'faq' => 'faq.html',
        'privacy_policy' => 'privacy-policy.html',
    ];

    public function run(): void
    {
        $site = app(SiteConfig::class);
        $vars = [
            'siteName' => $site->siteName(),
            'clubName' => (string) $site->get('clubName') ?: $site->siteName(),
            'contactEmail' => (string) $site->contactEmail(),
            'rulesUrl' => (string) $site->get('rulesUrl'),
        ];

        foreach (self::PAGES as $key => $file) {
            if (DB::table('settings')->where('key', $key)->exists()) {
                continue;
            }
            $template = (string) file_get_contents(__DIR__.'/content/'.$file);
            DB::table('settings')->insert(['key' => $key, 'value' => self::render($template, $vars), 'updatedAt' => now()]);
            $this->command?->info("Seeded content page '{$key}'.");
        }
    }

    /** @param array<string, string> $vars */
    public static function render(string $template, array $vars): string
    {
        $html = preg_replace_callback('/\{\{(\w+)\}\}/', fn (array $m) => e($vars[$m[1]] ?? ''), $template) ?? $template;
        // A link whose target resolved to nothing becomes plain text.
        return preg_replace('#<a\b[^>]*href="(?:mailto:)?"[^>]*>(.*?)</a>#is', '$1', $html) ?? $html;
    }
}
```

`DemoDataSeeder.php`: move the body of today's `DatabaseSeeder::run()` after the admin call (from `if (Resource::query()->count() > 0)` to `Seed complete.`) into `run()`, preceded by:
```php
if (!app()->environment('local') && !config('site.seed_demo_data')) {
    $this->command?->info('Skipping demo data (not local, SEED_DEMO_DATA not set).');
    return;
}
```
`DatabaseSeeder::run()` becomes `$this->call([AdminSeeder::class, ContentSeeder::class, DemoDataSeeder::class]);` with a class docblock explaining the three.

Templates (generic Slovak; write full HTML, ~the structure of today's texts with placeholders). `content/faq.html` — today's FAQ text with the mailto replaced by `<a href="mailto:{{contactEmail}}">{{contactEmail}}</a>` and "lodenice" phrasing kept generic. `content/reservation-rules.html` — sections "O tomto systéme" (uses `{{clubName}}` and `<a href="{{rulesUrl}}">prevádzkovým poriadkom</a>`), "Pred použitím lode", "Ako si rezervovať loď", "Po jazde", "Poškodenia" — adapt today's `deploy/reservation-rules.html` without KVŠ specifics. `content/privacy-policy.html` — today's privacy text with `{{clubName}}` for the operator and `{{contactEmail}}` for the contact.

Migrations: in `create_settings_table` delete the seed block (keep `Schema::create`); in the two seed migrations replace `up()` bodies with a comment `// Content is seeded by Database\Seeders\ContentSeeder since 2026-09; kept as a no-op so the migrations table history stays valid.` and delete `defaultHtml()`.

`ReservationRulesApiTest::test_default_rules_seed_is_present`:
```php
app(SiteConfig::class)->update(['clubName' => 'Klub Test']);
$this->seed(\Database\Seeders\ContentSeeder::class);
$this->getJson('/api/v1/reservation-rules')->assertOk()
    ->assertJsonPath('content', fn ($c) => is_string($c) && str_contains($c, 'O tomto systéme') && str_contains($c, 'Klub Test'));
```

- [ ] **Step 4: Run** the suite. **Step 5: Commit**

```bash
git add backend-php/database/seeders backend-php/database/migrations/2026_06_12_220000_create_settings_table.php backend-php/database/migrations/2026_06_17_040000_seed_privacy_policy_setting.php backend-php/database/migrations/2026_06_17_050000_seed_faq_setting.php backend-php/tests/Feature/SeedersTest.php backend-php/tests/Feature/Api/ReservationRulesApiTest.php
git commit -m "feat(seed): admin from secrets, templated content pages, demo data only locally"
```

---

## Part B — Frontend

### Task 8: Theme engine (CSS variables)

**Files:**
- Modify: `frontend/tailwind.config.js`, `frontend/src/styles/main.css`, `frontend/index.html` (theme-color meta stays; title neutral)
- Create: `frontend/src/theme/themes.ts`, `frontend/src/theme/themes.spec.ts`

**Produces:**
```ts
export type ThemeKey = 'ocean' | 'forest' | 'sunset' | 'berry' | 'graphite';
export const THEMES: Record<ThemeKey, { label: string; themeColor: string; colors: Record<Shade, string> }>;
export const DEFAULT_THEME: ThemeKey;
export function isThemeKey(v: unknown): v is ThemeKey;
export function resolveTheme(userTheme: string | null | undefined, siteTheme: string): ThemeKey;
export function applyTheme(key: string | null | undefined): ThemeKey;
export function readCachedTheme(): string | null; export function writeCachedTheme(key: ThemeKey): void;
```

- [ ] **Step 1: Failing test** `themes.spec.ts`

```ts
import { describe, expect, it } from 'vitest';
import { applyTheme, DEFAULT_THEME, isThemeKey, readCachedTheme, resolveTheme, THEMES, writeCachedTheme } from './themes';

describe('themes', () => {
  it('knows its keys', () => {
    expect(isThemeKey('ocean')).toBe(true);
    expect(isThemeKey('neon')).toBe(false);
    expect(Object.keys(THEMES)).toContain(DEFAULT_THEME);
  });
  it('user theme wins over site theme, unknown values fall back', () => {
    expect(resolveTheme('forest', 'sunset')).toBe('forest');
    expect(resolveTheme(null, 'sunset')).toBe('sunset');
    expect(resolveTheme(undefined, 'bogus')).toBe(DEFAULT_THEME);
  });
  it('applies CSS variables, data attribute and theme-color', () => {
    document.head.innerHTML = '<meta name="theme-color" content="#000">';
    expect(applyTheme('berry')).toBe('berry');
    expect(document.documentElement.dataset.theme).toBe('berry');
    expect(document.documentElement.style.getPropertyValue('--brand-600')).toBe(THEMES.berry.colors[600]);
    expect(document.querySelector('meta[name="theme-color"]')?.getAttribute('content')).toBe(THEMES.berry.themeColor);
    expect(applyTheme('bogus')).toBe(DEFAULT_THEME);
  });
  it('caches the last applied theme', () => {
    writeCachedTheme('graphite');
    expect(readCachedTheme()).toBe('graphite');
  });
});
```

- [ ] **Step 2: Run** `pnpm test src/theme` → fails. **Step 3: Implement**

`themes.ts` — palettes as `"r g b"` triplets (Tailwind blue-ish ocean = today's brand hexes; forest = tailwind green; sunset = orange; berry = violet; graphite = slate), `themeColor` = the 700 shade hex. `applyTheme` sets `root.dataset.theme`, every `--brand-<shade>` via `style.setProperty`, and the meta. Cache key `app.theme` in `localStorage` wrapped in try/catch.

`tailwind.config.js`:
```js
const shades = [50, 100, 200, 300, 400, 500, 600, 700, 800, 900, 950];
// …
colors: { brand: Object.fromEntries(shades.map((s) => [s, `rgb(var(--brand-${s}) / <alpha-value>)`])) },
```
`main.css` `@layer base`: `:root { --brand-50: 238 247 255; … --brand-950: 14 36 71; }` (ocean, so the first paint before JS is today's blue).

- [ ] **Step 4: Run** `pnpm test src/theme && pnpm typecheck && pnpm build` → green, build succeeds. **Step 5: Commit** `feat(frontend): brand palette as CSS variables with five colour themes`.

---

### Task 9: Site store, bootstrap, router title + feature guard

**Files:**
- Create: `frontend/src/api/site.api.ts`, `frontend/src/stores/site.store.ts`, `frontend/src/stores/site.store.spec.ts`, `frontend/src/composables/useSiteChrome.ts`
- Modify: `frontend/src/main.ts`, `frontend/src/App.vue`, `frontend/src/router/index.ts`, `frontend/src/api/types.ts` (`User.theme: string | null`)

**Produces:**
```ts
// site.api.ts
export interface SiteFeatures { paddlingTrafficLight: boolean; expeditions: boolean }
export interface SiteConfig { siteName: string; shortName: string; clubName: string; contactEmail: string | null; address: string; mapsUrl: string | null; websiteUrl: string | null; rulesUrl: string | null; gdprNoticeUrl: string | null; gdprConsentUrl: string | null; statutesUrl: string | null; operatorNotice: string; memberIdExample: string; features: SiteFeatures; theme: string; logoUrl: string | null }
export interface AdminSiteConfig extends SiteConfig { adminEmail: string | null }
export type SiteConfigPatch = Partial<Omit<AdminSiteConfig, 'logoUrl' | 'features'>> & { features?: Partial<SiteFeatures> };
export const DEFAULT_SITE_CONFIG: SiteConfig;
export const siteApi: { get(): Promise<SiteConfig>; adminGet(): Promise<AdminSiteConfig>; update(p: SiteConfigPatch): Promise<AdminSiteConfig>; uploadLogo(f: File): Promise<AdminSiteConfig>; removeLogo(): Promise<void> };
// site.store.ts
useSiteStore(): { config: Ref<SiteConfig>; loaded: Ref<boolean>; load(): Promise<void>; apply(next: SiteConfig): void }
```

- [ ] **Step 1: Failing test** `site.store.spec.ts`

```ts
import { createPinia, setActivePinia } from 'pinia';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { DEFAULT_SITE_CONFIG } from '@/api/site.api';
import { useSiteStore } from './site.store';

const get = vi.fn();
vi.mock('@/api/site.api', async (orig) => ({ ...(await orig<typeof import('@/api/site.api')>()), siteApi: { get: () => get() } }));

describe('site store', () => {
  beforeEach(() => { setActivePinia(createPinia()); localStorage.clear(); get.mockReset(); });

  it('loads the config and caches it', async () => {
    get.mockResolvedValue({ ...DEFAULT_SITE_CONFIG, siteName: 'Klub Test', shortName: 'KT' });
    const s = useSiteStore();
    await s.load();
    expect(s.config.shortName).toBe('KT');
    expect(JSON.parse(localStorage.getItem('app.site') ?? '{}').siteName).toBe('Klub Test');
    expect(s.loaded).toBe(true);
  });

  it('falls back to the cache, then defaults, when the API fails', async () => {
    localStorage.setItem('app.site', JSON.stringify({ ...DEFAULT_SITE_CONFIG, shortName: 'Cached' }));
    get.mockRejectedValue(new Error('offline'));
    const s = useSiteStore();
    expect(s.config.shortName).toBe('Cached');
    await s.load();
    expect(s.config.shortName).toBe('Cached');
    expect(s.loaded).toBe(true);
  });
});
```

- [ ] **Step 2: Run** → fails. **Step 3: Implement**

`site.api.ts` with `DEFAULT_SITE_CONFIG = { siteName: 'Lodenica', shortName: 'Lodenica', clubName: '', contactEmail: null, address: '', mapsUrl: null, websiteUrl: null, rulesUrl: null, gdprNoticeUrl: null, gdprConsentUrl: null, statutesUrl: null, operatorNotice: '', memberIdExample: '001', features: { paddlingTrafficLight: false, expeditions: true }, theme: 'ocean', logoUrl: null }`; `uploadLogo` posts `FormData` with `{ headers: { 'Content-Type': 'multipart/form-data' } }`.

`site.store.ts`: cache key `app.site`; `readCache()` merges over `DEFAULT_SITE_CONFIG` (so new fields have defaults); `load()` try/catch/finally `loaded = true`; `apply(next)` sets + caches (strip `adminEmail` via destructuring before caching).

`useSiteChrome.ts`:
```ts
export function useSiteChrome(): void {
  const auth = useAuthStore(); const site = useSiteStore();
  watchEffect(() => {
    if (auth.initializing || !site.loaded) return;
    writeCachedTheme(applyTheme(resolveTheme(auth.user?.theme, site.config.theme)));
  });
  watchEffect(() => {
    const href = site.config.logoUrl ?? '/favicon.svg';
    let link = document.querySelector<HTMLLinkElement>('link[rel="icon"]');
    if (!link) { link = document.createElement('link'); link.rel = 'icon'; document.head.appendChild(link); }
    link.href = href;
  });
}
```
`App.vue`: call `useSiteChrome()` in setup. `main.ts`: create both stores, `applyTheme(readCachedTheme() ?? site.config.theme)` before mount, `Promise.all([auth.bootstrap(), site.load()]).finally(...)`; sessionStorage key `app.visit`.

`router/index.ts`: `meta.feature: 'paddlingTrafficLight'` on `/vodacky-semafor`, `meta.feature: 'expeditions'` on the three expedition routes; in `beforeEach` (first thing): `const feature = to.meta?.feature as keyof SiteFeatures | undefined; if (feature && !useSiteStore().config.features[feature]) return { name: 'dashboard' };`; `afterEach`: `const short = useSiteStore().config.shortName; document.title = title ? \`${short} · ${title}\` : short;`. Add route `/admin/site` → `AdminSiteSettingsView` (created in Task 12; create the file as a stub `<template><div /></template>` now so typecheck passes, or add the route in Task 12 — add it in Task 12).

`types.ts` `User`: `theme: string | null;`.

- [ ] **Step 4: Run** `pnpm test && pnpm typecheck`. Existing specs that mount views with `RouterLink` stubs are unaffected. **Step 5: Commit** `feat(frontend): site store with cached config, feature guard and dynamic title`.

---

### Task 10: Navigation tree with the Administrácia group

**Files:**
- Create: `frontend/src/components/layout/NavGroup.vue`, `frontend/src/components/layout/AppShell.spec.ts`
- Modify: `frontend/src/components/layout/AppShell.vue`

**Produces:** `NavGroup` props `{ group: NavGroup; activePath: string }`, emits `navigate`. Types exported from `AppShell.vue`? No — put them in `frontend/src/components/layout/nav.ts`:
```ts
export interface NavItem { to: string; label: string; icon: string; external?: boolean; badge?: number }
export interface NavSubgroup { label: string; items: NavItem[] }
export interface NavGroup { key: string; label: string; icon: string; items: NavItem[]; subgroups?: NavSubgroup[] }
```

- [ ] **Step 1: Failing test** `AppShell.spec.ts` — mount with a memory router (routes: `/`, `/audit`, `/admin/site`, each a `<div/>`), pinia, `vi.mock('@/api/reservations.api', …approvals: () => Promise.resolve({ items: [], total: 0 }))`. Cases:
  - admin: text contains `Administrácia`, `Správa`, `Systém`, `Nastavenia stránky`; `História zmien` occurs exactly once (`wrapper.text().split('História zmien').length - 1 === 1`); `Používatelia` present.
  - member: no `Administrácia`; `História zmien` once; no `Nastavenia stránky`.
  - anonymous with `features.paddlingTrafficLight=false`: no `Vodácky semafor`; with `true`: present (inside Informácie, which auto-opens when `infoOpen`… click the group button first).
  - `site.config.rulesUrl = 'https://x'` → an `a[href="https://x"]` exists after opening Informácie; `null` → none.
  - header shows `site.config.shortName`.

- [ ] **Step 2: Run** → fails. **Step 3: Implement**

`NavGroup.vue`: collapsible button (icon, label, ▶ rotates), `open` ref auto-set when any item (own or in subgroups) matches `activePath` (`startsWith`); renders `items` then each subgroup as `<p class="mt-2 px-3 text-[11px] font-semibold uppercase tracking-wide text-slate-400">{{ sub.label }}</p>` + items. Item rendering copied from today's info-item markup (external `<a target=_blank>` with ↗, else `RouterLink` with active ring and optional badge). Emits `navigate` on any click.

`AppShell.vue`:
```ts
const mainItems = computed<NavItem[]>(() => [
  { to: '/', label: NAV_LABELS.dashboard, icon: '📊' },
  { to: '/reservations', label: NAV_LABELS.reservations, icon: '📅' },
  ...(auth.isMember && (auth.isAdmin || approvals.pendingCount > 0) ? [{ to: '/approvals', label: NAV_LABELS.approvals, icon: '✅', badge: approvals.pendingCount }] : []),
  { to: '/events', label: NAV_LABELS.events, icon: '🎉' },
  { to: '/spaces', label: NAV_LABELS.spaces, icon: '🏠' },
  { to: '/damages', label: NAV_LABELS.damages, icon: '🛠️' },
  { to: '/resources', label: NAV_LABELS.resources, icon: '🛶' },
  ...(auth.isMember && site.config.features.expeditions ? [{ to: '/expeditions', label: 'Expedície', icon: '🗺️' }] : []),
  ...(auth.isAuthenticated ? [{ to: '/profil', label: 'Môj profil', icon: '👤' }] : []),
  ...(auth.isAuthenticated && !auth.isAdmin ? [{ to: '/audit', label: NAV_LABELS.audit, icon: '📜' }] : []),
]);
const infoGroup = computed<NavGroup>(() => ({ key: 'info', label: 'Informácie', icon: 'ℹ️', items: [
  ...(site.config.features.paddlingTrafficLight ? [{ to: '/vodacky-semafor', label: 'Vodácky semafor', icon: '🚦' }] : []),
  { to: '/rules', label: 'Pravidlá rezervácie', icon: '📋' },
  { to: '/q-a', label: 'Otázky a odpovede', icon: '❓' },
  ...ext(site.config.websiteUrl, 'Web klubu', '🌐'),
  ...ext(site.config.gdprNoticeUrl, 'GDPR – Informačná povinnosť', '🔒'),
  ...ext(site.config.gdprConsentUrl, 'GDPR – Súhlas dotknutej osoby', '📝'),
  ...ext(site.config.rulesUrl, 'Prevádzkový poriadok', '📘'),
]}));
const adminGroup = computed<NavGroup | null>(() => auth.isAdmin ? { key: 'admin', label: 'Administrácia', icon: '🛡️', items: [], subgroups: [
  { label: 'Správa', items: [
    { to: '/admin/users', label: 'Používatelia', icon: '👥' }, { to: '/member-roster', label: 'Číselník členov', icon: '📇' },
    { to: '/admin/usage', label: 'Štatistiky', icon: '📈' }, { to: '/audit', label: NAV_LABELS.audit, icon: '📜' }, { to: '/admin/qr-codes', label: 'QR kódy lodí', icon: '🔳' } ] },
  { label: 'Systém', items: [
    { to: '/admin/site', label: 'Nastavenia stránky', icon: '⚙️' }, { to: '/admin/diagnostics', label: 'Diagnostika e-mailov', icon: '✉️' }, { to: '/admin/data', label: 'Správa dát', icon: '💾' } ] },
]} : null);
```
Header: `<img :src="site.config.logoUrl ?? '/favicon.svg'" …>` and `{{ site.config.shortName }}`. Remove the old `infoItems`/`infoOpen` code.

- [ ] **Step 4: Run** `pnpm test && pnpm typecheck`. **Step 5: Commit** `feat(frontend): grouped Administrácia navigation and config-driven info links`.

---

### Task 11: Replace every hard-coded club value in the SPA

**Files (exact edits):**

| File | Old | New |
|---|---|---|
| `views/LoginView.vue:52`, `views/RegisterView.vue:59` | `Rezervácie KVŠ` | `{{ site.config.shortName }}` (import + `const site = useSiteStore()`) |
| `views/DashboardView.vue:128-131` | mailto `rezervacie@lodenicakvs.sk` | `<template v-if="site.config.contactEmail">V prípade otázok napíš na <a :href="\`mailto:${site.config.contactEmail}\`">{{ site.config.contactEmail }}</a>.</template>` |
| `views/DashboardView.vue:359-369` footer | mailto with subject `Lodenica KVS — feedback` | `v-if="site.config.contactEmail"` on `<footer>`; `:href="\`mailto:${site.config.contactEmail}?subject=${encodeURIComponent(site.config.shortName + ' — spätná väzba')}\`"` |
| `views/DashboardView.vue:210` | `<PaddlingTrafficLightWidget />` | `<PaddlingTrafficLightWidget v-if="site.config.features.paddlingTrafficLight" />` |
| `components/ui/GdprConsentFields.vue:22-26,39-45,97-104` | constants + KVŠ text | `const site = useSiteStore()`; operator paragraph `<p v-if="site.config.operatorNotice">{{ site.config.operatorNotice }}</p>` + generic sentence "Odoslaním prihlášky potvrdzujem, že som sa oboznámil/a s <ExtLink :href="site.config.gdprNoticeUrl">Oznámením o spracúvaní osobných údajov</ExtLink> klubu."; consent text "…na účely propagácie klubu podľa podmienok <ExtLink :href="site.config.gdprConsentUrl">GDPR súhlasu dotknutej osoby</ExtLink>"; rules: "oboznámil so <ExtLink :href="site.config.statutesUrl">stanovami klubu</ExtLink> a <ExtLink :href="site.config.rulesUrl">prevádzkovým poriadkom lodenice</ExtLink>" |
| new `components/ui/ExtLink.vue` | — | `props { href: string \| null }`; renders `<a v-if="href" :href target="_blank" rel="noopener noreferrer" class="font-medium text-brand-700 hover:underline"><slot/></a><span v-else class="font-medium"><slot/></span>` |
| `views/ReservationFormView.vue:124` | `` `Lodenica KVŠ: …` `` | `` `${site.config.siteName}: …` `` and pass `location: site.config.address, mapsUrl: site.config.mapsUrl` |
| `views/ReservationFormView.vue:940-953` | KVŠ anchor | `<template v-if="site.config.rulesUrl"> a som si vedomý/á <a :href="site.config.rulesUrl" …>prevádzkového poriadku</a></template>` |
| `views/ReservationFormView.vue:438,447,465` | `kvs_resv_prompt` | `app.resvPrompt` |
| `api/reservations.api.ts:26-28,47,60` | `KVS_LOCATION`, `KVS_MAPS_URL` | new opts `location: string; mapsUrl: string \| null`; description starts with `opts.mapsUrl` when set; `location: opts.location` |
| `components/ui/ReservationEditDialog.vue:80` | `'Lodenica KVŠ'` | `site.config.siteName` |
| `components/ui/RiverNavigability.vue:118` | mailto KVŠ | `<ExtLink :href="site.config.contactEmail ? \`mailto:${site.config.contactEmail}?subject=Limity%20splavnosti\` : null">pošli nám ich</ExtLink>` |
| `views/ExpeditionsView.vue:235` | `t.bocinec@gmail.com` | `v-if="site.config.contactEmail"` + `{{ site.config.contactEmail }}` |
| `views/AdminDataView.vue:32,46,60,74` | `lodenica-*` | `zaloha-`, `rezervacie-`, `lode-`, `clenovia-` |
| `utils/qr.ts:10` | fallback `https://rezervacie.lodenicakvs.sk` | `''` |
| `views/AdminUsersView.vue:285,324,384`, `views/MemberRosterView.vue:179,207`, `components/ui/UserDetailDialog.vue:160` | `napr. KVS-001` / CSV example | `` :placeholder="`napr. ${site.config.memberIdExample}`" `` / `` :placeholder="`${site.config.memberIdExample},Ján Novák,jan@example.com`" `` |
| `main.ts:20-21` | `kvs_visit` | `app.visit` |
| `index.html:11` | `Lodenica KVŠ · Správa rezervácií` | `Rezervácie` |
| `index.html:5-7` | png favicons | `<link rel="icon" type="image/svg+xml" href="/favicon.svg" />` |
| `public/favicon-*.png` | — | `git mv` to `branding/kvs/logo-32.png`, `logo-180.png`, `logo-192.png` |
| new `public/favicon.svg` | — | neutral 64×64 rounded square in brand blue with a white paddle glyph (hand-written SVG) |

- [ ] **Step 1: Write the guard test** `frontend/src/no-hardcoded-brand.spec.ts`

```ts
import { readdirSync, readFileSync, statSync } from 'node:fs';
import { join } from 'node:path';
import { describe, expect, it } from 'vitest';

const SRC = join(__dirname);
const FORBIDDEN = /KVŠ|KVS-|lodenicakvs|Lodenica KVŠ|t\.bocinec@/;

function walk(dir: string): string[] {
  return readdirSync(dir).flatMap((name) => {
    const p = join(dir, name);
    return statSync(p).isDirectory() ? walk(p) : [p];
  });
}

describe('SITE-004: no hard-coded club identity in the SPA', () => {
  it('finds no forbidden literal in src/', () => {
    const offenders = walk(SRC)
      .filter((p) => /\.(vue|ts|css)$/.test(p) && !p.endsWith('no-hardcoded-brand.spec.ts'))
      .filter((p) => FORBIDDEN.test(readFileSync(p, 'utf8')));
    expect(offenders).toEqual([]);
  });
});
```

- [ ] **Step 2: Run** → lists ~15 offenders. **Step 3: Apply the table.** **Step 4: Run** `pnpm test && pnpm typecheck`; also `grep -rn -e 'KVŠ' -e 'lodenicakvs' src index.html` → empty. Update `DashboardView.spec.ts` stubs if `ExtLink` is needed.

- [ ] **Step 5: Commit**

```bash
git add frontend/src frontend/index.html frontend/public branding
git commit -m "feat(frontend): every club-specific value comes from the site config"
```

---

### Task 12: Admin page *Nastavenia stránky*

**Files:**
- Create: `frontend/src/views/AdminSiteSettingsView.vue`, `frontend/src/views/AdminSiteSettingsView.spec.ts`
- Modify: `frontend/src/router/index.ts` (route `/admin/site`, title `Nastavenia stránky`, `auth: 'admin'`)

- [ ] **Step 1: Failing test** — mock `@/api/site.api` (`adminGet` resolves a config with `siteName: 'Klub Test'`, `update` resolves the merged config); mount; expect the input `#site-siteName` value `Klub Test`; set `#site-contactEmail` to `klub@example.test`, click *Uložiť*; expect `update` called once with exactly `{ contactEmail: 'klub@example.test' }`; expect success text `Nastavenia uložené.`; the site store's `config.contactEmail` equals the new value.

- [ ] **Step 2: Run** → fails. **Step 3: Implement the view**

Structure: `PageHeader title="Nastavenia stránky" subtitle="Názov, kontakty, odkazy, moduly a vzhľad tejto inštalácie."`; `LoadError`; sections (each `card-padded`):
1. **Identita** — `siteName`, `shortName`, `clubName` (inputs, `label` + `input` classes, ids `site-<field>`; helper text under each explaining where it shows).
2. **Kontakty a miesto** — `contactEmail`, `adminEmail` (hint: "kam chodia upozornenia na nových členov; prázdne = kontaktný e-mail"), `address`, `mapsUrl`.
3. **Odkazy** — `websiteUrl`, `rulesUrl`, `gdprNoticeUrl`, `gdprConsentUrl`, `statutesUrl` (hint: prázdny odkaz sa v menu a formulároch skryje).
4. **Registrácia** — `operatorNotice` textarea, `memberIdExample`.
5. **Moduly** — two checkboxes with descriptions (Vodácky semafor: "Dunaj v Bratislave, zdroj dunajcik.sk a SHMÚ"; Expedície).
6. **Vzhľad** — theme radio swatches from `THEMES` (a 3-colour strip per theme, label), selecting previews immediately via `applyTheme` and reverts on cancel/leave (`onBeforeUnmount` → re-apply resolved theme).
7. **Logo** — current logo `<img>` or placeholder; file input (png/jpg/webp ≤ 2 MB) → `siteApi.uploadLogo`; *Odstrániť logo* → `removeLogo`.
Sticky bottom bar: *Uložiť* (disabled when no diff), *Zahodiť zmeny*. `diff()` compares `form` vs `loaded` and emits only changed keys, `''` → `null`, features as a nested partial. On success: `site.apply(result)`, `loaded = result`, info message. Validation errors: `ApiError.details` is `Record<string, string[]>` → map to per-field messages under inputs; fallback to `error.message`.

- [ ] **Step 4: Run** `pnpm test && pnpm typecheck`. **Step 5: Commit** `feat(frontend): admin site settings page`.

---

### Task 13: Theme picker in the profile

**Files:**
- Modify: `frontend/src/views/ProfileView.vue` (new section after *E-mailové notifikácie*), `frontend/src/api/profile.api.ts` (`setAppearance(theme: string | null): Promise<User>`), `frontend/src/stores/auth.store.ts` (`setUser(u: User)` helper or assign `auth.user = …`)
- Modify test: `frontend/src/views/ProfileView.spec.ts` — add a case: mock `profileApi.setAppearance` resolving `{ …user, theme: 'forest' }`; click the *Les* swatch; expect the call with `'forest'`, `auth.user.theme === 'forest'`, `document.documentElement.dataset.theme === 'forest'`; click *Predvolená téma stránky* → call with `null`.

- [ ] Steps: failing test → implement (`section` "Vzhľad" listing `THEMES` as radio-like buttons + a "Predvolená téma stránky" option; on choose → `profileApi.setAppearance` → `auth.user = result`; `useSiteChrome`'s `watchEffect` re-applies) → run → commit `feat(profile): members choose their colour theme`.

---

## Part C — Install, deploy, docs

### Task 14: Deploy script, deploy-all, secrets, workflow

**Files:**
- Modify: `scripts/deploy-rezervacie.sh`, `.deploy-secrets.example`, `.github/workflows/deploy-rezervacie.yml`
- Create: `scripts/deploy-all.sh`
- Test: `backend-php/tests/Feature/DeployScriptIsClientNeutralTest.php`
- Local only (not committed, gitignored): add the KVŠ `SITE_*` block to `.deploy-secrets` and `.deploy-secrets.test`.

- [ ] **Step 1: Failing test**

```php
<?php
namespace Tests\Feature;

use Tests\TestCase;

/** The deploy script must work for any club: every identity value comes from the secrets file. */
class DeployScriptIsClientNeutralTest extends TestCase
{
    private function script(): string
    {
        $path = base_path('../scripts/deploy-rezervacie.sh');
        if (!is_readable($path)) { $this->markTestSkipped('deploy script not available here.'); }
        return (string) file_get_contents($path);
    }

    public function test_no_club_literal_in_the_deploy_script(): void
    {
        $this->assertDoesNotMatchRegularExpression('/lodenicakvs|KVŠ|Lodenica KVS/u', $this->script());
    }

    public function test_site_name_is_required_and_written_to_env(): void
    {
        $s = $this->script();
        $this->assertStringContainsString('require_var SITE_NAME', $s);
        $this->assertStringContainsString("printf 'SITE_NAME=\"%s\"\\n'", $s);
        $this->assertStringContainsString("printf 'ADMIN_EMAIL=%s\\n'", $s);
    }

    public function test_logo_file_and_client_flag_are_supported(): void
    {
        $s = $this->script();
        $this->assertStringContainsString('SITE_LOGO_FILE', $s);
        $this->assertStringContainsString('--client', $s);
    }
}
```

- [ ] **Step 2: Run** → fails. **Step 3: Edit the script**

1. Usage text: add `--client SLUG   Shortcut for --secrets .deploy-secrets.SLUG` and list `SITE_NAME` under Required, the `SITE_*`, `ADMIN_*`, `SITE_LOGO_FILE`, `MAIL_*` under Optional. Flag parsing: `--client) shift; SECRETS="$REPO_ROOT/.deploy-secrets.$1" ;;` and `--client=*)`.
2. After sourcing secrets: `require_var … PROD_DOMAIN SITE_NAME`; `STAGE="${LODENICA_DEPLOY_STAGE:-/tmp/lodenica-deploy-$PROD_DOMAIN}"` (move the `STAGE=` line below the secrets load).
3. `.env` block: `printf 'APP_NAME="%s"\n' "$SITE_NAME"`; after `FILESYSTEM_DISK`: 
```bash
printf '\n# Site identity — install-time defaults; the admin overrides them in the SPA.\n'
printf 'SITE_NAME="%s"\n' "$SITE_NAME"
for v in SITE_SHORT_NAME SITE_CLUB_NAME SITE_CONTACT_EMAIL SITE_ADDRESS SITE_MAPS_URL SITE_WEBSITE_URL SITE_RULES_URL SITE_GDPR_NOTICE_URL SITE_GDPR_CONSENT_URL SITE_STATUTES_URL SITE_OPERATOR_NOTICE SITE_MEMBER_ID_EXAMPLE SITE_THEME SITE_FEATURE_TRAFFIC_LIGHT SITE_FEATURE_EXPEDITIONS MAIL_ADMIN_ADDRESS; do
    [[ -n "${!v:-}" ]] && printf '%s="%s"\n' "$v" "${!v}"
done
printf '\n# First-install admin (ignored once an admin exists).\n'
printf 'ADMIN_EMAIL=%s\n' "${ADMIN_EMAIL:-}"
printf 'ADMIN_PASSWORD="%s"\n' "${ADMIN_PASSWORD:-}"
printf 'ADMIN_NAME="%s"\n' "${ADMIN_NAME:-}"
```
4. Mail: replace the two KVŠ defaults: `if [[ "$MAIL_MAILER_EFFECTIVE" == "smtp" ]]; then require_var MAIL_USERNAME MAIL_FROM_ADDRESS; fi`; `printf 'MAIL_USERNAME=%s\n' "${MAIL_USERNAME:-}"`, `printf 'MAIL_FROM_ADDRESS=%s\n' "${MAIL_FROM_ADDRESS:-}"`, `printf 'MAIL_FROM_NAME="%s"\n' "${MAIL_FROM_NAME:-$SITE_NAME}"`.
5. Logo: after the lftp mirror, when `SITE_LOGO_FILE` is set and readable:
```bash
if [[ -n "${SITE_LOGO_FILE:-}" ]]; then
    [[ -r "$SITE_LOGO_FILE" ]] || die "SITE_LOGO_FILE not readable: $SITE_LOGO_FILE"
    LOGO_EXT="${SITE_LOGO_FILE##*.}"
    log "Uploading site logo ($SITE_LOGO_FILE) → storage/app/private/site/logo.$LOGO_EXT"
    lftp -u "$DEPLOY_SFTP_USER,$DEPLOY_SFTP_PASSWORD" -p "$DEPLOY_SFTP_PORT" "sftp://$DEPLOY_SFTP_HOST" <<LFTP
set sftp:auto-confirm yes
mkdir -fp $DEPLOY_LARAVEL_APP_REMOTE/storage/app/private/site
put -O $DEPLOY_LARAVEL_APP_REMOTE/storage/app/private/site/ "$SITE_LOGO_FILE" -o logo.$LOGO_EXT
bye
LFTP
fi
```
6. Header comment: "Deploy the Lodenica PHP backend + Vue SPA for ONE client…"; any remaining `rezervacie.lodenicakvs.sk` example in comments → `<client-domain>`.

`scripts/deploy-all.sh`:
```bash
#!/usr/bin/env bash
# Roll the current checkout out to EVERY client: one deploy per
# .deploy-secrets.<slug> file (the .example and .test files are skipped
# unless named explicitly). Extra flags are passed through to
# scripts/deploy-rezervacie.sh. Stops at the first failing client.
#
#   scripts/deploy-all.sh                 # all clients, asks first
#   scripts/deploy-all.sh --yes           # no confirmation (CI)
#   scripts/deploy-all.sh --only kvs,klub2
set -euo pipefail
cd "$(dirname "${BASH_SOURCE[0]}")/.."
YES=0; ONLY=""; PASS=()
while [[ $# -gt 0 ]]; do case "$1" in --yes) YES=1;; --only) shift; ONLY="$1";; *) PASS+=("$1");; esac; shift; done
mapfile -t FILES < <(ls .deploy-secrets.* 2>/dev/null | grep -v -e '\.example$' -e '\.test$' || true)
if [[ -n "$ONLY" ]]; then FILES=(); IFS=, read -ra SLUGS <<< "$ONLY"; for s in "${SLUGS[@]}"; do FILES+=(".deploy-secrets.$s"); done; fi
[[ ${#FILES[@]} -gt 0 ]] || { echo "No .deploy-secrets.<slug> files found."; exit 1; }
echo "Clients to deploy:"; for f in "${FILES[@]}"; do d=$(grep -E '^PROD_DOMAIN=' "$f" | cut -d= -f2- | tr -d "'\""); printf '  %-28s %s\n' "$f" "$d"; done
if (( ! YES )); then read -r -p "Continue? [y/N] " a; [[ "$a" == y || "$a" == Y ]] || exit 1; fi
for f in "${FILES[@]}"; do echo; echo "════ $f ════"; scripts/deploy-rezervacie.sh --secrets "$f" "${PASS[@]}"; done
echo; echo "All clients deployed."
```
`chmod +x scripts/deploy-all.sh`.

`.deploy-secrets.example`: add a commented block
```bash
#────────────────────────────────────────────────────────────────────────────
# Site identity (install-time defaults; the admin can override every value
# in the SPA under Administrácia → Systém → Nastavenia stránky)
#────────────────────────────────────────────────────────────────────────────
SITE_NAME='Lodenica Klub'                 # REQUIRED — e-mails, ICS, browser title
SITE_SHORT_NAME='Rezervácie Klub'          # header + login heading (default: SITE_NAME)
SITE_CLUB_NAME='Vodácky klub …'           # full club name for content pages
SITE_CONTACT_EMAIL='rezervacie@example.sk' # footer, FAQ, "waiting" banner
# MAIL_ADMIN_ADDRESS='spravca@example.sk'  # new-member notices (default: SITE_CONTACT_EMAIL)
SITE_ADDRESS='Ulica 1, 900 01 Mesto'       # ICS / Google Calendar location
# SITE_MAPS_URL='https://maps.app.goo.gl/…'
# SITE_WEBSITE_URL= SITE_RULES_URL= SITE_GDPR_NOTICE_URL= SITE_GDPR_CONSENT_URL= SITE_STATUTES_URL=
# SITE_OPERATOR_NOTICE='Prevádzkovateľ: …, IČO: …'
# SITE_MEMBER_ID_EXAMPLE='001'
# SITE_THEME='ocean'                       # ocean | forest | sunset | berry | graphite
SITE_FEATURE_TRAFFIC_LIGHT='false'         # Danube paddling traffic light (Bratislava only)
SITE_FEATURE_EXPEDITIONS='true'
# SITE_LOGO_FILE='branding/<slug>/logo-192.png'  # uploaded at deploy; png/jpg/webp

# First install only — creates the first admin. Ignored once an admin exists.
ADMIN_EMAIL='admin@example.sk'
ADMIN_PASSWORD='change-me-now'
ADMIN_NAME='Správca'

# Transactional e-mail (Websupport SMTP). All three required when MAIL_PASSWORD is set.
# MAIL_HOST='smtp.m1.websupport.sk' MAIL_PORT='465'
MAIL_USERNAME='potvrdenie@example.sk'
MAIL_FROM_ADDRESS='potvrdenie@example.sk'
MAIL_PASSWORD=''
# MAIL_FROM_NAME defaults to SITE_NAME
```

Workflow: input `environment` (`type: string`, default `rezervace.kvs`, description "GitHub Environment holding this client's REZERVACIE_* values"); `environment: { name: ${{ inputs.environment }}, url: https://${{ vars.REZERVACIE_DOMAIN }} }`; in *Write .deploy-secrets* add env + printf lines for `SITE_NAME` (from `vars.REZERVACIE_SITE_NAME`), `SITE_SHORT_NAME`, `SITE_CLUB_NAME`, `SITE_CONTACT_EMAIL`, `SITE_FEATURE_TRAFFIC_LIGHT`, `MAIL_USERNAME`, `MAIL_FROM_ADDRESS`, `MAIL_PASSWORD` (secret), `ADMIN_EMAIL`, `ADMIN_PASSWORD` (secret) — each `[[ -n "$X" ]] && printf 'X=%s\n' "$X"` style so absent values stay absent; add `SITE_NAME` to the required-values check. Update the header comment block listing the new variables.

Local secrets (`.deploy-secrets`, `.deploy-secrets.test` — NOT committed): append the KVŠ block: `SITE_NAME='Lodenica KVŠ'`, `SITE_SHORT_NAME='Rezervácie KVŠ'`, `SITE_CLUB_NAME='Klub vodných športov Karlova Ves'`, `SITE_CONTACT_EMAIL='rezervacie@lodenicakvs.sk'`, `SITE_ADDRESS='Klub vodných športov Karlova Ves, Botanická 20/59, 841 04 Bratislava-Karlova Ves, Slovakia'`, `SITE_MAPS_URL='https://maps.app.goo.gl/zZwKA168QCeugSxA8'`, `SITE_WEBSITE_URL='https://www.lodenicakvs.sk'`, `SITE_RULES_URL='https://www.lodenicakvs.sk/?page_id=4578'`, `SITE_GDPR_NOTICE_URL='https://www.lodenicakvs.sk/?page_id=5024'`, `SITE_GDPR_CONSENT_URL='https://www.lodenicakvs.sk/?page_id=5036'`, `SITE_STATUTES_URL='https://www.lodenicakvs.sk/?page_id=4698'`, `SITE_OPERATOR_NOTICE='Prevádzkovateľ: Klub vodných športov, Karlova Ves (KVŠ), Botanická 59, 841 04 Bratislava, IČO: 17315115.'`, `SITE_MEMBER_ID_EXAMPLE='KVS-001'`, `SITE_FEATURE_TRAFFIC_LIGHT='true'`, `SITE_LOGO_FILE='branding/kvs/logo-192.png'`, `MAIL_USERNAME`/`MAIL_FROM_ADDRESS` = `potvrdenie.rezervacie@lodenicakvs.sk` if not already present.

- [ ] **Step 4: Run** `vendor/bin/phpunit tests/Feature/DeployScriptIsClientNeutralTest.php tests/Feature/DeployProtectsUploadedFilesTest.php`; `bash -n scripts/deploy-rezervacie.sh scripts/deploy-all.sh`; `scripts/deploy-rezervacie.sh --help`. Expected: green, syntax OK.

- [ ] **Step 5: Commit**

```bash
git add scripts/deploy-rezervacie.sh scripts/deploy-all.sh .deploy-secrets.example .github/workflows/deploy-rezervacie.yml backend-php/tests/Feature/DeployScriptIsClientNeutralTest.php
git commit -m "feat(deploy): client-neutral deploy with --client, site identity and logo from secrets"
```

---

### Task 15: Local dev defaults + documentation

**Files:**
- Modify: `docker-compose.yml` (backend env: `SITE_NAME: "Lodenica (dev)"`, `SITE_FEATURE_TRAFFIC_LIGHT: "true"`, `ADMIN_EMAIL: admin@lodenica.sk`, `ADMIN_PASSWORD: "Lodenica2026!"`), `docker/backend/entrypoint.sh` (pass the same into `.env`), `backend-php/.env.example` (SITE_* block)
- Create: `docs/CLIENT-ONBOARDING.md`, `docs/spec/13-site-configuration.md`
- Modify: `AGENTS.md`, `README.md`, `docs/AUTH-AND-PERMISSIONS.md`

- [ ] **Step 1: `docs/CLIENT-ONBOARDING.md`** — sections: *What a client is* (domain + DB + secrets file + uploads; shared code), *Checklist* (1 hosting with PHP 8.3 + SFTP, managed Postgres, mailbox for sending, DNS; 2 `cp .deploy-secrets.example .deploy-secrets.<slug>` and fill SFTP/DB/domain/SITE_*/ADMIN_*/MAIL_*; 3 optional `branding/<slug>/logo-192.png` + `SITE_LOGO_FILE`; 4 `scripts/deploy-rezervacie.sh --client <slug> --explore` then `scripts/deploy-rezervacie.sh --client <slug>`; 5 log in as ADMIN_EMAIL, change password, open *Nastavenia stránky*, check content pages under *Informácie*, import the member roster; 6 for CI: new GitHub Environment `<slug>` with the `REZERVACIE_*` values, run the workflow with `environment=<slug>`), *Updating all clients* (`scripts/deploy-all.sh`), *What is per client vs shared* table, *Removing a client*.
- [ ] **Step 2: `docs/spec/13-site-configuration.md`** — `SITE-001..004`, `NAV-001..002`, `THEME-001`, `INST-001..002` in the spec's format with Enforced/Test lines pointing at the classes and tests created above.
- [ ] **Step 3: `AGENTS.md`** — new section *One codebase, many clients* (never hard-code a club value; add a `SiteConfig` field + env default + `.deploy-secrets.example` line + admin form field; per-client secrets files; `deploy-all.sh`); update *Migrations and seed* table (`db:seed` = AdminSeeder + ContentSeeder + DemoDataSeeder; INST-001/002); update the onboarding checklist to point at `docs/CLIENT-ONBOARDING.md`.
- [ ] **Step 4: `README.md`** — replace the "First admin is seeded…" paragraph (credentials now come from `ADMIN_*`; dev default only in docker), add a *Branding / multiple clients* paragraph linking the onboarding doc, mention `/admin/site`.
- [ ] **Step 5: `docs/AUTH-AND-PERMISSIONS.md`** — read matrix: `Site config (GET /site)` ✅✅✅✅, `Site config incl. adminEmail (GET /admin/site)` ❌❌❌✅; write matrix: `Edit site config / logo (PATCH /admin/site, POST/DELETE /admin/site/logo)` ❌❌❌✅, `Set own theme (PATCH /profile/appearance)` ❌(401) ✅ ✅ ✅.
- [ ] **Step 6: Verify** `docker compose config >/dev/null`, `bash -n docker/backend/entrypoint.sh`, full backend + frontend suites one last time.
- [ ] **Step 7: Commit**

```bash
git add docker-compose.yml docker/backend/entrypoint.sh backend-php/.env.example docs/CLIENT-ONBOARDING.md docs/spec/13-site-configuration.md AGENTS.md README.md docs/AUTH-AND-PERMISSIONS.md
git commit -m "docs: client onboarding, site configuration rules and dev defaults for many clients"
```

---

## Self-review

- **Spec coverage:** §3.1–3.3 → Tasks 1–2; feature gating → 3; §3.4 mail/ICS/exports → 4–5; §3.5 SPA consumers → 9–11; §4 nav → 10; §5 themes → 6, 8, 9, 13; §6.1 seeding → 7; §6.2–6.3 deploy → 14; §6.4–6.5 dev + docs → 15; §7 tests → each task. `MailDiagnosticsController` admin address → Task 4. `config/mail.php` default → Task 4.
- **Type consistency:** `SiteConfig::update()` returns the resolved array used by `SiteController::update`; the SPA's `SiteConfig`/`AdminSiteConfig` mirror `publicPayload()`/`all()`; `applyTheme`/`resolveTheme`/`readCachedTheme`/`writeCachedTheme` names match between Tasks 8, 9, 12, 13; `features` keys `paddlingTrafficLight`/`expeditions` identical on both sides and in `EnsureFeature` route parameters.
- **Placeholders:** none; Task 11 is a literal edit table; Task 15 lists section contents.
