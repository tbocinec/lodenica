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
| Audit log | ❌ (401) | ✅ | ✅ | ✅ |
| Admin pages (users, usage, data, settings edit) | ❌ | ❌ | ❌ | ✅ |

### Write access (who can mutate)

| Action | Anonymous | PENDING | MEMBER | ADMIN |
| --- | --- | --- | --- | --- |
| Create a reservation (`POST /reservations`) | ✅ | ✅ | ✅ | ✅ |
| Update a reservation (`PATCH /reservations/{id}`) | ❌ (401) | ❌ (403) | ✅ | ✅ |
| Cancel a reservation (`PATCH /reservations/{id}/cancel`) | ❌ | ❌ | ✅ | ✅ |
| Delete a reservation (`DELETE /reservations/{id}`) | ❌ | ❌ | ✅ | ✅ |
| Report a damage | ✅ | ✅ | ✅ | ✅ |
| Edit / delete damage report | ✅ | ✅ | ✅ | ✅ |
| Create / update inventory (resources) | ❌ | ❌ | ❌ | ✅ |
| Edit reservation rules HTML | ❌ | ❌ | ❌ | ✅ |
| Confirm a pending user → MEMBER | ❌ | ❌ | ❌ | ✅ |
| Edit user roles + active flag | ❌ | ❌ | ❌ | ✅ |
| Export DB / CSV, purge reservations | ❌ | ❌ | ❌ | ✅ |

## Where the gating actually lives

The system has **three layers of gating**, all of which must stay in
sync. When you add a new permission-sensitive feature, touch all three.

### Layer 1: route middleware (backend)

`backend-php/routes/api.php` groups routes by what they need:

| Middleware stack | Who passes | Use for |
| --- | --- | --- |
| (none) | anonymous + all roles | Public reads + low-friction writes (create reservation, report damage) |
| `auth:sanctum` | any logged-in role (incl. PENDING) | Reading audit log, `/auth/me`, `/auth/logout` |
| `auth:sanctum`, `member` | MEMBER + ADMIN | Edits/cancels/deletes on existing reservations |
| `auth:sanctum`, `admin` | ADMIN only | Resource edits, user management, settings, exports |

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

Pattern:

```php
$user = $request->user();
$isMember = $user !== null
    && method_exists($user, 'isMember')
    && $user->isMember();

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

## How a new account flows through the system

1. Admin creates a user with role `PENDING` via `/admin/users` (or
   future self-registration writes the row directly).
2. User logs in via `/auth/login` → gets a Sanctum token like any
   other role.
3. SPA boot calls `/auth/me`, sees `role === 'PENDING'`, switches the
   dashboard into "waiting" mode. Names and contacts everywhere
   render as `** rezervácia` because the API stripped them.
4. Admin opens `/admin/users`, sees the PENDING row with a green
   "✓ Potvrdiť" button next to the role select.
5. Click → `POST /users/{id}/confirm` → backend `confirmPending()`
   transitions PENDING → MEMBER and writes an audit-log entry.
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
