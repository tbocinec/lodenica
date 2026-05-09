# Lodenica

Production-grade web application for managing a kayak/canoe club boathouse —
inventory of boats, trailers and boathouse spaces, reservations with conflict
detection, damage tracking, and a live availability dashboard.

Replaces the existing Google Sheet workflow. Code is in English; the UI is in
Slovak.

## Stack

| Layer       | Technology                                                          |
| ----------- | ------------------------------------------------------------------- |
| Frontend    | Vue 3, Vite, TypeScript, Pinia, Vue Router, Tailwind CSS, Vitest    |
| Backend     | NestJS 10, Prisma 5, class-validator, Zod, Pino, Swagger / OpenAPI  |
| Database    | PostgreSQL 16 (with `btree_gist` for overlap exclusion constraints) |
| Infra       | Docker, Docker Compose                                              |
| Tests       | Jest (backend), Vitest (frontend)                                   |

## Architecture

The backend follows clean architecture / DDD-light layering per module:

```
backend/src/modules/<feature>/
├── domain/          # entities, value objects, repository ports
├── application/     # use cases / services (framework-free where possible)
├── infrastructure/  # Prisma adapters implementing the ports
└── presentation/    # controllers + DTOs (HTTP boundary)
```

Cross-cutting concerns live in `backend/src/common/` (errors, filters, DTOs)
and `backend/src/infrastructure/` (Prisma module, future external clients).

The frontend uses a feature-by-view structure:

```
frontend/src/
├── api/         # typed axios clients + DTO types
├── components/  # reusable UI (layout/, ui/)
├── stores/      # Pinia stores
├── views/       # route components
├── router/      # vue-router config
├── i18n/        # Slovak labels (single source of truth for copy)
└── utils/       # date helpers, formatters
```

### Reservation overlap detection

Two layers protect against overlapping reservations on the same resource:

1. **Application layer** — `ReservationsService.assertNoOverlap()` runs an
   inclusive overlap query and raises a domain error with a clear Slovak
   message before persisting. Tested in
   `backend/src/modules/reservations/application/reservations.service.spec.ts`.
2. **Database layer** — a Postgres `EXCLUDE USING gist` constraint on
   `(resourceId WITH =, daterange(startDate, endDate, '[]') WITH &&)` filtered
   to `status = 'CONFIRMED'`. Provides a hard guarantee against races and is
   translated back to a 409 conflict by the global exception filter.

## Running locally

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

The backend container automatically:

- runs `prisma migrate deploy`
- runs `prisma db seed` to load demo data (boats, spaces, reservations, one
  damage record)
- starts NestJS in watch mode

## Local development without Docker

```bash
# database
docker compose up -d postgres

# backend
cd backend
pnpm install
pnpm prisma migrate dev
pnpm db:seed
pnpm start:dev

# frontend (new terminal)
cd frontend
pnpm install
pnpm dev
```

## Testing

```bash
# backend
cd backend
pnpm test            # unit tests
pnpm test:cov        # with coverage

# frontend
cd frontend
pnpm test            # vitest run (component + util tests)
```

The most important suites:

- `backend/src/modules/reservations/domain/date-range.spec.ts` — overlap rules
- `backend/src/modules/reservations/application/reservations.service.spec.ts`
  — overlap detection, inactive resources, cancellation, update edge cases
- `backend/src/modules/resources/application/resources.service.spec.ts` —
  resource lifecycle

## API surface

All endpoints are versioned under `/api/v1`. Highlights:

| Method | Path                                  | Description                       |
| ------ | ------------------------------------- | --------------------------------- |
| GET    | `/api/v1/availability/dashboard`      | Aggregated dashboard snapshot     |
| GET    | `/api/v1/resources`                   | List resources (filter, search)   |
| POST   | `/api/v1/resources`                   | Create a resource                 |
| PATCH  | `/api/v1/resources/:id`               | Update mutable fields             |
| PATCH  | `/api/v1/resources/:id/deactivate`    | Soft-retire                       |
| DELETE | `/api/v1/resources/:id`               | Hard delete (only without rsv.)   |
| GET    | `/api/v1/reservations`                | List reservations (filter)        |
| POST   | `/api/v1/reservations`                | Create with conflict detection    |
| PATCH  | `/api/v1/reservations/:id/cancel`     | Cancel (audit-friendly)           |
| GET    | `/api/v1/damages`                     | List damages                      |
| POST   | `/api/v1/damages`                     | Report a damage                   |
| PATCH  | `/api/v1/damages/:id`                 | Update status / fields            |

See Swagger at `/docs` for full request/response shapes.

## Database schema

All reservable assets — kayaks, canoes, rowing boats, inflatable boats,
trailers, boathouse spaces — share a single `resources` table discriminated by
`type`. Type-specific fields (`seats`, `lengthCm`, `weightKg`) are nullable.
This keeps reservations uniformly polymorphic without STI or table-per-type.

Migrations are managed by Prisma and live in `backend/prisma/migrations/`. To
add a new migration:

```bash
cd backend
pnpm prisma migrate dev --name <descriptive-name>
```

## Configuration

All runtime configuration is environment-driven and validated on boot via Zod
(`backend/src/config/config.validation.ts`). The application refuses to start
with an invalid config — typos in env vars surface immediately rather than at
the first request. See `.env.example` (root) and `backend/.env.example`.

## Logging & errors

- `nestjs-pino` is used for structured logs; pretty-printed in development.
- Domain errors (`backend/src/common/errors/domain.errors.ts`) are translated
  to HTTP responses by `GlobalExceptionFilter`. Prisma `P2002`, `P2025` and
  exclusion-constraint violations are mapped to user-friendly Slovak messages.
- Authorization headers are redacted from logs.

## Importing data from the existing Google Sheet

The data model intentionally mirrors the sheet's columns:
identifier · type · name · model · color · seats · length · weight · status.
A separate import script (Prisma seed-style) can be added in
`backend/prisma/import.ts` to read a CSV export and bulk-create resources and
reservations. The schema is ready — the existing `seed.ts` is a good starting
template.

## Future extensibility

The system is structured so the following can be added without rewriting:

- **Auth & roles** — add an `AuthModule`, guards on controllers, a `users`
  table relating to reservations as `createdBy`.
- **Members & memberships** — `members` table, FK from reservations.
- **Payments** — `payments` table linked to reservations or memberships.
- **QR check-in / check-out** — a new `events` table; resource detail page
  already has space for QR codes.
- **Notifications** — emit domain events from the application layer; wire
  `BullMQ` or Nest's `EventEmitterModule`.
- **Audit logs** — Prisma middleware to record changes per entity.
- **Mobile app** — same OpenAPI contract; the `/docs-json` endpoint is the
  contract source.

## CI / CD

GitHub Actions in [.github/workflows](.github/workflows):

| Workflow      | Trigger                       | Does                                                     |
| ------------- | ----------------------------- | -------------------------------------------------------- |
| `ci.yml`      | every push + PR to `main`     | typecheck + tests for backend (against ephemeral PG) and frontend, plus a clean build |
| `release.yml` | push to `main`, tag `v*`      | builds multi-arch (amd64+arm64) Docker images and pushes to **GHCR** as `ghcr.io/<owner>/lodenica-{backend,frontend}` with tags `latest`, `sha-<short>`, branch, semver |
| `deploy.yml`  | manual (workflow_dispatch)    | SCPs the latest `docker-compose.prod.yml` to the production host and rolls the stack via SSH |

Required GitHub Actions repo configuration:

- **Variables** (Settings → Variables): `DEPLOY_HOST`, `DEPLOY_USER` (= `ubuntu`)
- **Secrets** (Settings → Secrets): `DEPLOY_SSH_KEY` (private key matching the EC2 key pair)
- **Environment** named `production` (optional, for approval gates)

## Production deployment (AWS)

The cheapest viable production setup is a single EC2 instance running the
full Docker Compose stack. Terraform under [infra/](infra/) provisions it.

```bash
aws sso login                       # or however your account auths
cd infra
cp terraform.tfvars.example terraform.tfvars  # edit github_owner, ssh CIDR, …
terraform init
terraform apply
```

Cost (eu-central-1):

| Tier                       | Monthly                           |
| -------------------------- | --------------------------------- |
| `t3.micro` (free tier)     | **$0** for 12 months · then $7.59 |
| `t4g.small` (recommended)  | ~$12.30                           |
| EBS gp3 root (20 GB)       | ~$1.60                            |
| Elastic IP attached        | $0                                |

After `terraform apply`, follow the next-steps output: SCP your `.env` and
trigger the `Deploy` workflow. See [infra/README.md](infra/README.md) for
the full runbook.

## Repository layout

```
.
├── docker-compose.yml
├── .env.example
├── README.md
├── backend/
│   ├── Dockerfile
│   ├── package.json
│   ├── prisma/
│   │   ├── schema.prisma
│   │   ├── migrations/
│   │   │   └── 20260101000000_init/migration.sql
│   │   └── seed.ts
│   ├── src/
│   │   ├── main.ts
│   │   ├── app.module.ts
│   │   ├── config/
│   │   ├── common/
│   │   ├── infrastructure/prisma/
│   │   └── modules/
│   │       ├── resources/
│   │       ├── reservations/
│   │       ├── damages/
│   │       ├── availability/
│   │       └── health/
│   └── test/
└── frontend/
    ├── Dockerfile
    ├── nginx.conf
    ├── package.json
    ├── index.html
    └── src/
        ├── main.ts
        ├── App.vue
        ├── api/
        ├── components/
        ├── stores/
        ├── views/
        ├── router/
        ├── i18n/
        ├── utils/
        └── styles/
```

## License

Internal — UNLICENSED.
