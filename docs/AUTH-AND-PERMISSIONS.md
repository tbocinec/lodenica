# Auth and permissions

This is the source of truth for **who can see what** and **who can do what**
in Lodenica. When you change anything that depends on auth state — adding a
new endpoint, gating a new field, masking PII in a view — read this file
first and update the matrix as part of the same change.

## Roles

There are three roles, plus a fourth "role" for anyone without a session.

| Code | UI label | Meaning |
| --- | --- | --- |
| (none) | — | Anonymous visitor. Hits the public surface of the API without a Bearer token. |
| `PENDING` | "Čaká na potvrdenie" | Registered but not yet confirmed by an admin. Can log in (so they see a "waiting" dashboard) but is treated like anonymous for everything else. |
| `MEMBER` | "Člen" | Confirmed club member. Sees customer names + contacts, can edit reservations. |
| `ADMIN` | "Administrátor" | Everything `MEMBER` can do plus inventory edits, user management, audit log, exports, settings, etc. |

> **Critical rule:** treat `PENDING` as anonymous for visibility and write
> permissions. The only thing they get over anon is the "your account is
> waiting" experience on the dashboard.

## Backend enum

```php
// backend-php/app/Domain/Enums/UserRole.php
enum UserRole: string {
    case ADMIN = 'ADMIN';
    case MEMBER = 'MEMBER';
    case PENDING = 'PENDING';
}
```

`User` model helpers:

- `$user->isAdmin()` — strict `ADMIN` check.
- `$user->isMember()` — `MEMBER` *or* `ADMIN`. **Use this for permission
  gates**; PENDING returns false.
- `$user->isPending()` — strict `PENDING`.

## Frontend pinia store

```ts
// frontend/src/stores/auth.store.ts
const auth = useAuthStore();
auth.isAuthenticated   // any logged-in user (incl. PENDING)
auth.isMember          // MEMBER or ADMIN  ← use this for gates
auth.isAdmin           // strict ADMIN
auth.isPending         // strict PENDING
```

## Permission matrix

### Read access (what each role *sees*)

| Field / endpoint | Anonymous | PENDING | MEMBER | ADMIN |
| --- | --- | --- | --- | --- |
| Resource inventory (boats, names, identifiers, types) | ✅ | ✅ | ✅ | ✅ |
| Reservation date ranges + resource | ✅ | ✅ | ✅ | ✅ |
| `customerName` on reservations | ❌ (null) | ❌ (null) | ✅ | ✅ |
| `customerContact` on reservations | ❌ (null) | ❌ (null) | ✅ | ✅ |
| Damage reports + photos | ✅ | ✅ | ✅ | ✅ |
| Reservation rules page | ✅ | ✅ | ✅ | ✅ |
| Privacy policy page (`/ochrana-udajov`) | ✅ | ✅ | ✅ | ✅ |
| Event list + single-event metadata (title, description, date, location) | ✅ | ✅ | ✅ | ✅ |
| Event participants (`GET /events/{id}/participants`) | ❌ (401) | ❌ (403) | ✅ | ✅ |
| Boats attached to an event (shown in SPA event detail) | ❌ | ❌ | ✅ | ✅ |
| My own reservations (`GET /reservations/mine`) | ❌ (401) | ✅ (own) | ✅ (own) | ✅ (own) |
| Audit log | ❌ (401) | ✅ | ✅ | ✅ |
| Admin pages (users, usage, data, settings edit) | ❌ | ❌ | ❌ | ✅ |

### Write access (who can mutate)

| Action | Anonymous | PENDING | MEMBER | ADMIN |
| --- | --- | --- | --- | --- |
| Register an account (`POST /auth/register`) → always PENDING | ✅ | — | — | — |
| Request password reset (`POST /auth/forgot-password`, captcha-gated) | ✅ | ✅ | ✅ | ✅ |
| Reset password with token (`POST /auth/reset-password`) | ✅ | ✅ | ✅ | ✅ |
| Change OWN password (`POST /profile/change-password`) | ❌ (401) | ✅ | ✅ | ✅ |
| Link / unlink own social login (`/profile/identities`, `/profile/oauth/*`) | ❌ (401) | ✅ | ✅ | ✅ |
| Create a reservation (`POST /reservations`) — stamps `createdById` when logged in | ✅ | ✅ | ✅ | ✅ |
| Update a reservation (`PATCH /reservations/{id}`) | ❌ (401) | ❌ (403) | ✅ | ✅ |
| Cancel a reservation (`PATCH /reservations/{id}/cancel`) | ❌ | ❌ | ✅ | ✅ |
| Delete a reservation (`DELETE /reservations/{id}`) | ❌ | ❌ | ✅ | ✅ |
| Report a damage | ✅ | ✅ | ✅ | ✅ |
| Edit / delete damage report | ✅ | ✅ | ✅ | ✅ |
| Create / update / delete an event | ❌ (401) | ❌ (403) | ✅ | ✅ |
| Add / remove event participants | ❌ | ❌ | ✅ | ✅ |
| Attach boats to an event (`POST /events/{id}/reservations`) | ❌ | ❌ | ✅ | ✅ |
| Create / update inventory (resources) | ❌ | ❌ | ❌ | ✅ |
| Edit reservation rules HTML | ❌ | ❌ | ❌ | ✅ |
| Edit privacy policy HTML | ❌ | ❌ | ❌ | ✅ |
| Confirm a pending user → MEMBER (sends approval email) | ❌ | ❌ | ❌ | ✅ |
| Edit user roles + active flag | ❌ | ❌ | ❌ | ✅ |
| Reset ANY user's password (`PATCH /users/{id}`) | ❌ | ❌ | ❌ | ✅ |
| Invite a member — single (`POST /users/invite`) or bulk CSV (`POST /users/import`); both auto-confirm as MEMBER | ❌ | ❌ | ❌ | ✅ |
| Export DB / CSV, purge reservations | ❌ | ❌ | ❌ | ✅ |

## Where the gating actually lives

The system has **three layers of gating**, all of which must stay in
sync. When you add a new permission-sensitive feature, touch all three.

### Layer 1: route middleware (backend)

`backend-php/routes/api.php` groups routes by what they need:

| Middleware stack | Who passes | Use for |
| --- | --- | --- |
| (none) | anonymous + all roles | Public reads + low-friction writes (create reservation, report damage); auth entry points (`login`, `register`, `captcha`, `forgot-password`, `reset-password`, `providers`, `oauth/*`); event list + single-event reads |
| `auth:sanctum` | any logged-in role (incl. PENDING) | `/auth/me`, `/auth/logout`, audit log, **own** profile (change password, link/unlink identity), **own** reservations (`/reservations/mine`) |
| `auth:sanctum`, `member` | MEMBER + ADMIN | Edits/cancels/deletes on reservations; event create/update/delete + participants + attach-boats |
| `auth:sanctum`, `admin` | ADMIN only | Resource edits, user management, bulk import, settings, exports |

> **Public-route guard gotcha.** On a route with no `auth:sanctum`
> middleware the default guard never runs, so `$request->user()` is null
> even when a valid Bearer token is present. Code that needs the user on a
> public route (e.g. `ReservationResource`, `CreateReservationRequest`'s
> default-to-self, stamping `createdById` in `ReservationsController::store`)
> must ask the sanctum guard explicitly: `$request->user('sanctum')`.

Aliases in `bootstrap/app.php`:

```php
$middleware->alias([
    'admin'  => \App\Http\Middleware\EnsureAdmin::class,
    'member' => \App\Http\Middleware\EnsureMember::class,
]);
```

`EnsureMember` rejects both anon AND PENDING with `ForbiddenException`
(403). `EnsureAdmin` does the same but only ADMIN passes.

### Layer 2: API response shape (backend)

PII gating happens at the resource boundary, not the SQL layer — the
records are loaded normally, then the serialiser nulls private fields
for non-members.

Two places to update if you add a new private field:

- `app/Http/Resources/ReservationResource.php` — uses `$request->user()`
  + `isMember()`.
- `app/Services/AvailabilityService.php::renderReservation()` — same
  rule, applied to the dashboard payload (separate code path because
  it's not a JsonResource).

Pattern (note the explicit `sanctum` guard — these run on public routes):

```php
$user = $request->user('sanctum') ?? $request->user();
$isMember = $user instanceof \App\Models\User && $user->isMember();

return [
    // …
    'customerName' => $isMember ? $this->customerName : null,
    'customerContact' => $isMember ? $this->customerContact : null,
];
```

> The backend is the **only** authoritative gate. The SPA layer below
> exists for UX, not security. Never trust the frontend to enforce
> visibility — strip the value at the API boundary instead.

### Layer 3: SPA visibility + action gates

The Vue layer is purely UX — it controls what's shown to the user,
which forms are interactive, and where buttons appear. Patterns:

- **Mask null fields**: `{{ r.customerName ?? '** rezervácia' }}` — the
  backend already nulled it, this is just the placeholder.
- **Hide / disable actions**: `v-if="auth.isMember"` around the edit
  button row, or `:class="auth.isMember ? 'cursor-pointer' : ''"` on
  list rows that open a modal.
- **PENDING dashboard banner**: amber card at the top of
  `DashboardView.vue` shown when `auth.isPending` is true.
- **Mobile drawer**: navigation items use `requires: 'member' | 'admin'`
  on each `NavItem` (see `components/layout/AppShell.vue`); filtering
  is computed live.

## How an account is created (four ways)

All four paths land the account as **PENDING** (admin-created accounts can
be MEMBER/ADMIN directly):

1. **Self-registration** — `POST /auth/register` (email + password). Role
   is forced to PENDING server-side; the client cannot pick it. Auto-logs
   in so the user immediately sees the "waiting" dashboard.
2. **Social login** — first OAuth login with an unknown identity creates a
   PENDING account (`OAuthService::resolveLogin`). If the provider email
   matches an existing account, the identity is linked to it instead.

> Self-registration and a new OAuth PENDING account both fire an admin
> notification email (`AdminNotifier::pendingMemberAwaitingApproval`) to
> `config('mail.admin_address')` (default `rezervacie@lodenicakvs.sk`).
3. **Admin create** — `/admin/users` form, role chosen by the admin.
4. **Admin invite** — single (`POST /users/invite`, name + email) or bulk
   CSV (`POST /users/import`). Admin-invited accounts are **auto-confirmed
   as MEMBER** (the admin vetted them) and emailed a set-your-password
   link (same token mechanism as reset, 30-day TTL). They are NOT PENDING.

## Password reset & invitations

- `POST /auth/forgot-password` is **captcha-gated** (stateless HMAC-signed
  SVG captcha — `CaptchaService`, no GD/session needed) and always returns
  a generic 200, even for unknown emails, to prevent account enumeration.
- Tokens live in `password_reset_tokens` (sha256-hashed, per-row
  `expires_at`). `PasswordResetService` handles both reset (60 min) and
  invitation (72 h) flows; both land on the SPA `/reset-password` screen.
- Reset/invite consumption revokes the user's existing API tokens.

## OAuth (Google / Facebook) — dormant by default

- `laravel/socialite` + a `user_identities` table (one row per provider
  identity → user). Add a provider with an enum case + a `config/services`
  block; the flow is provider-agnostic.
- **Dormant** until client id/secret are set in `.env` (from
  `.deploy-secrets`). Unconfigured providers 404 and `GET /auth/providers`
  returns `[]`, so the SPA hides the buttons.
- Full-page redirect flow (stateless Socialite, since the API has no
  session). Linking from the profile screen carries the logged-in user's
  id in an HMAC-signed `state` so the callback links instead of logging in.

## How a PENDING account becomes a member

1. Account is created PENDING by one of the four paths above.
2. User logs in via `/auth/login` → gets a Sanctum token like any
   other role.
3. SPA boot calls `/auth/me`, sees `role === 'PENDING'`, switches the
   dashboard into "waiting" mode. Names and contacts everywhere
   render as `** rezervácia` because the API stripped them.
4. Admin opens `/admin/users`, sees the PENDING row with a green
   "✓ Potvrdiť" button next to the role select.
5. Click → `POST /users/{id}/confirm` → backend `confirmPending()`
   transitions PENDING → MEMBER, writes an audit-log entry, and emails the
   member a "membership approved" notice.
6. User reloads (or their next `/auth/me`) → `role: 'MEMBER'` →
   `auth.isMember` flips true → full UI.

## When to touch this file

You **must** update this matrix when you:

- Add a new endpoint that should be gated by role.
- Add a new field on `ReservationResource` (or any user-facing
  resource) that might be PII.
- Change the meaning of an existing role.
- Add a new role.

If you find yourself reading SPA permission code to figure out who
gets to see what, the docs are stale — fix the file before merging.

## Related tests

- `backend-php/tests/Feature/Api/UserConfirmationApiTest.php` — the
  PENDING → MEMBER flow + edit gating.
- `backend-php/tests/Feature/Api/ReservationsApiTest.php`
  (`test_customer_name_and_contact_are_hidden_for_anonymous_readers`)
  — privacy regression net.
- `backend-php/tests/Feature/Api/AdminDataApiTest.php` — RBAC on
  destructive admin operations.
- `backend-php/tests/Feature/Api/AuthRegistrationApiTest.php` — self-reg
  lands PENDING, role can't be forced, duplicate-email guard.
- `backend-php/tests/Feature/Api/PasswordResetApiTest.php` — captcha gate,
  no enumeration, token reset.
- `backend-php/tests/Feature/Api/ProfileApiTest.php` — own-password change
  (incl. PENDING), wrong-current rejection.
- `backend-php/tests/Feature/Api/MyReservationsApiTest.php` —
  default-to-self, book-for-others, `createdById`, `/reservations/mine`.
- `backend-php/tests/Feature/Api/BulkUserImportApiTest.php` — CSV import,
  duplicate/invalid handling, invitation emails.
- `backend-php/tests/Feature/Api/MembershipApprovalMailTest.php` —
  approval email on confirm.
- `backend-php/tests/Feature/Api/OAuthDormantApiTest.php` +
  `tests/Feature/OAuthServiceTest.php` — dormant providers 404, identity
  create/link/unlink logic.

> **SPA route gate `confirmed`.** `router/index.ts` adds a third
> `meta.auth` value: `'member'` = any authenticated (incl. PENDING, e.g.
> profile/audit), `'confirmed'` = MEMBER/ADMIN only (PENDING bounced, e.g.
> creating events), `'admin'` = ADMIN.
