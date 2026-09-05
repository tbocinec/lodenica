# Site configuration — `SITE`, `NAV`, `THEME`, `INST`

One codebase serves several clubs. Everything that differs between them is
a **site setting**, resolved by `App\Services\SiteConfig` and edited by an
admin under *Administrácia → Systém → Nastavenia stránky*.

Súvisí: [00-conventions.md](00-conventions.md),
`08-notifications.md` *(planned)* (the site name in every e-mail),
`12-admin-operations.md` *(planned)*.

## Model

| Field | Meaning |
|---|---|
| `siteName` | Full name — e-mail subjects and layout, ICS, browser title. |
| `shortName` | Header and login heading. Defaults to `siteName`. |
| `clubName` | Full club name for content pages and consents. |
| `contactEmail` | Public contact (footer, FAQ, "waiting" banner). |
| `adminEmail` | Where new-member notices go. Defaults to `contactEmail`. Admin-only. |
| `address`, `mapsUrl` | Boathouse location for calendar exports. |
| `websiteUrl`, `rulesUrl`, `gdprNoticeUrl`, `gdprConsentUrl`, `statutesUrl` | The club's documents. Empty = hidden wherever it would appear. |
| `operatorNotice` | "Prevádzkovateľ: …" sentence in the registration consent block. |
| `memberIdExample` | Placeholder in member-ID inputs. |
| `features.paddlingTrafficLight`, `features.expeditions` | Module switches. |
| `theme` | Default colour theme key. |
| logo | Uploaded file on the `local` disk (`site/logo.<ext>`). |

## Resolution

**SITE-001** — A field the admin has never saved MUST resolve to the
`.env` value (`config('site.*')`), and when that is unset, to the built-in
default. A saved value wins over both; saving `null` (or an empty string)
removes the stored value so the default applies again.
Enforced: `SiteConfig::get`, `SiteConfig::update`
Test: `SiteConfigTest`

**SITE-002** — `adminEmail` MUST NOT be part of the public payload
(`GET /api/v1/site`).
Enforced: `SiteConfig::publicPayload`
Test: `SiteApiTest::test_public_payload_has_branding_without_admin_email`

**SITE-003** — `PATCH /api/v1/admin/site` MUST reject unknown keys and
invalid values with 400 `VALIDATION_ERROR` and MUST NOT apply any part of a
rejected payload.
Enforced: `UpdateSiteConfigRequest`
Test: `SiteApiTest::test_patch_rejects_invalid_values_and_unknown_keys`

**SITE-004** — No user-visible occurrence of a club's identity (name,
e-mail, URL, member-ID prefix) may be a literal in the code; it MUST come
from the site configuration. Covers the SPA, e-mail subjects and bodies,
the ICS export and the deploy script.
Enforced: `SiteConfig`, `useSiteStore`
Test: `frontend/src/no-hardcoded-brand.spec.ts`, `MailBrandingTest`,
`ReservationIcsApiTest`, `DeployScriptIsClientNeutralTest`

**SITE-005** — A module switched off MUST answer 404 on its API routes and
MUST disappear from the SPA navigation and routes.
Enforced: `EnsureFeature` middleware, router `meta.feature`, `AppShell`
Test: `FeatureGateTest`, `AppShell.spec.ts`

## Navigation

**NAV-001** — An admin MUST find every admin page inside the
*Administrácia* group (sub-groups *Správa* and *Systém*). *Zdroje* (the
resource list), *Na schválenie* and *História zmien* appear there for an
admin and MUST NOT be repeated in the main navigation.
Enforced: `AppShell.vue`
Test: `AppShell.spec.ts`

**NAV-002** — A member MUST NOT see the *Administrácia* group; *Lode*,
*História zmien* and (while something waits for them) *Na schválenie* stay
in the main navigation for them. Anonymous visitors keep *Lode*.
Enforced: `AppShell.vue`
Test: `AppShell.spec.ts`

## Themes

**THEME-001** — A user's own theme (`users.theme`) MUST win over the site
default; an anonymous visitor always sees the site default. `null` means
"use the site default".
Enforced: `resolveTheme`, `useSiteChrome`, `ProfileController::updateAppearance`
Test: `themes.spec.ts`, `ProfileAppearanceApiTest`

## Installation

**INST-001** — `php artisan db:seed --force` MUST be idempotent on a live
database and MUST NOT insert demo data outside the `local` environment
(unless `SEED_DEMO_DATA=true`). Content pages are inserted only when
missing.
Enforced: `DatabaseSeeder`, `ContentSeeder`, `DemoDataSeeder`
Test: `SeedersTest`

**INST-002** — On a database with no admin account, the seed MUST create
one from `ADMIN_EMAIL` / `ADMIN_PASSWORD` and, outside `local`/`testing`,
MUST fail loudly when they are not set. It MUST NOT create an account with
a well-known password in production.
Enforced: `AdminSeeder`
Test: `SeedersTest::test_production_without_credentials_and_without_admin_fails_loudly`

**INST-003** — The deploy script MUST contain no club-specific value; the
club's identity comes from its `.deploy-secrets.<slug>` file
(`SITE_NAME` required).
Enforced: `scripts/deploy-rezervacie.sh`
Test: `DeployScriptIsClientNeutralTest`
