# Lodenica — PHP backend (Laravel 13)

Drop-in PHP/Laravel port of the original NestJS backend, written for cheap
PHP hosting. Same database schema, same API surface, same business rules,
including the half-open reservation overlap detection and the Postgres
`EXCLUDE USING gist` constraint as a race-condition safety net.

## Stack

| Layer       | Technology                                                          |
| ----------- | ------------------------------------------------------------------- |
| Runtime     | PHP 8.3+, Composer 2                                                |
| Framework   | Laravel 13 (HTTP, Eloquent, Validation)                             |
| Database    | PostgreSQL 14+ with `btree_gist` and `pgcrypto`                     |
| Tests       | PHPUnit 12                                                          |

## Architecture

Standard Laravel layering with a small DDD-light core:

```
backend-php/
├── app/
│   ├── Domain/
│   │   ├── Enums/         # ResourceType, DamageSeverity, DamageStatus, ReservationStatus
│   │   └── ValueObjects/  # TimeRange (half-open [startsAt, endsAt))
│   ├── Exceptions/        # DomainException + subclasses, ApiExceptionRenderer
│   ├── Http/
│   │   ├── Controllers/Api/  # Thin REST controllers
│   │   ├── Requests/         # FormRequest validation
│   │   ├── Resources/        # JSON transformers
│   │   └── Support/          # Paginated helper
│   ├── Models/            # Eloquent: Resource, Reservation, Event, EventParticipant, Damage
│   └── Services/          # Application logic (ResourcesService, ReservationsService, …)
├── database/
│   ├── migrations/        # Single Postgres-first migration with sqlite fallback for tests
│   └── seeders/           # DatabaseSeeder (idempotent demo data)
├── routes/
│   ├── api.php            # /api/v1/* routes
│   ├── health.php         # /health, /health/live, /health/ready
│   └── web.php
└── tests/
    ├── Unit/
    │   └── TimeRangeTest.php          # Half-open overlap rules
    └── Feature/
        ├── ResourcesServiceTest.php
        ├── ReservationsServiceTest.php # Overlap matrix, cancellation, hard delete
        ├── PostgresExcludeConstraintTest.php  # DB-level race-condition guard
        └── Api/
            ├── ResourcesApiTest.php
            ├── ReservationsApiTest.php
            ├── EventsApiTest.php
            ├── DamagesApiTest.php
            └── HealthAndDashboardTest.php
```

## Quick start

```bash
cp .env.example .env
php artisan key:generate

# Postgres must be reachable (see DB_* in .env)
php artisan migrate --seed

php artisan serve   # http://127.0.0.1:8000
```

## Running tests

```bash
# Full suite (uses in-memory sqlite for speed; the Postgres EXCLUDE test
# self-attaches to the live pgsql_test connection when available)
vendor/bin/phpunit

# Only the Postgres-specific safety-net test (needs a running Postgres):
vendor/bin/phpunit tests/Feature/PostgresExcludeConstraintTest.php
```

## API surface

All endpoints are versioned under `/api/v1`. Identical to the NestJS backend.

| Method | Path                                          | Description                       |
| ------ | --------------------------------------------- | --------------------------------- |
| GET    | `/health`                                     | Liveness + DB ping                |
| GET    | `/api/v1/availability/dashboard`              | Aggregated dashboard snapshot     |
| GET    | `/api/v1/resources`                           | List (filter + search + pagination) |
| POST   | `/api/v1/resources`                           | Create                            |
| GET    | `/api/v1/resources/:id`                       | Get one                           |
| PATCH  | `/api/v1/resources/:id`                       | Update mutable fields             |
| PATCH  | `/api/v1/resources/:id/deactivate`            | Soft-retire                       |
| PATCH  | `/api/v1/resources/:id/activate`              | Reactivate                        |
| DELETE | `/api/v1/resources/:id`                       | Hard delete                       |
| GET    | `/api/v1/reservations`                        | List with date-range filter       |
| POST   | `/api/v1/reservations`                        | Create with conflict detection    |
| GET    | `/api/v1/reservations/:id`                    | Get one                           |
| PATCH  | `/api/v1/reservations/:id`                    | Update                            |
| PATCH  | `/api/v1/reservations/:id/cancel`             | Cancel (audit-friendly)           |
| DELETE | `/api/v1/reservations/:id`                    | Hard delete                       |
| GET    | `/api/v1/events`                              | List                              |
| POST   | `/api/v1/events`                              | Create                            |
| GET    | `/api/v1/events/:id`                          | Get one                           |
| PATCH  | `/api/v1/events/:id`                          | Update                            |
| DELETE | `/api/v1/events/:id`                          | Delete                            |
| GET    | `/api/v1/events/:id/participants`             | List participants                 |
| POST   | `/api/v1/events/:id/participants`             | Add a participant                 |
| DELETE | `/api/v1/events/:id/participants/:pId`        | Remove a participant              |
| POST   | `/api/v1/events/:id/reservations`             | Bulk-attach resources to an event |
| GET    | `/api/v1/damages`                             | List                              |
| POST   | `/api/v1/damages`                             | Report a damage                   |
| GET    | `/api/v1/damages/:id`                         | Get one                           |
| PATCH  | `/api/v1/damages/:id`                         | Update (auto-stamps `fixedAt` on FIXED) |
| DELETE | `/api/v1/damages/:id`                         | Delete                            |

### Response shapes

Single resource:

```json
{ "data": { "id": "…", "identifier": "…", … } }
```

Paginated list:

```json
{ "items": [ … ], "total": 42, "page": 1, "pageSize": 25 }
```

Error:

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

## Reservation overlap detection

Two layers protect against overlapping reservations on the same resource —
mirroring the NestJS backend:

1. **Application layer** — `ReservationsService::create` and `::update` call
   `assertNoOverlap()` before persisting. Half-open semantics: a reservation
   that ends at `12:00` does **not** conflict with one that starts at `12:00`
   (handovers are free).
2. **Database layer** — `EXCLUDE USING gist` on
   `(resourceId WITH =, tsrange(startsAt, endsAt, '[)') WITH &&)` filtered to
   `status = 'CONFIRMED'`. The global exception renderer translates SQLSTATE
   `23P01` back to a 409 with code `RESERVATION_OVERLAP`.

`tests/Unit/TimeRangeTest.php` covers the value object. The overlap matrix
(exact match, inside, partial start/end, fully containing, back-to-back,
cancelled, self-overlap on update) is covered in
`tests/Feature/ReservationsServiceTest.php`. The DB-level guard is exercised
in `tests/Feature/PostgresExcludeConstraintTest.php` (skipped when Postgres
isn't reachable).

## Deployment to PHP hosting

The app is designed for cheap shared hosting:

1. Copy `app/`, `bootstrap/`, `config/`, `database/`, `public/`, `resources/`,
   `routes/`, `storage/`, `vendor/`, `composer.json`, `composer.lock`,
   `artisan` to the server. **Only `public/` should be the web root.**
2. Upload an `.env` with production `APP_KEY`, `DB_*`, `CORS_ALLOWED_ORIGINS`.
3. Run `php artisan migrate --force`.
4. Optional: `php artisan config:cache && php artisan route:cache`.

If shell access is available, run `composer install --no-dev --optimize-autoloader`
on the server instead of uploading `vendor/`. If only FTP is available,
install vendor locally (`composer install --no-dev`) and upload it.

Apache `.htaccess` is included by Laravel in `public/`. For nginx, point
`fastcgi_pass` at PHP-FPM and use the standard Laravel rewrite block.
