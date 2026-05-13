# Lodenica

Production-grade web application for managing a kayak/canoe club boathouse —
inventory of boats, trailers and boathouse spaces, reservations with conflict
detection, damage tracking, an audit log, and a live availability dashboard.

Replaces the existing Google Sheet workflow. Code is in English; the UI is in
Slovak. **Live at https://tomas.gart.sk** (Websupport shared hosting,
PHP/Laravel backend).

## Two backends, one API

The same REST contract under `/api/v1/*` is implemented twice:

| Folder | Stack | Used by | Why |
| --- | --- | --- | --- |
| `backend-js/` | NestJS 10 + Prisma 5 + TypeScript | Local Docker stack, CI, AWS EC2 layout | Original reference implementation. Cleanest seam for a Node/serverless future. |
| `backend-php/` | Laravel 13 + Eloquent + PHP 8.3+ | **Production at tomas.gart.sk** | Hosting budget was constrained to PHP shared hosting. Same DB schema, same Postgres `EXCLUDE USING gist` overlap guarantee, same JSON error shape. |

The Vue frontend in `frontend/` is contract-driven and works against either
backend unchanged. Pick whichever one fits your hosting environment; both
are first-class citizens, both are kept in sync feature-by-feature.

## Stack

| Layer          | Technology                                                                       |
| -------------- | -------------------------------------------------------------------------------- |
| Frontend       | Vue 3, Vite, TypeScript, Pinia, Vue Router, Tailwind CSS, Vitest                 |
| Backend (JS)   | NestJS 10, Prisma 5, class-validator, Zod, Pino, Swagger / OpenAPI               |
| Backend (PHP)  | Laravel 13, Eloquent, FormRequest validation, PHPUnit 12                         |
| Database       | PostgreSQL 14+ (with `btree_gist` + `pgcrypto` extensions)                       |
| Infra          | Docker, Docker Compose (dev / NestJS path) · Websupport shared hosting (PHP path)|
| Tests          | Jest (backend-js), PHPUnit (backend-php), Vitest (frontend)                      |

## Architecture

Both backends follow the same logical layering:

- **Domain** — entities, value objects (e.g. half-open `TimeRange`), enums.
- **Application / Services** — use cases that orchestrate persistence and
  enforce invariants (`ReservationsService`, `ResourcesService`, …).
- **Infrastructure** — Prisma adapters (JS) or Eloquent models (PHP).
- **Presentation** — controllers + DTOs / FormRequests + JSON resources.

```
backend-js/src/modules/<feature>/   # NestJS layering
├── domain/         # entities, value objects, repository ports
├── application/    # framework-free use cases
├── infrastructure/ # Prisma adapters
└── presentation/   # controllers + DTOs

backend-php/app/                    # Laravel layering
├── Domain/{Enums,ValueObjects}/
├── Services/                       # ResourcesService, ReservationsService, AuditLogger, …
├── Models/                         # Eloquent
├── Http/{Controllers/Api,Requests,Resources}/
├── Exceptions/                     # DomainException + ApiExceptionRenderer
└── Console/Commands/               # lodenica:import-sheet
```

The frontend uses a feature-by-view structure:

```
frontend/src/
├── api/         # typed axios clients + DTO types
├── components/  # reusable UI (layout/, ui/)
├── stores/      # Pinia stores
├── views/       # route components (incl. AuditView)
├── router/      # vue-router config
├── i18n/        # Slovak labels (single source of truth for copy)
└── utils/       # date helpers, formatters
```

### Reservation overlap detection

Two layers protect against overlapping reservations on the same resource:

1. **Application layer** — `ReservationsService.assertNoOverlap()` runs an
   inclusive overlap query and raises a domain error with a clear Slovak
   message before persisting. Identical logic on both backends, tested in
   [backend-js/src/modules/reservations/application/reservations.service.spec.ts](backend-js/src/modules/reservations/application/reservations.service.spec.ts)
   and [backend-php/tests/Feature/ReservationsServiceTest.php](backend-php/tests/Feature/ReservationsServiceTest.php).
2. **Database layer** — a Postgres `EXCLUDE USING gist` constraint on
   `(resourceId WITH =, tsrange(startsAt, endsAt, '[)') WITH &&)` filtered to
   `status = 'CONFIRMED'`. Provides a hard guarantee against races and is
   translated back to a 409 conflict by both backends. Verified in
   [backend-php/tests/Feature/PostgresExcludeConstraintTest.php](backend-php/tests/Feature/PostgresExcludeConstraintTest.php).

### Audit log

Every mutating action (`create`, `update`, `delete`, `cancel`, `activate`,
`deactivate`, `attach_resources`, `add_participant`, `remove_participant`)
writes an append-only row to `audit_logs` with a Slovak `summary` plus a
`{ before, after }` JSON snapshot. Surfaced via `GET /api/v1/audit-logs`
and the `AuditView` page in the SPA.

## Running locally

Pick **one** of the two backends.

### Option A: NestJS backend (Docker stack)

```bash
cp .env.example .env
docker compose up --build
```

Once the containers are healthy:

| Service          | URL                                                |
| ---------------- | -------------------------------------------------- |
| Frontend         | http://localhost:5173                              |
| Backend API      | http://localhost:3000/api/v1                       |
| Swagger UI       | http://localhost:3000/docs                         |
| OpenAPI JSON     | http://localhost:3000/docs-json                    |
| Health check     | http://localhost:3000/health                       |
| PostgreSQL       | `postgres://lodenica:lodenica@localhost:5432/lodenica` |

The backend container automatically runs `prisma migrate deploy`, seeds demo
data and starts NestJS in watch mode.

### Option B: PHP/Laravel backend

```bash
docker compose up -d postgres            # reuse the Postgres container
docker exec lodenica-postgres psql -U lodenica -d postgres \
  -c "CREATE DATABASE lodenica_php;"     # dedicated DB to avoid clashing

cd backend-php
cp .env.example .env && php artisan key:generate
php artisan migrate --seed
php artisan serve                         # http://127.0.0.1:8000

# in another terminal
cd frontend
VITE_API_BASE_URL=http://127.0.0.1:8000/api/v1 pnpm dev
```

## Testing

```bash
# backend-js
cd backend-js
pnpm test            # Jest
pnpm test:cov

# backend-php
cd backend-php
vendor/bin/phpunit   # 75+ tests, includes Postgres EXCLUDE constraint suite

# frontend
cd frontend
pnpm test            # vitest run
```

## API surface

All endpoints versioned under `/api/v1`. Identical contract on both backends.

| Method | Path                                          | Description                       |
| ------ | --------------------------------------------- | --------------------------------- |
| GET    | `/health`                                     | Liveness + DB ping                |
| GET    | `/api/v1/availability/dashboard`              | Aggregated dashboard snapshot     |
| GET    | `/api/v1/resources`                           | List (filter + search + pagination) |
| POST   | `/api/v1/resources`                           | Create                            |
| PATCH  | `/api/v1/resources/:id`                       | Update mutable fields             |
| PATCH  | `/api/v1/resources/:id/deactivate`            | Soft-retire                       |
| PATCH  | `/api/v1/resources/:id/activate`              | Reactivate                        |
| DELETE | `/api/v1/resources/:id`                       | Hard delete                       |
| GET/POST/PATCH/DELETE | `/api/v1/reservations[/…]`     | CRUD + `/cancel`                  |
| GET/POST/PATCH/DELETE | `/api/v1/events[/…]`           | CRUD + participants + bulk attach |
| GET/POST/PATCH/DELETE | `/api/v1/damages[/…]`          | CRUD                              |
| GET    | `/api/v1/audit-logs`                          | Append-only audit log             |

Swagger UI is available at `/docs` on the NestJS backend.

## Database schema

All reservable assets — kayaks, canoes, rowing boats, inflatable boats,
trailers, boathouse spaces — share a single `resources` table discriminated by
`type`. Type-specific fields (`seats`, `lengthCm`, `weightKg`) are nullable.
This keeps reservations uniformly polymorphic without STI or table-per-type.

Migrations:

- `backend-js/prisma/migrations/` (Prisma format) — canonical for the NestJS path
- `backend-php/database/migrations/` (Laravel migrations) — Postgres path
  uses raw SQL for enums + EXCLUDE constraint, with a `upPortable()` fallback
  for sqlite tests

The two migration sets are kept in lockstep; whenever you touch one, mirror
the change in the other.

## Importing the club inventory

The original boat / canoe / trailer inventory lives in a Google Sheet that
both backends can ingest.

```bash
# NestJS backend
cd backend-js
pnpm tsx prisma/import-sheet.ts

# PHP backend (local or via SSH on Websupport)
cd backend-php
php artisan lodenica:import-sheet --force
```

Both importers fetch the same CSV, parse section headers into `ResourceType`,
handle quirks (K78 has its model in the `cm` column, K91 appears twice and
gets a `-2` suffix), recreate the two boathouse spaces, and optionally insert
sample reservations. They are **destructive** — they wipe damages, events and
reservations first — so they are safe for a fresh load but call them with
care on a populated DB.

## Configuration

Both backends are env-driven and validated on boot:

- NestJS: Zod-validated, see [backend-js/src/config/config.validation.ts](backend-js/src/config/config.validation.ts)
- Laravel: standard Laravel config + `config/cors.php` reads
  `CORS_ALLOWED_ORIGINS` (comma-separated list)

See each backend's `.env.example` for the full list.

## Logging & errors

Domain errors are translated into a stable JSON envelope on both backends:

```json
{
  "statusCode": 409,
  "error": "Conflict",
  "code": "RESERVATION_OVERLAP",
  "message": "Vybraný zdroj je v zadanom termíne už rezervovaný.",
  "details": { "resourceId": "…", "conflictingReservationIds": ["…"] },
  "path": "/api/v1/reservations",
  "timestamp": "2026-05-13T12:00:00+00:00"
}
```

Postgres `EXCLUDE USING gist` violations (SQLSTATE `23P01`) are mapped to
`RESERVATION_OVERLAP` on both sides. Unique-constraint violations become
`UNIQUE_CONSTRAINT`. Authorization headers are redacted from logs.

## CI / CD

GitHub Actions in [.github/workflows](.github/workflows):

| Workflow      | Trigger                       | Does                                                     |
| ------------- | ----------------------------- | -------------------------------------------------------- |
| `ci.yml`      | every push + PR to `main`     | typecheck + tests for the NestJS backend (against ephemeral PG) and the Vue frontend, plus a clean build |
| `release.yml` | push to `main`, tag `v*`      | builds multi-arch (amd64+arm64) Docker images and pushes to **GHCR** as `ghcr.io/<owner>/lodenica-{backend-js,frontend}` |
| `deploy.yml`  | manual (workflow_dispatch)    | SCPs the latest `docker-compose.prod.yml` to the production host and rolls the stack via SSH |

The PHP backend is deployed manually to Websupport via rsync + SSH —
see [backend-php/DEPLOY-WEBSUPPORT.md](backend-php/DEPLOY-WEBSUPPORT.md).

## Production deployment

### Current: PHP/Laravel on Websupport (https://tomas.gart.sk)

- Laravel app outside the docroot at `$HOME/lodenica-app/`
- Built Vue SPA + thin Laravel bootstrap `index.php` in the docroot at
  `$HOME/gart.sk/sub/tomas/`
- Managed Postgres at `db.r2.websupport.sk` (firewalled to hosting IPs)
- `php artisan lodenica:import-sheet --force` populates the inventory

Full runbook: [backend-php/DEPLOY-WEBSUPPORT.md](backend-php/DEPLOY-WEBSUPPORT.md).

### Alternative: NestJS on a single EC2 (Docker Compose)

Terraform under [infra/](infra/) provisions a `t4g.small` running the full
Docker Compose stack. See [infra/README.md](infra/README.md) for the runbook.

## Repository layout

```
.
├── docker-compose.yml          # dev stack: Postgres + NestJS backend + Vite frontend
├── docker-compose.prod.yml     # EC2 stack: pulls images from GHCR
├── .github/workflows/          # CI + Release + Deploy
├── README.md
├── backend-js/                 # NestJS reference backend
│   ├── Dockerfile
│   ├── prisma/                 # schema + migrations + seed + sheet importer
│   └── src/{modules,common,config,infrastructure}/
├── backend-php/                # Laravel production backend (Websupport)
│   ├── DEPLOY-WEBSUPPORT.md
│   ├── deploy/websupport-docroot/   # index.php + .htaccess for the SPA host
│   ├── app/{Domain,Services,Models,Http,Exceptions,Console}/
│   ├── database/migrations/
│   └── tests/{Unit,Feature}/
└── frontend/                   # Vue 3 SPA, talks to either backend
    ├── Dockerfile
    ├── nginx.conf
    └── src/{api,components,stores,views,router,i18n,utils,styles}/
```

## License

Internal — UNLICENSED.
