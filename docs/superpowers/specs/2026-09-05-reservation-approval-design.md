# Reservation approval workflow + per-user e-mail notification preferences

**Date:** 2026-09-05
**Status:** implemented — see docs/superpowers/plans/2026-09-05-reservation-approval.md
**Scope:** `backend-php/`, `frontend/`, `docs/spec/02-reservations.md`, `docs/AUTH-AND-PERMISSIONS.md`

## 1. Goal

Some resources (a clubhouse space, a trailer, an expensive boat) must not be
bookable freely. An admin marks such a resource as *requires approval* and
picks the members who may approve. A booking of that resource is created in a
new **waiting** state, the approvers get an e-mail, decide in the app, and the
booker is e-mailed the outcome. Every user can switch the e-mails they
personally receive on or off in their profile.

Resources that are **not** flagged keep today's behaviour exactly: booking by
anyone, immediately confirmed, no e-mail.

## 2. Decisions taken with the product owner

| Question | Decision |
|---|---|
| Does a waiting request hold the slot? | **Yes.** While undecided, the slot is occupied for everyone else. Approval can therefore never hit an overlap. |
| How is a rejection represented? | Own status `REJECTED`. It frees the slot exactly like a cancellation, but the booker can tell "I cancelled" from "it was rejected". |
| Anonymous booker of an approval-required resource? | **Login required.** Only a confirmed member (`MEMBER` or `ADMIN`) may book such a resource; anonymous and `PENDING` get a 403 with an explanation and the SPA shows a login prompt. |
| Where do approvers decide? | A dedicated page *Na schválenie* (target of the e-mail link, nav item with a count), Approve/Reject buttons in the existing reservation dialog, and a dashboard banner with the pending count. |
| Approval logic location | Separate `ReservationApprovalService`; `ReservationsService` stays about lifecycle + overlaps. |
| Approver storage | Pivot table `resource_approvers` with foreign keys (cascade on user/resource delete). |
| Per-user notification preferences | JSON column on `users`, "missing key = on", mirroring the admin switches in `MailNotificationSettings`. |

Verified along the way: deactivating a resource already works end to end
(backend 400 `RESERVATION_RESOURCE_INACTIVE` with a test; SPA hides inactive
resources in the booking form, picker, timeline, event attach and dashboard
"available"). Known pre-existing gap: the *Priestory* page lists inactive
spaces too (booking them fails). Optional fix, not part of this design.

## 3. Domain model

### 3.1 Reservation status

| Status | Blocks the slot | Created by |
|---|---|---|
| `CONFIRMED` | yes | booking a normal resource; approval |
| `PENDING_APPROVAL` | yes | booking an approval-required resource |
| `CANCELLED` | no | cancel by booker/member |
| `REJECTED` | no | rejection by an approver |

`ReservationStatus` gains:

```php
public function blocksSlot(): bool;            // CONFIRMED, PENDING_APPROVAL
/** @return list<self> */
public static function blocking(): array;
/** @return list<string> */
public static function blockingValues(): array;
```

Every place that today filters on `CONFIRMED` for "is the slot taken" switches
to the blocking set: `ReservationsService::findOverlapping`,
`AvailabilityService`, the Postgres EXCLUDE constraint, and the SPA schedule
views. Resources without the flag never enter `PENDING_APPROVAL`, so nothing
changes for them.

### 3.2 Resource

- `requiresApproval BOOLEAN NOT NULL DEFAULT FALSE`
- relation `approvers()` — `belongsToMany(User)` through `resource_approvers`
- helper `isApprover(User $u): bool`

### 3.3 `resource_approvers` (new table)

| Column | Type |
|---|---|
| `resourceId` | UUID, FK `resources.id` ON DELETE CASCADE |
| `userId` | UUID, FK `users.id` ON DELETE CASCADE |
| `createdAt` | TIMESTAMP(3) default now |

Primary key `(resourceId, userId)`, index on `userId` (drives "pending for me").
Only `MEMBER`/`ADMIN` accounts may be approvers; the service rejects others
with a validation error.

### 3.4 Reservation

New nullable columns:

| Column | Type | Meaning |
|---|---|---|
| `decidedById` | UUID, FK `users.id` ON DELETE SET NULL | who approved/rejected |
| `decidedAt` | TIMESTAMP(3) | when |
| `decisionNote` | TEXT | optional note from the approver, goes into the booker's e-mail and the detail |

Model gains casts, `decidedBy()` relation, `isPendingApproval()`.

### 3.5 User

- `notificationPrefs JSONB NULL` (cast `array`). Keys are `MailNotification`
  values, values are booleans. A missing key means **on**.

## 4. Migrations (both additive, Postgres-first with SQLite fallback)

1. `2026_09_05_000000_add_approval_values_to_reservationstatus_enum.php`
   — Postgres only, `$withinTransaction = false`,
   `ALTER TYPE "ReservationStatus" ADD VALUE IF NOT EXISTS 'PENDING_APPROVAL'`
   and the same for `'REJECTED'`. No-op on SQLite (status is a string column).
   Same pattern as `2026_06_17_000000_add_pending_to_userrole_enum.php`.
2. `2026_09_05_010000_add_reservation_approval.php`
   - `resources.requiresApproval`
   - table `resource_approvers`
   - `reservations.decidedById / decidedAt / decisionNote`
   - `users.notificationPrefs` (`jsonb` on Postgres, text on SQLite)
   - Postgres only: `ALTER TABLE reservations DROP CONSTRAINT IF EXISTS
     reservations_no_overlap_excl` then re-add with
     `WHERE ("status" IN ('CONFIRMED','PENDING_APPROVAL'))`.

Migration 1 must run and commit before 2 because a new enum value cannot be
referenced in the same transaction that adds it. The timestamp order
guarantees this.

## 5. Backend

### 5.1 Enums

- `ReservationStatus`: see 3.1.
- `AuditAction`: `APPROVE`, `REJECT`.
- `MailNotification`: two new cases, both non-critical, both user-configurable:

| Case | Value | Recipient |
|---|---|---|
| `RESERVATION_APPROVAL_REQUESTED` | `reservation_approval_requested` | approvers of the resource (fallback: club admin address) |
| `RESERVATION_DECIDED` | `reservation_decided` | the account that created the reservation |

  New method `isUserConfigurable(): bool` — `true` only for these two. Slovak
  `label()`, `description()`, `consequence()` are added for both; they appear
  on the admin diagnostics page automatically (the page is data-driven). No
  change to the seeded `mail_notifications` row is needed:
  `MailNotificationSettings::all()` already defaults missing keys to on.

### 5.2 `ReservationsService`

Constructor gains `ReservationNotifier`.

`create(array $cmd)`:

1. Resolve the resource (404), reject inactive (400) — unchanged.
2. If `$resource->requiresApproval`: load the actor from `$cmd['createdById']`.
   If there is none, or the actor is not `isMember()`, throw
   `ApprovalMemberRequiredException` — HTTP **403**, code
   `RESERVATION_APPROVAL_MEMBER_REQUIRED`, message *"Tento zdroj vyžaduje
   schválenie. Rezervovať ho môže iba prihlásený člen klubu."*
   (403 for anonymous too, deliberately: the SPA treats 401 as a lost session
   and would log a `PENDING` user out.)
3. Overlap check against blocking statuses.
4. Create with `status = requiresApproval ? PENDING_APPROVAL : CONFIRMED`.
5. Audit `CREATE`; summary for pending reads *"Požiadaná rezervácia „X“ pre
   „K-1 – Name“ (range) — čaká na schválenie"*.
6. If pending: `$this->notifier->approvalRequested($reservation)`.

`update(string $id, array $cmd)`:

- Overlap re-check when the range changes runs if the resulting status
  `blocksSlot()` (was: `=== CONFIRMED`).
- Status lock, thrown as `ReservationStatusLockedException` — HTTP **409**,
  code `RESERVATION_STATUS_LOCKED`:
  - existing status is `PENDING_APPROVAL` or `REJECTED` and `$cmd['status']`
    differs from it → *"Stav tejto rezervácie sa dá zmeniť iba schválením,
    zamietnutím alebo zrušením."*
  - resource `requiresApproval`, `$cmd['status'] === CONFIRMED` and existing
    status is not `CONFIRMED` → *"Rezerváciu tohto zdroja môže potvrdiť iba
    schvaľovateľ."*
- Editing time/name/note of a pending reservation stays allowed; it remains
  pending. Approvers are **not** re-notified (follow-up if ever needed).

`cancel(string $id)`:

- Only a reservation whose status `blocksSlot()` transitions to `CANCELLED`;
  `CANCELLED`/`REJECTED` return unchanged with no audit row (extends REZ-024).
- Audit `before.status` records the actual previous status (today it is the
  literal `'CONFIRMED'`).

`list(array $options)`: `status` accepts a single value **or a list**;
implemented as `whereIn`. `findOverlapping` uses the blocking set.

### 5.3 `ReservationApprovalService` (new)

```php
__construct(AuditLogger $audit, ReservationNotifier $notifier)

approve(string $id, User $actor, ?string $note = null): Reservation
reject(string $id, User $actor, ?string $note = null): Reservation
canDecide(User $user, Reservation $r): bool      // admin, or listed approver of the resource
pendingFor(User $user, int $skip, int $take): array{items, total}
pendingCountFor(User $user): int
```

`approve`/`reject` share a private `decide()`:

1. Load or 404.
2. `canDecide` or `ForbiddenException` (403) *"Túto rezerváciu nemôžeš
   schvaľovať."* — checked before the state so unauthorised callers learn
   nothing about it.
3. Race-safe transition: a single conditional update
   `WHERE id = ? AND status = 'PENDING_APPROVAL'` setting `status`,
   `decidedById`, `decidedAt = now()`, `decisionNote`. Zero affected rows →
   `ReservationNotPendingException`, HTTP **409**, code
   `RESERVATION_NOT_PENDING`, *"O tejto rezervácii už bolo rozhodnuté."*
4. Audit `APPROVE` / `REJECT` with `before/after status` and the note.
5. `$this->notifier->decided($reservation)`.

`pendingFor`: `status = PENDING_APPROVAL`, ordered by `startsAt`; admins see
all, members see reservations whose resource lists them as approver. Past
pending requests stay listed until decided or cancelled.

### 5.4 Notifications

`NotificationMailer::sendToUser(MailNotification $type, User $user, Mailable $mail): bool`
— the single place where **both** switches are consulted: the admin switch
(existing `send()`) and, for user-configurable types, the user's preference.
Skips are logged like today.

`UserNotificationPreferences` (new service, mirrors `MailNotificationSettings`):

```php
all(User $u): array<string, bool>        // configurable types only, missing = true
wants(User $u, MailNotification $t): bool
update(User $u, array $changes): array   // partial; writes users.notificationPrefs; audits USER update
```

`ReservationNotifier` (new, same shape as `AdminNotifier`; failures are
logged, never thrown):

- `approvalRequested(Reservation $r)` — recipients are the resource's
  **active** approvers via `sendToUser`. If the list is empty, one mail to
  `config('mail.admin_address')` via `send()` (no personal preference
  applies). Content: resource identifier + name, range, booker name and
  contact, note, button to `{app.url}/approvals`.
- `decided(Reservation $r)` — recipient is `$r->creator` (the account that
  created it) via `sendToUser`. No creator (deleted account) → skip. Content:
  approved/rejected, resource, range, decision note, button to
  `{app.url}/reservations?mine=1`. When a member books for someone else, the
  member gets the mail, not the third person.

Mailables + Blade views (Slovak, using `emails.layout`):
`ReservationApprovalRequestedMail` / `emails.reservation-approval-requested`,
`ReservationDecidedMail(bool $approved, …)` / `emails.reservation-decided`.

### 5.5 Resources

- `CreateResourceRequest` / `UpdateResourceRequest`: `requiresApproval`
  (`boolean`), `approverIds` (`array`, each `uuid` + `exists:users,id`).
- `ResourcesService::create/update`: pull `approverIds` out of the input
  before `fill`, save, then `approvers()->sync($ids)` when the key is present.
  Reject approvers who are not `MEMBER`/`ADMIN` with a `ValidationException`
  (*"Schvaľovateľ musí byť potvrdený člen."*). `list()`/`findById()` eager-load
  `approvers`.
- `AuditSnapshot::resource` adds `requiresApproval` and `approverIds` (sorted),
  so changes to the approver list land in the audit log.
- `ResourceResource`: `requiresApproval` (public), `approvers` as
  `[{id, name}]` for `isMember()` viewers, `null` otherwise (resolve the
  viewer with `$request->user('sanctum')` — the route is public).
- `AvailabilityService::renderResource` adds `requiresApproval`.

### 5.6 Reservation API shape

`ReservationResource` adds `decidedById`, `decidedAt`, `decisionNote`
(`decisionNote` for `isMember()` viewers only, `null` otherwise).
`AuditSnapshot::reservation` adds `decisionNote`.

`UpdateReservationRequest.status`: `Rule::in(['CONFIRMED','CANCELLED'])` —
the two approval statuses can never be set through PATCH.

`ListReservationsRequest.status`: normalised to an array in
`prepareForValidation` (scalar → one-element array); rules
`status => nullable|array`, `status.* => Enum(ReservationStatus)`. axios sends
arrays as `status[]=…`, which Laravel parses natively.

ICS: `STATUS:TENTATIVE` for `PENDING_APPROVAL`, `CONFIRMED` for confirmed,
`CANCELLED` for cancelled and rejected.

### 5.7 Routes

| Method + path | Middleware | Notes |
|---|---|---|
| `GET /reservations/approvals` | `auth:sanctum`, `member` | **registered next to `/reservations/mine`, before `GET /reservations/{id}`**, else `{id}` swallows it. Paginated `ReservationResource`. |
| `POST /reservations/{id}/approve` | `auth:sanctum`, `member` | body `{ note?: string ≤1000 }` via `DecideReservationRequest`; approver check inside the service |
| `POST /reservations/{id}/reject` | `auth:sanctum`, `member` | same |
| `GET /profile/notifications` | `auth:sanctum` | `{ notifications: [{key,label,description,enabled}] }`, configurable types only |
| `PATCH /profile/notifications` | `auth:sanctum` | partial `{ key: bool }`; unknown keys → 400 validation |
| `POST /resources`, `PATCH /resources/{id}` | `auth:sanctum`, `admin` | now accept `requiresApproval`, `approverIds` |

### 5.8 Events

`EventsService::attachResources(string $eventId, array $resourceIds, ?User $actor = null)`
stamps `createdById`/`memberId` from the actor on each created reservation
(the controller passes `$request->user()`). Attaching an approval-required
resource therefore produces a pending reservation and notifies approvers, the
same as a direct booking. Side benefit: event reservations now have a creator.

## 6. Frontend (Vue 3, `frontend/src`)

### 6.1 Contract + labels

- `api/types.ts`: `ReservationStatus` gains `PENDING_APPROVAL`, `REJECTED`;
  new `RESERVATION_BLOCKING_STATUSES` constant; `Resource.requiresApproval`,
  `Resource.approvers: {id,name}[] | null`; `Reservation.decidedById /
  decidedAt / decisionNote`; `AuditAction` gains `APPROVE`, `REJECT`;
  new `NotificationPreference` interface.
- `i18n/labels.ts`: `PENDING_APPROVAL: 'Čaká na schválenie'`,
  `REJECTED: 'Zamietnutá'`; `APPROVE: 'Schválenie'`, `REJECT: 'Zamietnutie'`;
  `NAV_LABELS.approvals: 'Na schválenie'`.
- `api/reservations.api.ts`: `status?: ReservationStatus | ReservationStatus[]`;
  `approvals(params)`, `approve(id, note?)`, `reject(id, note?)`.
- `api/resources.api.ts`: `requiresApproval?`, `approverIds?` on the inputs.
- `api/profile.api.ts`: `notifications()`, `setNotifications(changes)`.

### 6.2 New building blocks

- `components/ui/ReservationStatusPill.vue` — one place for status text +
  colour (pending amber, rejected red, cancelled slate, confirmed green;
  confirmed hidden unless `show-confirmed`).
- `components/ui/ApprovalDecisionButtons.vue` — Approve / Reject buttons with
  an optional note textarea; emits `decided(reservation)`. Used by the
  approvals page and the reservation dialog.
- `stores/approvals.store.ts` — `items`, `pendingCount`, `refresh()`. Refreshed
  on login/boot for `auth.isMember`, after every decision, and when the
  dashboard mounts. Non-members never call the endpoint.
- `views/ApprovalsView.vue` at `/approvals` (`meta.auth: 'confirmed'`,
  title *Na schválenie*): list of pending reservations the viewer may decide
  — resource, booker, range, note — with `ApprovalDecisionButtons`, empty state.

### 6.3 Changes to existing screens

- `AppShell.vue`: nav item *Na schválenie* (icon ✅, `requires: 'confirmed'`)
  with a count badge; visible when `auth.isAdmin || approvals.pendingCount > 0`.
- `DashboardView.vue`: banner "Na tvoje schválenie čaká N rezervácií →
  Rozhodnúť" when `pendingCount > 0`; status pills in *Moje rezervácie*.
- `ResourceFormView.vue`: checkbox *Vyžaduje schválenie pred rezerváciou*;
  when checked, a searchable checklist of active `MEMBER`/`ADMIN` users (from
  `usersApi.list`) with the hint *"Ak nevyberieš nikoho, žiadosti dostanú
  administrátori."*
- `ResourcesView.vue`: pill *Schvaľuje sa*. `ResourceDetailView.vue`: same pill
  plus approver names for members; current/upcoming lists include pending.
- `ReservationFormView.vue`: tiles/rows of approval-required resources carry a
  small *Schvaľuje sa* badge. With such a resource selected: info box; submit
  label *Odoslať žiadosť o rezerváciu*; for non-members the submit is replaced
  by a login prompt (link to `/login` with redirect back). Success card variant
  for `status === 'PENDING_APPROVAL'`: *"Žiadosť odoslaná — čaká na schválenie.
  O výsledku ťa budeme informovať e-mailom na {email}."*
- `ReservationEditDialog.vue`: status pill in the header; `canDecide =
  auth.isAdmin || resource.approvers?.some(a => a.id === auth.user?.id)`
  (resource from the resources store by `reservation.resourceId`); shows
  `ApprovalDecisionButtons` when pending and `canDecide`, and the decision note
  when rejected. A decision emits `saved` so the parent refreshes.
- `TimelineView`, `CalendarView`, `SpacesView`, `AvailabilityHints`,
  `EventDetailView`, `ResourceDetailView`: request
  `status: RESERVATION_BLOCKING_STATUSES` and render pending distinctly
  (timeline: amber block with dashed ring; calendar/lists: pill or ⏳ prefix).
  `EventDetailView.attachedResourceIds` uses the blocking set.
- `ReservationsView.vue`: default `status = RESERVATION_BLOCKING_STATUSES`;
  the toggle becomes *Zobraziť zrušené a zamietnuté* (drops the filter);
  status pill per row.
- `ProfileView.vue`: section *E-mailové notifikácie* with a toggle per entry
  from `GET /profile/notifications`.
- `AdminDiagnosticsView.vue`: no code change; the two new switches appear from
  the API.

## 7. Documentation

- `docs/spec/02-reservations.md` (Slovak, matching the file): model table
  gains the three decision columns and the four statuses; REZ-006 (status at
  creation) and REZ-010/011/012 (blocking statuses) are reworded; new section
  *Schvaľovanie* with REZ-050 onwards covering: admin flag + approver list,
  member-only booking (403), pending blocks the slot, approver notification +
  admin fallback, who may decide, approve/reject effects and recorded fields,
  finality (409), booker notification, PATCH status lock, cancel of pending,
  scoped approvals list, multi-status list filter, per-user notification
  preferences (configurable types only, default on, admin switch still wins),
  ICS `TENTATIVE`. Each rule names its Enforced location and test, per the spec
  README.
- `docs/AUTH-AND-PERMISSIONS.md`: matrix rows for the new endpoints, the rule
  "approval-required resources need a confirmed member to book", visibility of
  `approvers` and `decisionNote`, and the `/approvals` SPA route gate.

## 8. Testing

Backend (`vendor/bin/phpunit`, SQLite; Postgres test for the constraint):

- `ReservationApprovalApiTest` (new): booking an approval-required resource
  as member → 201 `PENDING_APPROVAL`; as anonymous and as `PENDING` → 403
  with the code; approvers receive `ReservationApprovalRequestedMail`; no
  approvers → mail to the admin address; approve → `CONFIRMED` + decided
  fields + `ReservationDecidedMail`; reject → `REJECTED`, slot bookable again,
  mail; non-approver member → 403; admin may decide; second decision → 409;
  PATCH status on pending/rejected → 409; PATCH `CONFIRMED` on a cancelled
  reservation of an approval-required resource → 409; cancel pending →
  `CANCELLED`; `/reservations/approvals` shows only the caller's items; event
  attach of an approval-required resource creates a pending reservation.
- `MailNotificationTogglesTest`: the two new types in both admin states.
- `UserNotificationPreferencesTest` (new): default on, partial update,
  unknown key → 400, preference off skips the mail, admin switch off skips
  regardless of preference, audit row written.
- `ResourcesApiTest`: `requiresApproval` + `approverIds` round-trip, sync on
  update, non-member approver → 400, anonymous sees `approvers: null`, audit
  snapshot includes approver changes.
- `ReservationsApiTest`: list `status[]` with two values; ICS `TENTATIVE`.
- `PostgresExcludeConstraintTest`: a pending row blocks a confirmed insert; a
  rejected row does not.

Frontend (`pnpm test`): `ReservationStatusPill.spec.ts`,
`ApprovalsView.spec.ts` (renders items, approve calls the API and refreshes),
`ProfileView` notifications section (toggles call the API),
`ReservationFormView` approval notice + login prompt for anonymous.

## 9. Rollout

Ordinary deploy; both migrations are additive and run automatically. Existing
rows are untouched (`requiresApproval = false`, statuses unchanged). Deploy to
`test_rezervacie.lodenicakvs.sk` first, then production. After deploy an admin
flags the relevant resources and picks approvers; nothing changes until they do.

## 10. Explicitly out of scope (possible follow-ups)

- Auto-expiry or reminders for pending requests.
- Re-notifying approvers when a pending reservation's time is edited.
- Notifying approvers when a request is withdrawn (cancelled).
- Notifying the third person when a member books for someone else.
- Anonymous booking of approval-required resources (decided against).
- Hiding inactive spaces on the *Priestory* page (pre-existing gap).
