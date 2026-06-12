# Lodenica

Production-grade web application for managing a kayak/canoe club boathouse —
inventory of boats, trailers and boathouse spaces, reservations with conflict
detection, damage tracking, event scheduling, an audit log of every change,
and a live availability dashboard.

Replaces the existing Google Sheet workflow. Code is in English; the UI is
in Slovak.

**Live deployments**

| Site | URL | Layout |
| --- | --- | --- |
| Internal test | https://tomas.gart.sk | SSH Websupport, Laravel outside docroot |
| Public club site | https://rezervacie.lodenicakvs.sk | SFTP-only Websupport, all-in-docroot |

Both are PHP/Laravel + Vue SPA on the same domain; the Laravel API serves
`/api/v1/*` and the SPA's static assets live in the docroot.

## Stack

| Layer    | Technology                                                              |
| -------- | ----------------------------------------------------------------------- |
| Frontend | Vue 3, Vite, TypeScript, Pinia, Vue Router, Tailwind CSS, Vitest        |
| Backend  | Laravel 13, Eloquent, FormRequest validation, Sanctum bearer tokens     |
| Database | PostgreSQL 14+ (with `btree_gist` + `pgcrypto` extensions)              |
| Local dev | Docker Compose stack (postgres + backend + vite) — see below          |
| Hosting  | Websupport shared hosting (managed Postgres + PHP 8.3+)                 |
| Tests    | PHPUnit 12 (backend), Vitest (frontend)                                 |

## Repository layout

```
.
├── README.md                          # this file
├── backend-php/                       # Laravel app — production source of truth
│   ├── app/
│   │   ├── Domain/{Enums,ValueObjects}/
│   │   ├── Services/                  # business logic (one per module)
│   │   ├── Http/{Controllers,Requests,Resources,Middleware}/
│   │   ├── Models/                    # Eloquent (UUID PKs, camelCase columns)
│   │   └── Exceptions/                # domain errors + JSON error renderer
│   ├── database/{migrations,seeders}/
│   ├── routes/api.php                 # /api/v1/* + auth gates
│   ├── tests/{Unit,Feature}/          # 96 tests, all green
│   ├── deploy/                        # docroot bootstrap + install.php template
│   └── DEPLOY-WEBSUPPORT.md           # manual deploy runbook (SSH layout)
├── frontend/                          # Vue 3 SPA — same UI for both deployments
│   └── src/{api,components,stores,views,router,i18n,utils}/
├── docker/
│   └── backend/                       # Dockerfile + entrypoint for local dev
├── docker-compose.yml                 # local dev stack (postgres + backend + vite)
├── scripts/
│   └── deploy-rezervacie.sh           # one-shot deploy for the SFTP layout
├── .deploy-secrets.example            # template — copy to .deploy-secrets (gitignored)
└── .github/workflows/
    └── deploy-rezervacie.yml          # GitHub-Actions wrapper around the deploy script
```

## Architecture

Per-module folders inside `backend-php/app/`:

- **Domain** — enums (`ResourceType`, `ReservationStatus`, `UserRole`, …)
  and value objects (`TimeRange` for half-open `[startsAt, endsAt)`
  intervals).
- **Services** — application use cases that orchestrate persistence and
  enforce invariants. `ReservationsService::assertNoOverlap()` is the
  canonical example.
- **Models** — Eloquent with UUID primary keys, camelCase columns
  (`createdAt`, `updatedAt`, `resourceId`), and enum casts.
- **Http** — thin controllers (FormRequest validation → service →
  JsonResource), per-domain exceptions translated to the stable
  `{statusCode, error, code, message, details}` envelope by
  `ApiExceptionRenderer`.

The Vue frontend uses a feature-by-view structure under `frontend/src/`:
`api/` (typed Axios clients), `stores/` (Pinia), `views/` (route
components), `router/` (with role-based guards), `i18n/` (Slovak labels).

### Reservation overlap — defence in depth

1. **Application layer** — `ReservationsService::assertNoOverlap()` runs
   an inclusive overlap query and raises `ReservationOverlapException`
   with conflicting reservation IDs. Tested at
   `backend-php/tests/Feature/ReservationsServiceTest.php`.
2. **Database layer** — a Postgres `EXCLUDE USING gist` constraint on
   `(resourceId WITH =, tsrange(startsAt, endsAt, '[)') WITH &&)` filtered
   to `status = 'CONFIRMED'` makes overlap physically impossible at the
   DB level. The exclusion violation is mapped back to a 409 by
   `ApiExceptionRenderer::handleQueryException`.

### Auth + RBAC

- Sanctum **bearer tokens** (sessionStorage in the SPA, `Authorization:
  Bearer …` header for API calls). No cookies, no CSRF gymnastics.
- Two roles: **ADMIN** and **MEMBER (Člen)**.
- Anonymous visitors can browse the inventory, make reservations, run
  events and report damages — the club wants a low-friction booking flow.
- Login is required for the **audit log** (`/api/v1/audit-logs`).
- The **ADMIN role** is required for resource CRUD (boat inventory) and
  user management.
- First admin is seeded on first deploy:
  `admin@lodenica.sk` / `Lodenica2026!` — **change it after first
  login**. `ADMIN_EMAIL` / `ADMIN_PASSWORD` env vars override the
  defaults.

### Audit log

Every business change (CREATE / UPDATE / DELETE / CANCEL / activate /
deactivate / attach resources / participants) is recorded in
`audit_logs` with a Slovak `summary`, JSONB `changes` (before/after
diff), the acting user's email as `actor`, and a timestamp. Filter and
browse in the UI at `/audit` (login required).

## API surface

All endpoints under `/api/v1`.

| Group        | Endpoint                                          | Access     |
| ------------ | ------------------------------------------------- | ---------- |
| Auth         | `POST /auth/login`                                | public     |
|              | `GET /auth/me`, `POST /auth/logout`               | logged-in  |
| Resources    | `GET /resources`, `GET /resources/{id}`           | public     |
|              | `POST/PATCH/DELETE /resources`, activate/deactivate | admin     |
| Reservations | `GET/POST/PATCH/DELETE /reservations`, cancel     | public     |
| Events       | `GET/POST/PATCH/DELETE /events`, participants, attach | public  |
| Damages      | `GET/POST/PATCH/DELETE /damages`                  | public     |
| Availability | `GET /availability/dashboard`                     | public     |
| Audit        | `GET /audit-logs`                                 | logged-in  |
| Users        | `GET/POST/PATCH/DELETE /users`                    | admin      |

## Local development

Two equally supported workflows. Pick whichever you prefer; both result
in the same app reachable at http://localhost:5173 talking to
http://localhost:8000/api/v1.

### Option A — Docker Compose (fastest start)

Three containers: Postgres 14, the Laravel backend (`php artisan serve`),
and the Vite dev server.

```bash
docker compose up --build           # first run: builds + migrates + seeds admin
docker compose up                   # subsequent runs
docker compose down                 # stop, keep DB volume
docker compose down -v              # stop + nuke DB volume + admin
```

Log in at http://localhost:5173/login with `admin@lodenica.sk` /
`Lodenica2026!`. The `.env` file inside the backend container is
regenerated from `docker-compose.yml` on every boot, so the developer's
host-side `backend-php/.env` is never touched.

### Option B — Bare metal (host PHP + Node + Postgres)

```bash
# Postgres (any local instance with btree_gist + pgcrypto extensions)
createdb lodenica_php
psql lodenica_php -c "CREATE EXTENSION IF NOT EXISTS btree_gist;"
psql lodenica_php -c "CREATE EXTENSION IF NOT EXISTS pgcrypto;"

# Backend
cd backend-php
cp .env.example .env                       # adjust DB credentials
composer install
php artisan key:generate
php artisan migrate:fresh --seed           # creates schema + bootstraps admin
php artisan serve                           # http://localhost:8000

# Frontend (new terminal)
cd ../frontend
pnpm install
pnpm dev                                    # http://localhost:5173
```

Same admin credentials apply.

## Tests

```bash
# Backend (PHPUnit, runs against SQLite :memory:)
cd backend-php
vendor/bin/phpunit                          # 96 tests, all green

# Frontend (Vitest)
cd frontend
pnpm test                                   # 11 tests
pnpm typecheck                              # vue-tsc strict
```

Key suites:

- `backend-php/tests/Unit/TimeRangeTest.php` — half-open interval semantics
- `backend-php/tests/Feature/ReservationsServiceTest.php` — overlap rules,
  cancellation, status transitions
- `backend-php/tests/Feature/AuditIntegrationTest.php` — audit log fires
  for every service mutation
- `backend-php/tests/Feature/Api/AuthApiTest.php` — login flow, inactive
  users, wrong password
- `backend-php/tests/Feature/Api/UsersApiTest.php` — admin/member RBAC,
  self-protection rules

## Database schema

A single `resources` table discriminated by `type` covers kayaks
(several sub-types), canoes, rowing boats, inflatables, trailers and
boathouse spaces. Type-specific fields (`seats`, `lengthCm`, `weightKg`)
are nullable.

The full schema lives in one consolidated migration:
`backend-php/database/migrations/2026_01_01_000000_create_lodenica_schema.php`.
Additional migrations layer in `audit_logs`, `users` (with a `UserRole`
enum), and Sanctum's `personal_access_tokens` (UUID `tokenable_id`).

## Deployment

### Production: SFTP-only Websupport (rezervacie.lodenicakvs.sk)

One command, end-to-end:

```bash
cp .deploy-secrets.example .deploy-secrets    # fill in real values
chmod 600 .deploy-secrets
scripts/deploy-rezervacie.sh                   # NB: VPN OFF — port 22 needs out
```

The script: composer install (no-dev) → pnpm build → writes `.env` from
secrets → uploads via SFTP (lftp) → triggers `install.php` over HTTPS
which runs `migrate --force`, `db:seed --force` (bootstraps admin if
missing), and rebuilds the artisan caches → self-deletes `install.php` →
smoke-tests the live endpoints.

Re-runnable any time. The destructive sheet importer is only triggered
with the explicit `--import-sheet` flag.

A GitHub Actions wrapper at `.github/workflows/deploy-rezervacie.yml`
runs the same script from a GitHub runner — useful when a corporate VPN
blocks outbound SFTP from the developer laptop.

### Alternative: SSH-enabled Websupport (tomas.gart.sk)

For the older internal-test deployment with SSH + Composer on the box,
see [`backend-php/DEPLOY-WEBSUPPORT.md`](backend-php/DEPLOY-WEBSUPPORT.md)
for the manual runbook.

## License

Internal — UNLICENSED.
