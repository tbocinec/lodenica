# Multi-client parametrisation: site configuration, admin menu, themes, clean install

**Date:** 2026-09-05
**Status:** implemented on branch `feat/multi-client-site-config` (2026-09-05); implementation plan in
`docs/superpowers/plans/2026-09-05-multi-client-site-config.md`
**Scope:** `backend-php/`, `frontend/`, `scripts/`, `.github/workflows/`,
`docs/`, `docker/`, `.deploy-secrets.example`

## 1. Goal

A second club wants the same application. Today the KVŠ identity is
hard-coded on both sides (name, logo, contact e-mail, external GDPR links,
boathouse address, Danube traffic light, member-ID example, e-mail subjects,
seeded content pages), the seeder puts demo boats into every empty
database, and the deploy script carries KVŠ mail defaults.

After this work:

- **Every client-specific value is a setting** an admin edits in the UI
  under *Administrácia → Systém → Nastavenia stránky*, with sensible
  install-time defaults coming from the deploy secrets.
- **The admin navigation is grouped**: an *Administrácia* section with two
  sub-groups, *Správa* (people and records) and *Systém* (site settings,
  mail diagnostics, data management).
- **Colour themes**: the admin picks the site default, every user may pick
  their own in the profile.
- **A clean install per client is one secrets file + one deploy run**, and
  releasing a change to every client is one command. One codebase, N
  databases; Laravel migrations already track per database.

## 2. Decisions taken with the product owner

| Question | Decision |
|---|---|
| Multi-tenant in one DB or one install per client? | **One install per client** (own domain, DB, secrets file, uploads). No tenant column. |
| Where do client values live? | JSON row `site_config` in the existing `settings` table, read through a `SiteConfig` service with **layered defaults**: DB value → `.env` (`SITE_*`) → neutral built-in default. |
| *História zmien* placement | Admin sees it under *Administrácia → Správa*; a non-admin member keeps it in the main nav. |
| Logo | Bundled favicon becomes neutral. The KVŠ turtle moves to `branding/kvs/` and reaches the KVŠ instance through `SITE_LOGO_FILE` in its secrets at deploy time. Admin can upload/replace/remove it in the UI. |
| Experimental *Expedície* banner e-mail | Uses the club contact e-mail (no separate feedback address). |
| Dark mode | Out of scope. Colour themes only. |
| Deploy script name | Stays `scripts/deploy-rezervacie.sh`; gains `--client <slug>`. |
| KVŠ-specific features | Behind feature switches in site config: *vodácky semafor* (default off, on for KVŠ via secrets) and *expedície* (default on). |
| Default content pages (rules, FAQ, privacy) | Move out of migrations into a templated, idempotent seeder. Existing KVŠ rows are never touched. |
| First admin on a fresh production install | Must come from `ADMIN_EMAIL` + `ADMIN_PASSWORD` in the secrets. No known default password in production. |
| Demo boats | Seeded only in the `local` environment (or `SEED_DEMO_DATA=true`). |

## 3. Site configuration (`SITE-*`)

### 3.1 Storage and resolution

- One row `settings.key = 'site_config'`, `value` = JSON object of the
  fields below. Only keys the admin has saved are present.
- `App\Services\SiteConfig` resolves each field: stored value if the key is
  present and not `null` → `config('site.<field>')` (new `config/site.php`
  reading `SITE_*` env) → built-in default.
- Writes go through `SettingsService::set()` so they land in the audit log
  as today's settings do (`SETTING` / `site_config`).
- The resolved config is computed per request (one `settings` read); no
  extra cache layer.

### 3.2 Fields

| Field | Type / rule | Env default | Built-in default | Used by |
|---|---|---|---|---|
| `siteName` | string 1–80 | `SITE_NAME` | `Lodenica` | e-mail subjects and layout, ICS, `<title>`, mail from-name |
| `shortName` | string 1–40 | `SITE_SHORT_NAME` | = `siteName` | header, login/register heading |
| `clubName` | string 0–160 | `SITE_CLUB_NAME` | `''` | content templates, GDPR text |
| `contactEmail` | e-mail or null | `SITE_CONTACT_EMAIL` | `null` | dashboard footer + pending banner, FAQ template, navigability, expeditions banner |
| `adminEmail` | e-mail or null | `MAIL_ADMIN_ADDRESS` | = `contactEmail` | `AdminNotifier` (new pending member), mail diagnostics. **Not in the public payload.** |
| `address` | string 0–200 | `SITE_ADDRESS` | `''` | ICS `LOCATION`, Google Calendar `location` |
| `mapsUrl` | URL or null | `SITE_MAPS_URL` | `null` | first line of ICS / GCal description |
| `websiteUrl` | URL or null | `SITE_WEBSITE_URL` | `null` | *Informácie* nav |
| `rulesUrl` | URL or null | `SITE_RULES_URL` | `null` | *Informácie* nav, booking-form consent, content templates |
| `gdprNoticeUrl` | URL or null | `SITE_GDPR_NOTICE_URL` | `null` | *Informácie* nav, registration consent |
| `gdprConsentUrl` | URL or null | `SITE_GDPR_CONSENT_URL` | `null` | *Informácie* nav, registration consent |
| `statutesUrl` | URL or null | `SITE_STATUTES_URL` | `null` | registration consent |
| `operatorNotice` | string 0–500 | `SITE_OPERATOR_NOTICE` | `''` | the "Prevádzkovateľ: …" sentence in the registration consent block |
| `memberIdExample` | string 0–40 | `SITE_MEMBER_ID_EXAMPLE` | `001` | input placeholders for member ID |
| `features.paddlingTrafficLight` | bool | `SITE_FEATURE_TRAFFIC_LIGHT` | `false` | nav, dashboard widget, route, API |
| `features.expeditions` | bool | `SITE_FEATURE_EXPEDITIONS` | `true` | nav, routes, API |
| `theme` | slug `^[a-z][a-z0-9-]{1,31}$` | `SITE_THEME` | `ocean` | default colour theme |

Logo is separate: setting `site_logo_path` (string, path on the `local`
disk, e.g. `site/logo.png`). When the key is missing, `SiteConfig` looks for
`site/logo.{png,jpg,jpeg,webp}` on the disk, so a file placed there at
deploy time is picked up without a DB write.

**SITE-001** — A field the admin has never saved MUST resolve to the `.env`
value, and when that is unset, to the built-in default. A saved value wins
over both.

**SITE-002** — `adminEmail` MUST NOT appear in the public payload.

### 3.3 API

| Endpoint | Access | Behaviour |
|---|---|---|
| `GET /api/v1/site` | public | Resolved config without `adminEmail`, plus `logoUrl` (`/api/v1/site/logo?v=<mtime>` or `null`). |
| `GET /api/v1/site/logo` | public | Streams the logo (`Cache-Control: public, max-age=86400`), 404 when none. |
| `GET /api/v1/admin/site` | admin | Resolved config **with** `adminEmail`. |
| `PATCH /api/v1/admin/site` | admin | Partial update: only sent keys change, `null` clears a stored value so the default applies again. Validated by `UpdateSiteConfigRequest` (rules in 3.2; unknown keys → 400 `VALIDATION_ERROR`). Returns the resolved config. |
| `POST /api/v1/admin/site/logo` | admin | multipart `logo`: png/jpg/webp, max 2 MB. Replaces the previous file. Returns the resolved config. |
| `DELETE /api/v1/admin/site/logo` | admin | Removes file and setting. 204. |

Feature-gated API: `GET /api/v1/paddling-traffic-light` answers **404**
while `features.paddlingTrafficLight` is off; the expedition routes answer
404 while `features.expeditions` is off. Gated in a small middleware
`EnsureFeature:<name>`.

**SITE-003** — `PATCH /admin/site` MUST reject unknown keys and invalid
values with 400 `VALIDATION_ERROR` and MUST NOT partially apply the payload.

### 3.4 Backend consumers

- `NotificationMailer::send()` and the diagnostics test send set the
  mailable's from-name to `siteName` (address stays `MAIL_FROM_ADDRESS`).
- Every mailable subject ends with `— {siteName}` instead of `— Lodenica KVŠ`;
  `emails/layout.blade.php` receives `siteName` for the header and title.
- `AdminNotifier` and `MailDiagnosticsController::config()` use
  `SiteConfig::adminEmail()`.
- `ReservationsController::ics()`: `SUMMARY` prefix `siteName`, `PRODID`
  from `siteName`, `UID` domain from the `APP_URL` host, `mapsUrl` as the
  first description line only when set, `LOCATION` from `address` only when
  set.
- Export file names lose the `lodenica-` prefix: `zaloha-…json`,
  `rezervacie-…csv`, `lode-…csv`, `clenovia-…csv`.
- `config/mail.php` `admin_address` loses its KVŠ default (`null`).

### 3.5 Frontend consumers

- New Pinia store `site.store.ts`: `config`, `load()`, typed getters. Loads
  in `main.ts` in parallel with `auth.bootstrap()` before mount; the last
  good payload is cached in `localStorage['app.site']` so a reload paints
  the right name without a flash. A failed load falls back to the cache,
  then to built-in defaults (the app must still mount).
- `router/index.ts`: `document.title = "<shortName> · <route title>"`;
  routes carry `meta.feature` and the guard redirects to the dashboard when
  the feature is off.
- `AppShell.vue`: logo (`logoUrl` or bundled neutral icon), `shortName`,
  nav from section 4, *Informácie* external links from config (an empty URL
  hides the entry).
- `index.html`: neutral `<title>Rezervácie</title>`, neutral favicon; the
  app swaps the `<link rel="icon">` to `logoUrl` when a logo exists and
  updates `<meta name="theme-color">` from the active theme.
- Replaced hard-coded strings: `LoginView`, `RegisterView` (heading),
  `DashboardView` (banner + footer; footer sentence hidden without
  `contactEmail`), `GdprConsentFields` (`operatorNotice`, four links; a
  missing link renders plain text), `ReservationFormView` (consent link,
  GCal title), `ReservationEditDialog` (subject), `reservations.api.ts`
  (GCal location/maps passed in), `RiverNavigability`, `ExpeditionsView`,
  `AdminDataView` (file names), `qr.ts` (origin only), `main.ts` and
  `ReservationFormView` (`sessionStorage` keys → `app.visit`,
  `app.resvPrompt`), member-ID placeholders (`memberIdExample`).
- Dashboard renders `PaddlingTrafficLightWidget` only when the feature is
  on.

**SITE-004** — Every user-visible occurrence of the club identity in the
SPA MUST come from the site store. Guarded by a Vitest test that greps
`src/` for `KVŠ`, `KVS-`, `lodenicakvs`, `Lodenica KVŠ`.

## 4. Navigation

```
Prehľad · Rezervácie · Na schválenie (approver, hidden for admin) ·
Udalosti · Priestory · Poškodenia · Lode (hidden for admin) ·
Expedície (feature, confirmed) · Môj profil (member) ·
História zmien (member, hidden for admin)

Informácie ▸  Vodácky semafor (feature) · Pravidlá rezervácie ·
              Otázky a odpovede · [websiteUrl] · [gdprNoticeUrl] ·
              [gdprConsentUrl] · [rulesUrl]        (external, from config)

Administrácia ▸ (admin only; auto-expands on its pages)
   Správa   Zdroje (/resources) · Na schválenie · Používatelia ·
            Číselník členov · Štatistiky · História zmien · QR kódy lodí
   Systém   Nastavenia stránky · Diagnostika e-mailov · Správa dát
```

Implementation: `AppShell.vue` builds the tree from a typed `NavGroup[]`
description; one recursive `NavSection` component renders a collapsible
group with optional sub-groups. Existing route paths are unchanged; new
route `/admin/site` (`AdminSiteSettingsView`, `auth: 'admin'`).

**NAV-001** — An admin MUST see every admin page inside *Administrácia*
(including *Zdroje*, *Na schválenie*, *História zmien*) and none of them
twice.
**NAV-002** — A member MUST NOT see the *Administrácia* group.

## 5. Themes

- Tailwind `brand.*` colours become CSS variables:
  `brand: { 50: 'rgb(var(--brand-50) / <alpha-value>)', … }`. Existing
  `brand-*` classes keep working.
- `frontend/src/theme/themes.ts` defines the palette per theme as RGB
  triplets: `ocean` (today's blue, default), `forest`, `sunset`, `berry`,
  `graphite`. `main.css` sets `:root` to `ocean` and
  `[data-theme="<key>"]` overrides.
- `applyTheme(key)` sets `document.documentElement.dataset.theme` and the
  `theme-color` meta. Unknown key → `ocean`.
- Resolution: `auth.user.theme` when set, otherwise `site.theme`.
  Re-applied whenever the user logs in/out or the site config changes.
  `localStorage['app.theme']` only caches the last applied theme so the
  first paint before `/auth/me` and `/site` answer has no flash; there is
  no theme picker for anonymous visitors.
- Persistence: new nullable column `users.theme VARCHAR(32)`;
  `PATCH /api/v1/profile/appearance { theme: slug|null }` (any
  authenticated user; validates the slug pattern; audited). `UserResource`
  exposes `theme`.
- UI: a *Vzhľad* card in `ProfileView` (theme swatches, "use the site
  default" option); a *Vzhľad* section in the admin site settings for the
  default.

**THEME-001** — A user's own theme MUST win over the site default; an
anonymous visitor always sees the site default.

## 6. Clean install, many clients

### 6.1 Seeding

`DatabaseSeeder::run()`:

1. `AdminSeeder` — if any `ADMIN` user exists: ensure the `ADMIN_EMAIL`
   account (when set) is active and admin, otherwise nothing. If none
   exists: create from `ADMIN_EMAIL` + `ADMIN_PASSWORD` (+ `ADMIN_NAME`);
   in `local`/`testing` fall back to `admin@lodenica.sk` /
   `Lodenica2026!`; in any other environment **throw** with a message
   naming the two variables. `install.php` surfaces that as a failed
   step.
2. `ContentSeeder` — inserts `reservation_rules`, `faq`, `privacy_policy`
   only when the key is missing, from `database/seeders/content/*.html`
   templates with `{{siteName}}`, `{{clubName}}`, `{{contactEmail}}`,
   `{{rulesUrl}}` substituted from `SiteConfig`. After substitution any
   `<a>` whose `href` resolved to empty (or bare `mailto:`) is unwrapped to
   its inner text, so a client without a rulebook URL or contact e-mail
   gets plain sentences instead of dead links.
3. `DemoDataSeeder` — the current demo boats/reservations/damage, only
   when `App::environment('local')` or `SEED_DEMO_DATA=true`, and only on
   an empty `resources` table.

Migrations `2026_06_12_220000_create_settings_table` (rules insert),
`2026_06_17_040000_seed_privacy_policy_setting` and
`2026_06_17_050000_seed_faq_setting` stop inserting content (the class
bodies become no-ops / only create the table). They have already run on
every existing database, so nothing changes there; a fresh database gets
its content from `ContentSeeder`.

`deploy/reservation-rules.html` and `deploy/privacy-policy.html` stay as
the KVŠ-specific texts (pushed by `scripts/set-reservation-rules.sh`).

**INST-001** — `php artisan db:seed --force` MUST be idempotent on a live
database and MUST NOT insert demo data outside `local`.
**INST-002** — A production install with no admin and no `ADMIN_*`
variables MUST fail the seed step loudly.

### 6.2 Deploy script (`scripts/deploy-rezervacie.sh`)

- `--client <slug>` ≡ `--secrets .deploy-secrets.<slug>`.
- Stage directory defaults to `/tmp/lodenica-deploy-<PROD_DOMAIN>` so two
  clients never share a stage.
- Required: `SITE_NAME`. Written to `.env`: `APP_NAME="<SITE_NAME>"`, the
  whole `SITE_*` block, `ADMIN_EMAIL`/`ADMIN_PASSWORD`/`ADMIN_NAME` when
  set, `MAIL_ADMIN_ADDRESS` when set.
- Mail: when `MAIL_PASSWORD` is set, `MAIL_USERNAME` and
  `MAIL_FROM_ADDRESS` are **required** (no KVŠ defaults);
  `MAIL_FROM_NAME` defaults to `SITE_NAME`; `MAIL_HOST`/`MAIL_PORT` keep
  the Websupport defaults (hosting-specific, not client-specific).
- `SITE_LOGO_FILE` (optional, local path): uploaded to
  `<laravel>/storage/app/private/site/logo.<ext>` after the mirror.
- No `lodenicakvs` / `KVŠ` literal anywhere in the script (test-guarded
  like the upload-path exclusions).
- New `scripts/deploy-all.sh`: lists every `.deploy-secrets.*` (except
  `.example`), shows the domains, asks for confirmation, runs the deploy
  for each in turn, stops at the first failure. Flags are passed through.

### 6.3 GitHub Actions

`deploy-rezervacie.yml` gains input `environment` (default `rezervace.kvs`)
and uses it as the job environment, `url: https://${{ vars.REZERVACIE_DOMAIN }}`.
New optional values passed into `.deploy-secrets`: `REZERVACIE_SITE_NAME`,
`REZERVACIE_SITE_SHORT_NAME`, `REZERVACIE_SITE_CLUB_NAME`,
`REZERVACIE_SITE_CONTACT_EMAIL`, `REZERVACIE_SITE_FEATURE_TRAFFIC_LIGHT`,
`REZERVACIE_MAIL_USERNAME`, `REZERVACIE_MAIL_FROM_ADDRESS`,
`REZERVACIE_MAIL_PASSWORD`, `REZERVACIE_ADMIN_EMAIL`,
`REZERVACIE_ADMIN_PASSWORD`. Variable names keep the `REZERVACIE_` prefix so
the existing KVŠ environment keeps working; a new client is a new GitHub
Environment with the same variable names.

### 6.4 Local development

`docker-compose.yml` / `docker/backend/entrypoint.sh` pass `SITE_NAME=Lodenica (dev)`,
`SITE_FEATURE_TRAFFIC_LIGHT=true`, `ADMIN_*` dev defaults; `.env.example`
documents the `SITE_*` block.

### 6.5 Documentation

- `docs/CLIENT-ONBOARDING.md` — checklist: hosting + DB + mailbox + DNS,
  `.deploy-secrets.<slug>` from the example (SITE_*, ADMIN_*, MAIL_*),
  first deploy, login, *Nastavenia stránky* (logo, texts, links,
  features, theme), content pages, member roster import, GitHub
  Environment for CI deploys.
- `AGENTS.md` — "one codebase, N databases" section; site config rules
  (never hard-code a club value; add a field to `SiteConfig` instead);
  seeding rules updated (AdminSeeder / ContentSeeder / DemoDataSeeder).
- `README.md` — branding paragraph, default admin paragraph corrected.
- `docs/AUTH-AND-PERMISSIONS.md` — matrix rows for `/site`, `/admin/site`,
  `/profile/appearance`.
- `docs/spec/13-site-configuration.md` — the `SITE-`, `NAV-`, `THEME-`,
  `INST-` rules above in the spec's format (new file only; the spec index
  is the owner's untracked work in progress and is left alone).
- `.deploy-secrets.example` — `SITE_*`, `ADMIN_*`, `SITE_LOGO_FILE`,
  `MAIL_*` block with comments.
- The owner's local `.deploy-secrets` and `.deploy-secrets.test` receive
  the KVŠ `SITE_*` block so the next deploy keeps today's appearance.

## 7. Testing

Backend (PHPUnit):

- `SiteConfigTest` — layered resolution, `null` clears, logo path
  fallback, `adminEmail` fallback to `contactEmail`.
- `SiteApiTest` — public shape (no `adminEmail`, `logoUrl`), admin
  gating (401/403), partial PATCH, validation errors, logo upload / serve
  / delete.
- `ProfileAppearanceApiTest` — set/clear theme, slug validation, exposed
  in `/auth/me`.
- `FeatureGateTest` — traffic light and expedition routes 404 when off.
- `ReservationIcsApiTest` — summary, location and maps line follow config.
- `MailBrandingTest` — subjects and from-name follow `siteName`.
- `SeedersTest` — admin bootstrap rules (INST-002), demo gating
  (INST-001), content templating and idempotence.
- `DeployScriptIsClientNeutralTest` — no `lodenicakvs`/`KVŠ` in the
  deploy script; `SITE_NAME` required; `SITE_LOGO_FILE` handled.
- Existing tests updated where they asserted KVŠ literals
  (`ReservationRulesApiTest::test_default_rules_seed_is_present` runs
  `ContentSeeder` and checks placeholder substitution).

Frontend (Vitest):

- `site.store.spec.ts` — load, cache fallback, defaults.
- `themes.spec.ts` — `applyTheme`, resolution order.
- `AppShell.spec.ts` — nav per role (NAV-001, NAV-002), feature switch,
  external links from config.
- `AdminSiteSettingsView.spec.ts` — loads config, sends only changed
  keys, shows validation errors.
- `no-hardcoded-brand.spec.ts` — SITE-004 grep guard.

Baseline before the work: backend 370 tests (365 passed, 5 skipped),
frontend 49 passed, `pnpm typecheck` clean. Both suites must stay green
after every task.

## 8. Out of scope

Dark mode; multi-tenant single database; translating the UI; changing
the resource taxonomy; renaming the deploy script; migrating GitHub
secret names.
