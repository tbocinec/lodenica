# AGENTS.md — Rules for AI agents working on Lodenica

This file is the standing brief for any AI coding agent (Claude Code,
Codex, …) that touches this repo. It complements per-task instructions
and the human-facing docs (`README.md`, `backend-php/DEPLOY-WEBSUPPORT.md`,
`backend-php/deploy/REZERVACIE-LODENICAKVS.md`).

Read this first. Re-read it whenever you're about to deploy, migrate, or
edit a deploy script.

## Repo at a glance

```
backend-js/    NestJS + Prisma reference backend (Docker/CI/EC2 target)
backend-php/   Laravel + Eloquent backend (deployed to Websupport hosts)
frontend/     Vue 3 SPA, contract-driven, works against either backend
scripts/       Local-run automation (deploy-rezervacie.sh, …)
infra/         Terraform for the (now decommissioned) AWS EC2 stack
.github/workflows/  CI + release + manual deploy
.deploy-secrets    LOCAL ONLY, gitignored — see `.deploy-secrets.example`
```

Both backends speak the same `/api/v1/*` contract. The Vue frontend is
backend-agnostic; pick the deploy target per environment.

## Production targets

| Domain | Backend | SSH? | Layout | Runbook |
| --- | --- | --- | --- | --- |
| `tomas.gart.sk` | `backend-php` | yes | Laravel at `$HOME/lodenica-app/`, docroot at `$HOME/gart.sk/sub/tomas/` | [`backend-php/DEPLOY-WEBSUPPORT.md`](backend-php/DEPLOY-WEBSUPPORT.md) |
| `rezervacie.lodenicakvs.sk` | `backend-php` | no (SFTP only) | all-in-docroot: Laravel at `/laravel`, SPA at `/` | [`backend-php/deploy/REZERVACIE-LODENICAKVS.md`](backend-php/deploy/REZERVACIE-LODENICAKVS.md) |

## How the deploy automation is shaped

There are three layers and they share one truth (the local script):

1. **`scripts/deploy-rezervacie.sh`** — single source of truth.
   - Reads `.deploy-secrets` (gitignored) for credentials + remote paths.
   - Builds locally (composer install, pnpm build), writes `.env` and a
     token-substituted `install.php`, uploads via `lftp mirror`, then
     curls `https://$DOMAIN/install.php?token=…` to run server-side
     Artisan commands. Self-deletes `install.php` on success.
2. **`.github/workflows/deploy-rezervacie.yml`** — manual GitHub Actions
   workflow that runs the *same script* on a GitHub runner. Used when
   the operator's VPN blocks outbound port 22 (most corporate VPNs do).
   Reads secrets/variables from the repo's Actions settings and writes
   `.deploy-secrets` on the runner, then invokes the script. Tickbox
   `import_sheet` toggles the destructive importer.
3. **`backend-php/deploy/install.php.template`** — the server-side
   trigger. Token-guarded. Runs:
   1. `php artisan migrate --force`
   2. `php artisan db:seed --force` (idempotent — only adds the bootstrap
      admin user if missing; safe on every deploy)
   3. `php artisan lodenica:import-sheet --force` **only if** `import=1`
      (destructive — wipes resources/reservations/events/damages)
   4. `php artisan {config,route,event}:clear && :cache`
   5. self-deletes (`unlink(__FILE__)`)

   Path to the Laravel app is substituted at deploy time (`__LARAVEL_PATH__`
   → `/laravel` for rezervacie, `/../../../lodenica-app` for tomas.gart.sk).
   Same template, two hosting profiles.

If you're changing deploy behaviour, **change the local script first.**
The workflow and `install.php.template` are downstream.

## Migrations and seed: the rules

The deploy pipeline is **safe to repeat on a live deployment by default**:

| Step | When it runs | Destructive? | Why it's safe |
| --- | --- | --- | --- |
| `php artisan migrate --force` | every deploy | additive only | Laravel tracks ran migrations in the `migrations` table; only new ones execute. **Never use `migrate:fresh` or `migrate:rollback` on prod.** |
| `php artisan db:seed --force` | every deploy | idempotent | Seeders use `firstOrCreate` / `updateOrCreate` (or check counts before inserting demo data). They MUST stay idempotent. |
| `php artisan lodenica:import-sheet --force` | only when `--import-sheet` flag passed | **destructive** | Wipes damages, reservations, events, resources, then re-imports from the Google Sheet. Off by default. |
| `php artisan {config,route,event}:cache` | every deploy | safe | Clears then rebuilds caches. |

### Writing new migrations

- Always **additive**: add tables, add nullable columns, add indexes.
- Never `DROP` a column or table without a follow-up migration on a
  later release after data is confirmed safe.
- Use the same migration file format that's already in
  `backend-php/database/migrations/` (Postgres-first with sqlite
  fallback for tests — see `2026_01_01_000000_create_lodenica_schema.php`).
- If the migration needs `btree_gist` / `pgcrypto`, the first migration
  already enables them; just rely on their presence.
- Migrations are numbered by timestamp — pick a fresh prefix.

### Writing new seeders

- They MUST be idempotent. Use `updateOrCreate`, `firstOrCreate`, or guard
  with `if (Model::count() === 0) { … }`.
- A seeder that wipes data is a **importer**, not a seeder. Build it as
  an Artisan command under `backend-php/app/Console/Commands/` and gate
  it behind an explicit flag in the deploy pipeline (like `--import-sheet`).
- The deploy runs `db:seed --force` on every deploy. If your new seeder
  is destructive, **do not register it in `DatabaseSeeder::run()`**.

## When two agents are editing the repo at once

This happens. Today's clean-up uncovered that one agent was adding
Laravel Sanctum auth in parallel with another agent's deploy work.
Rules to keep them from stepping on each other:

- **Inspect `git status` first.** Untracked files in domains you don't
  own (e.g. `app/Http/Controllers/Api/AuthController.php` showing up in
  a deploy task) belong to someone else — leave them alone.
- **Never run `composer install` or `npm install` from the repo root.**
  Always `cd backend-php && composer install` or
  `cd frontend && pnpm install`. The repo root has no package manifest;
  running it there pollutes the root with `composer.json` / `vendor/`
  and confuses tooling. The `.gitignore` blocks it from being committed
  but the local mess is easy to avoid.
- **Stage and commit only what your task is about.** When in doubt,
  enumerate files explicitly to `git add` rather than `git add -A`.
- **Don't auto-commit other agents' uncommitted work.** Even if their
  files end up in your `git status`, they have their own commit story
  to write.

## Local hygiene

- `backend-php/vendor/` and `backend-php/composer.lock` ARE committed
  by intent — they're part of the deploy pipeline (the SFTP target has
  no `composer` available). Run `composer install` inside `backend-php/`
  if you touch it.
- Repo-root `composer.json`, `composer.lock`, `vendor/` are gitignored
  as a safety net. If they appear, somebody ran composer in the wrong
  directory; delete them.
- Empty `backend/` directories owned by `root` are Docker bind-mount
  leftovers from before the `backend → backend-js` rename. They're
  harmless; clean up with `sudo rm -rf backend/` + a `docker compose
  down && up` cycle if Docker keeps recreating them.

## Conventions

- **Commit messages**: `<type>(<scope>): short summary` — lowercase,
  ≤72 chars on the subject line. See `git log` for the prevailing
  style. Body wraps at ~72 cols. Include the `Co-Authored-By: Claude
  <…>` trailer when an agent did the work.
- **Slovak in user-facing copy**: UI strings (`frontend/src/i18n/labels.ts`),
  error messages thrown by Laravel that map to JSON, and audit-log
  summaries. Code stays in English.
- **Times** are wall-clock UTC stored as `TIMESTAMP` (no timezone).
  Half-open intervals `[start, end)`. Both backends + DB enforce this
  via the `EXCLUDE USING gist` constraint.
- **`/api/v1/*` JSON contract**: flat objects (`{ id, … }`), not wrapped
  in `data`. Laravel `JsonResource::withoutWrapping()` is set in
  `AppServiceProvider::boot()` so both backends match.

## Onboarding checklist for a fresh agent session

1. Read this file.
2. Skim `README.md`, then the deploy doc for the target you'll touch.
3. `git status` + `git log --oneline -10` to know where you are.
4. If the task involves deployment: confirm the user has VPN handled
   (run via GH Actions if VPN blocks port 22) and ask whether
   `--import-sheet` is wanted (it's destructive — never assume).
5. After making changes, run the relevant test suite before suggesting
   a commit:
   - `cd backend-php && vendor/bin/phpunit`
   - `cd frontend && pnpm test`
   - `cd backend-js && pnpm test` (if you touched it)
