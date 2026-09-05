# Reservation Approval Workflow Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let an admin flag a resource as "requires approval", have bookings of such resources wait for a listed approver's decision (with e-mails both ways), and let every user switch their own e-mails on/off in the profile.

**Architecture:** Two new reservation statuses (`PENDING_APPROVAL` blocks the slot, `REJECTED` frees it) ride on the existing `ReservationsService`; a separate `ReservationApprovalService` owns who-may-decide and the race-safe decision; a `ReservationNotifier` sends mail through the existing `NotificationMailer`, which gains a `sendToUser()` that also honours a per-user JSON preference. The SPA gets a status pill, an approvals page + store, approve/reject buttons in the reservation dialog, and admin controls on the resource form.

**Tech Stack:** Laravel 11 (PHP 8.3, Eloquent, Sanctum, Blade mail, PHPUnit on SQLite + optional Postgres), Vue 3 + TypeScript + Pinia + Vue Router + Tailwind, Vitest + @vue/test-utils, pnpm.

**Spec:** `docs/superpowers/specs/2026-09-05-reservation-approval-design.md` — read it first; every task below cites the section it implements.

## Global Constraints

- Migrations are **additive only** (add tables/nullable columns/indexes; never `migrate:fresh`/rollback on prod). Postgres-first with an SQLite fallback; new tables use `camelCase` columns (CORE-011) and `createdAt`/`updatedAt` (CORE-012).
- Every user-facing string (API error messages, e-mail bodies, SPA text) is **Slovak**. Code, comments, commit messages are English.
- Commit messages: `<type>(<scope>): short summary`, lowercase, ≤72 chars, body wrapped at ~72, trailer `Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>`. Stage files explicitly (`git add <paths>`), never `git add -A`. `docs/spec/` is currently **untracked** work in progress owned by the user — you may edit `docs/spec/02-reservations.md` (Task 1) and stage exactly that file; do not stage the rest of `docs/spec/`.
- Public routes (no `auth:sanctum`) resolve the caller with `$request->user('sanctum') ?? $request->user()` (CORE-031). Name/contact/PII fields are `null` for non-members (CORE-030).
- Behaviour for resources **without** `requiresApproval` must not change. Baseline before this plan: backend `vendor/bin/phpunit` → 304 tests, 301 passed, 3 skipped; frontend `pnpm test` → 35 passed; `pnpm typecheck` clean.
- Commands run from `backend-php/` (`vendor/bin/phpunit …`) or `frontend/` (`pnpm test`, `pnpm typecheck`). Never run composer/npm from the repo root.
- Every approval-related domain rule has an ID (REZ-050 … REZ-063) defined in Task 1; reference them in code comments where a rule is enforced.

---

## File map

**Backend (`backend-php/`)**

| File | Responsibility |
|---|---|
| `app/Domain/Enums/ReservationStatus.php` | + `PENDING_APPROVAL`, `REJECTED`, `blocksSlot()`, `blocking()`, `blockingValues()` |
| `app/Domain/Enums/AuditAction.php` | + `APPROVE`, `REJECT` |
| `app/Domain/Enums/MailNotification.php` | + two cases, `isUserConfigurable()` |
| `database/migrations/2026_09_05_000000_add_approval_values_to_reservationstatus_enum.php` | Postgres enum values (no transaction) |
| `database/migrations/2026_09_05_010000_add_reservation_approval.php` | columns, pivot table, EXCLUDE constraint swap |
| `app/Models/Resource.php` | `requiresApproval` cast, `approvers()`, `isApprover()`, `label()` |
| `app/Models/Reservation.php` | decision casts, `decidedBy()`, `isPendingApproval()`, `blocksSlot()`, `rangeLabel()` |
| `app/Models/User.php` | `notificationPrefs` cast |
| `app/Services/UserNotificationPreferences.php` | per-user switches (new) |
| `app/Services/NotificationMailer.php` | + `sendToUser()` |
| `app/Services/ReservationNotifier.php` | approval e-mails (new) |
| `app/Mail/ReservationApprovalRequestedMail.php`, `app/Mail/ReservationDecidedMail.php` + `resources/views/emails/reservation-approval-requested.blade.php`, `reservation-decided.blade.php` | mailables + templates (new) |
| `app/Exceptions/ApprovalMemberRequiredException.php`, `ReservationStatusLockedException.php`, `ReservationNotPendingException.php` | new domain errors |
| `app/Services/ReservationsService.php` | pending creation, status lock, blocking overlaps, multi-status list |
| `app/Services/ReservationApprovalService.php` | approve/reject/canDecide/pendingFor (new) |
| `app/Http/Controllers/Api/ReservationApprovalsController.php`, `app/Http/Requests/DecideReservationRequest.php` | endpoints (new) |
| `app/Http/Controllers/Api/ProfileController.php` | + notifications GET/PATCH |
| `app/Http/Requests/ListReservationsRequest.php`, `UpdateReservationRequest.php`, `CreateResourceRequest.php`, `UpdateResourceRequest.php` | validation changes |
| `app/Http/Resources/ReservationResource.php`, `ResourceResource.php` | new fields |
| `app/Services/ResourcesService.php`, `AuditSnapshot.php`, `AvailabilityService.php`, `EventsService.php` | approvers sync, snapshots, blocking statuses, actor on attach |
| `app/Http/Controllers/Api/ReservationsController.php` (ICS), `EventsController.php` | TENTATIVE, pass actor |
| `routes/api.php` | new routes |

**Frontend (`frontend/src/`)**

| File | Responsibility |
|---|---|
| `api/types.ts`, `i18n/labels.ts` | contract + Slovak labels |
| `api/reservations.api.ts`, `api/resources.api.ts`, `api/profile.api.ts` | new calls/params |
| `components/ui/ReservationStatusPill.vue` (+ spec) | one place for status text/colour |
| `components/ui/ApprovalDecisionButtons.vue` (+ spec) | approve/reject with note |
| `stores/approvals.store.ts` | pending list + count |
| `views/ApprovalsView.vue` (+ spec), `router/index.ts`, `components/layout/AppShell.vue` | approvals page, route, nav badge |
| `views/DashboardView.vue`, `TimelineView.vue`, `CalendarView.vue`, `SpacesView.vue`, `ResourceDetailView.vue`, `EventDetailView.vue`, `ReservationsView.vue`, `components/ui/AvailabilityHints.vue`, `components/ui/ReservationEditDialog.vue` | blocking statuses + pending rendering + decision UI |
| `views/ReservationFormView.vue` | approval notice, login gate, request wording |
| `views/ResourceFormView.vue`, `ResourcesView.vue`, `ResourceDetailView.vue` | admin flag + approver picker + badges |
| `views/ProfileView.vue` (+ spec) | e-mail preferences |

**Docs:** `docs/spec/02-reservations.md`, `docs/AUTH-AND-PERMISSIONS.md`.

---

### Task 1: Spec first — REZ-050…063 and the permission matrix

The spec README rules that behaviour goes into the spec before the code. This task writes the binding rules the later tasks enforce and test. Test names used in the `Test:` lines are the ones Tasks 2–7 create — keep them in sync if you rename anything.

**Files:**
- Modify: `docs/spec/02-reservations.md`
- Modify: `docs/AUTH-AND-PERMISSIONS.md`

- [ ] **Step 1: Update the model table and the rules that change meaning**

In `docs/spec/02-reservations.md`, replace the `status` row of the model table:

```markdown
| `status` | `CONFIRMED`, `PENDING_APPROVAL`, `CANCELLED` alebo `REJECTED` — pozri Schvaľovanie. |
| `decidedById`, `decidedAt` | Kto a kedy o čakajúcej rezervácii rozhodol. `null`, kým sa nerozhodne. |
| `decisionNote` | Voliteľná poznámka schvaľovateľa k rozhodnutiu. Vidí ju iba potvrdený člen. |
```

Replace the text of REZ-006 (keep the ID):

```markdown
**REZ-006** — Novovytvorená rezervácia má stav `CONFIRMED`; pri zdroji
vyžadujúcom schválenie `PENDING_APPROVAL` (pozri REZ-052). Stav sa pri
vytváraní nedá zadať.
Vynútené: `ReservationsService::create`
Test: `ReservationsApiTest::test_create_reservation`,
`ReservationApprovalApiTest::test_member_booking_of_a_gated_resource_waits_for_approval`
```

Replace REZ-010's first sentence with: `Dve rezervácie toho istého zdroja v **blokujúcom stave** (\`CONFIRMED\` alebo \`PENDING_APPROVAL\`) sa NESMÚ časovo prekrývať.` and add `ReservationStatus::blocksSlot` to its Vynútené line.

In REZ-011 replace the constraint text with:
`EXCLUDE USING gist (resourceId WITH =, tsrange(startsAt, endsAt, '[)') WITH &&) WHERE status IN ('CONFIRMED', 'PENDING_APPROVAL')`

Replace REZ-012's first sentence with: `Zrušená alebo zamietnutá rezervácia prekryv **netvorí**. Uvoľnený termín sa dá obsadiť znova.` and its Vynútené with `WHERE status IN ('CONFIRMED','PENDING_APPROVAL')` v constrainte, `ReservationStatus::blocksSlot`.

Replace REZ-024 with:

```markdown
**REZ-024** — Opakované zrušenie už zrušenej alebo zamietnutej rezervácie
NESMIE zmeniť stav ani zapísať ďalší audit záznam.
Vynútené: `ReservationsService::cancel` (`blocksSlot()` guard)
Test: `ReservationApprovalApiTest::test_cancelling_a_rejected_reservation_is_a_no_op`
```

- [ ] **Step 2: Add the Schvaľovanie section**

Insert before `## Známe medzery`:

```markdown
## Schvaľovanie

Niektoré zdroje (klubovňa, príves, drahá loď) nemá zmysel dávať voľne.
Správca ich označí ako **vyžadujúce schválenie** a vyberie, kto smie
schvaľovať. Rezervácia takého zdroja nevzniká potvrdená, ale **čaká**,
kým o nej niekto rozhodne. Zdroje bez tohto príznaku fungujú presne ako
doteraz.

**REZ-050** — Správca MÔŽE zdroj označiť príznakom `requiresApproval` a
priradiť mu zoznam schvaľovateľov (potvrdených členov alebo správcov).
Predvolene je príznak vypnutý a zoznam prázdny. Zmeny zoznamu sa auditujú.
Vynútené: `ResourcesService::create/update`, `CreateResourceRequest`,
`UpdateResourceRequest`, tabuľka `resource_approvers`
Test: `ResourcesApiTest::test_admin_sets_requires_approval_and_approvers`,
`ResourcesApiTest::test_approver_must_be_a_confirmed_member`

**REZ-051** — Zdroj vyžadujúci schválenie MÔŽE rezervovať iba potvrdený
člen. Anonymný návštevník aj účet v stave `PENDING` dostanú **HTTP 403** s
kódom `RESERVATION_APPROVAL_MEMBER_REQUIRED`. Zámerne 403 aj pre anonyma:
SPA pri 401 zahodí prihlásenie, čo by čakajúci účet odhlásilo.
Vynútené: `ReservationsService::create` → `ApprovalMemberRequiredException`
Test: `ReservationApprovalApiTest::test_anonymous_cannot_book_a_gated_resource`,
`ReservationApprovalApiTest::test_pending_account_cannot_book_a_gated_resource`

**REZ-052** — Rezervácia zdroja vyžadujúceho schválenie vzniká v stave
`PENDING_APPROVAL`. Tento stav **blokuje termín** rovnako ako `CONFIRMED`,
takže schválenie nikdy nenarazí na prekryv.
Vynútené: `ReservationsService::create`, `ReservationStatus::blocksSlot`,
constraint `reservations_no_overlap_excl`
Test: `ReservationApprovalApiTest::test_member_booking_of_a_gated_resource_waits_for_approval`,
`ReservationApprovalApiTest::test_a_pending_request_holds_the_slot`,
`PostgresExcludeConstraintTest::test_exclude_constraint_treats_pending_approval_as_occupied`

**REZ-053** — Pri vzniku čakajúcej rezervácie systém pošle e-mail každému
aktívnemu schvaľovateľovi zdroja, každému samostatne. Ak zdroj nemá
schvaľovateľov, ide jeden e-mail na klubovú adresu (`mail.admin_address`).
Vynútené: `ReservationNotifier::approvalRequested`
Test: `ReservationNotifierTest`

**REZ-054** — O čakajúcej rezervácii MÔŽE rozhodnúť správca, alebo člen
uvedený v zozname schvaľovateľov daného zdroja. Ostatní dostanú 403.
Vynútené: `ReservationApprovalService::canDecide`
Test: `ReservationApprovalApiTest::test_member_outside_the_approver_list_cannot_decide`,
`ReservationApprovalApiTest::test_admin_can_decide_without_being_listed`

**REZ-055** — Schválenie mení stav na `CONFIRMED`, zamietnutie na
`REJECTED`. Zamietnutá rezervácia termín **uvoľňuje**. Pri oboch sa
zaznamená kto rozhodol (`decidedById`), kedy (`decidedAt`) a voliteľná
poznámka (`decisionNote`); audit dostane akciu `APPROVE` alebo `REJECT`.
Vynútené: `ReservationApprovalService::approve/reject`
Test: `ReservationApprovalApiTest::test_approver_approves`,
`ReservationApprovalApiTest::test_approver_rejects_and_frees_the_slot`

**REZ-056** — Rozhodnutie je konečné. Ďalší pokus o rozhodnutie vracia
**HTTP 409** s kódom `RESERVATION_NOT_PENDING`. Súbežné rozhodnutia rieši
podmienený zápis (`UPDATE … WHERE status = 'PENDING_APPROVAL'`) — vyhrá
prvý, druhý dostane 409.
Vynútené: `ReservationApprovalService::decide`
Test: `ReservationApprovalApiTest::test_second_decision_is_rejected_with_409`

**REZ-057** — Po rozhodnutí systém pošle e-mail účtu, ktorý rezerváciu
vytvoril. Keď člen rezervoval za niekoho iného, e-mail dostane člen, nie
tá osoba.
Vynútené: `ReservationNotifier::decided`
Test: `ReservationApprovalApiTest::test_approver_approves`,
`ReservationNotifierTest::test_decision_goes_to_the_creator`

**REZ-058** — Stav rezervácie v stave `PENDING_APPROVAL` alebo `REJECTED`
sa NEDÁ zmeniť bežnou úpravou (PATCH); pokus vracia **HTTP 409**
`RESERVATION_STATUS_LOCKED`. Na zdroji vyžadujúcom schválenie sa cez PATCH
NEDÁ dostať do `CONFIRMED` — jediná cesta je schválenie. Ostatné polia
(čas, meno, poznámka) sa upravovať dajú; rezervácia zostáva čakajúca a
schvaľovatelia sa o úprave e-mailom nedozvedia.
Vynútené: `ReservationsService::update`, `UpdateReservationRequest`
Test: `ReservationApprovalApiTest::test_patch_cannot_change_status_of_a_pending_reservation`,
`ReservationApprovalApiTest::test_patch_cannot_confirm_on_a_gated_resource`,
`ReservationApprovalApiTest::test_patch_of_other_fields_keeps_the_request_pending`

**REZ-059** — Zrušenie čakajúcej rezervácie ju prevedie do `CANCELLED`
(rezervujúci žiadosť stiahol) a termín uvoľní. Schvaľovatelia o tom
e-mail nedostanú.
Vynútené: `ReservationsService::cancel`
Test: `ReservationApprovalApiTest::test_cancelling_a_pending_request_frees_the_slot`

**REZ-060** — `GET /reservations/approvals` vracia čakajúce rezervácie, o
ktorých MÔŽE volajúci rozhodnúť: správcovi všetky, členovi tie zo zdrojov,
kde je schvaľovateľom. Neprihlásený dostane 401, účet `PENDING` 403.
Vynútené: `ReservationApprovalService::pendingFor`, `routes/api.php`
Test: `ReservationApprovalApiTest::test_approvals_list_is_scoped_to_the_caller`

**REZ-061** — Filter `status` v zozname rezervácií prijíma aj viac hodnôt
(`status[]=…`), aby si rozvrhové obrazovky vypýtali potvrdené a čakajúce
jedným dotazom. Čakajúca rezervácia MUSÍ byť v rozvrhu vizuálne odlíšená.
Vynútené: `ListReservationsRequest`, `ReservationsService::list`;
SPA `ReservationStatusPill.vue`, `TimelineView.vue`
Test: `ReservationsApiTest::test_list_accepts_multiple_statuses`,
`ReservationStatusPill.spec.ts`

**REZ-062** — Používateľ si MÔŽE v profile vypnúť e-maily označené ako
používateľsky nastaviteľné (`MailNotification::isUserConfigurable`).
Predvolene sú zapnuté. Vypnutie správcom v diagnostike má prednosť —
používateľská preferencia e-mail nikdy nezapne, iba vypne. Zmena sa
audituje.
Vynútené: `UserNotificationPreferences`, `NotificationMailer::sendToUser`,
`ProfileController::notifications/updateNotifications`
Test: `UserNotificationPreferencesTest`

**REZ-063** — Kalendárový súbor `.ics` čakajúcej rezervácie má
`STATUS:TENTATIVE`; zamietnutá a zrušená majú `CANCELLED`.
Vynútené: `ReservationsController::ics`
Test: `ReservationIcsApiTest::test_ics_marks_a_pending_reservation_tentative`
```

Add to `## Známe medzery`: `- Schvaľovanie nemá automatickú expiráciu čakajúcich žiadostí ani pripomienky; úprava času čakajúcej rezervácie schvaľovateľov znova neupozorní.`

- [ ] **Step 3: Update `docs/AUTH-AND-PERMISSIONS.md`**

Read-access table — add rows:

```markdown
| `approvers` (who may approve) on a resource | ❌ (null) | ❌ (null) | ✅ | ✅ |
| `decisionNote` on a reservation | ❌ (null) | ❌ (null) | ✅ | ✅ |
| Pending approvals list (`GET /reservations/approvals`) | ❌ (401) | ❌ (403) | ✅ (resources they approve) | ✅ (all) |
| Own e-mail preferences (`GET /profile/notifications`) | ❌ (401) | ✅ | ✅ | ✅ |
```

Write-access table — add rows:

```markdown
| Book a resource that `requiresApproval` (`POST /reservations`) → lands `PENDING_APPROVAL` | ❌ (403) | ❌ (403) | ✅ | ✅ |
| Approve / reject a pending reservation (`POST /reservations/{id}/approve`, `…/reject`) | ❌ (401) | ❌ (403) | ✅ only if listed approver of that resource, else 403 | ✅ |
| Set `requiresApproval` + `approverIds` on a resource | ❌ | ❌ | ❌ | ✅ |
| Change own e-mail preferences (`PATCH /profile/notifications`) | ❌ (401) | ✅ | ✅ | ✅ |
```

Layer 1 table: in the `auth:sanctum` row append `, own e-mail preferences (/profile/notifications)`; in the `auth:sanctum, member` row append `; approvals list + approve/reject (approver check inside ReservationApprovalService)`.

Under Layer 2 add a bullet: `- app/Http/Resources/ResourceResource.php — approvers is null for non-members (member names are member-only, CORE-030).`

In the SPA route gate note add: `/approvals` is `'confirmed'`.

Related tests: add `backend-php/tests/Feature/Api/ReservationApprovalApiTest.php — gated booking (403 for anon/PENDING), approver/admin decisions, 403 for non-approvers, scoped approvals list.` and `backend-php/tests/Feature/UserNotificationPreferencesTest.php — own preference switches.`

- [ ] **Step 4: Verify and commit**

Run: `grep -c "REZ-05\|REZ-06" docs/spec/02-reservations.md` — expected ≥ 14. Run: `grep -n "approvals" docs/AUTH-AND-PERMISSIONS.md` — expected several hits.

```bash
git add docs/spec/02-reservations.md docs/AUTH-AND-PERMISSIONS.md
git commit -m "docs(spec): approval workflow rules REZ-050..063 and permission matrix

Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>"
```

---

### Task 2: Enums, migrations and model fields

Spec §3, §4, §5.1.

**Files:**
- Modify: `backend-php/app/Domain/Enums/ReservationStatus.php`
- Modify: `backend-php/app/Domain/Enums/AuditAction.php`
- Modify: `backend-php/app/Domain/Enums/MailNotification.php`
- Create: `backend-php/database/migrations/2026_09_05_000000_add_approval_values_to_reservationstatus_enum.php`
- Create: `backend-php/database/migrations/2026_09_05_010000_add_reservation_approval.php`
- Modify: `backend-php/app/Models/Resource.php`, `Reservation.php`, `User.php`
- Test: `backend-php/tests/Unit/ReservationStatusTest.php`, `tests/Unit/MailNotificationTest.php`, `tests/Feature/ApprovalSchemaTest.php`, `tests/Feature/PostgresExcludeConstraintTest.php`

**Interfaces:**
- Produces: `ReservationStatus::PENDING_APPROVAL`, `::REJECTED`, `->blocksSlot(): bool`, `::blocking(): list<ReservationStatus>`, `::blockingValues(): list<string>`; `AuditAction::APPROVE/REJECT`; `MailNotification::RESERVATION_APPROVAL_REQUESTED` (`reservation_approval_requested`), `::RESERVATION_DECIDED` (`reservation_decided`), `->isUserConfigurable(): bool`; `Resource::approvers(): BelongsToMany`, `->isApprover(User): bool`, `->label(): string`, attribute `requiresApproval`; `Reservation` attributes `decidedById`, `decidedAt`, `decisionNote`, `->decidedBy()`, `->isPendingApproval()`, `->blocksSlot()`, `->rangeLabel(): string`; `User` attribute `notificationPrefs` (array cast).

- [ ] **Step 1: Write the failing enum tests**

`tests/Unit/ReservationStatusTest.php`:

```php
<?php

namespace Tests\Unit;

use App\Domain\Enums\ReservationStatus;
use PHPUnit\Framework\TestCase;

class ReservationStatusTest extends TestCase
{
    public function test_confirmed_and_pending_approval_block_the_slot(): void
    {
        $this->assertTrue(ReservationStatus::CONFIRMED->blocksSlot());
        $this->assertTrue(ReservationStatus::PENDING_APPROVAL->blocksSlot());
    }

    public function test_cancelled_and_rejected_free_the_slot(): void
    {
        $this->assertFalse(ReservationStatus::CANCELLED->blocksSlot());
        $this->assertFalse(ReservationStatus::REJECTED->blocksSlot());
    }

    public function test_blocking_values_lists_exactly_the_blocking_statuses(): void
    {
        $this->assertSame(['CONFIRMED', 'PENDING_APPROVAL'], ReservationStatus::blockingValues());
        $this->assertCount(2, ReservationStatus::blocking());
    }
}
```

`tests/Unit/MailNotificationTest.php`:

```php
<?php

namespace Tests\Unit;

use App\Domain\Enums\MailNotification;
use PHPUnit\Framework\TestCase;

class MailNotificationTest extends TestCase
{
    public function test_only_the_two_reservation_notifications_are_user_configurable(): void
    {
        $configurable = array_map(
            fn (MailNotification $t) => $t->value,
            array_values(array_filter(MailNotification::cases(), fn (MailNotification $t) => $t->isUserConfigurable())),
        );

        $this->assertSame(['reservation_approval_requested', 'reservation_decided'], $configurable);
    }

    public function test_every_case_has_copy_and_is_not_critical_unless_it_unlocks_an_account(): void
    {
        foreach (MailNotification::cases() as $type) {
            $this->assertNotSame('', $type->label(), $type->value);
            $this->assertNotSame('', $type->description(), $type->value);
            $this->assertNotSame('', $type->consequence(), $type->value);
        }
        $this->assertFalse(MailNotification::RESERVATION_APPROVAL_REQUESTED->isCritical());
        $this->assertFalse(MailNotification::RESERVATION_DECIDED->isCritical());
    }
}
```

- [ ] **Step 2: Run them to see them fail**

Run: `cd backend-php && vendor/bin/phpunit tests/Unit/ReservationStatusTest.php tests/Unit/MailNotificationTest.php`
Expected: errors — undefined case `PENDING_APPROVAL`, undefined method `isUserConfigurable`.

- [ ] **Step 3: Implement the enums**

`app/Domain/Enums/ReservationStatus.php`:

```php
<?php

namespace App\Domain\Enums;

enum ReservationStatus: string
{
    case CONFIRMED = 'CONFIRMED';
    /** Booked on a resource that requires approval; waiting for an approver (REZ-052). */
    case PENDING_APPROVAL = 'PENDING_APPROVAL';
    case CANCELLED = 'CANCELLED';
    /** An approver turned the request down (REZ-055). Frees the slot like CANCELLED. */
    case REJECTED = 'REJECTED';

    /**
     * Whether a reservation in this status occupies its time slot. A request
     * awaiting approval holds the slot so approving it can never collide;
     * cancelled and rejected ones give it back. Everything that asks "is the
     * slot taken" — overlap checks, the dashboard, the DB constraint — keys
     * off this, never off CONFIRMED alone.
     */
    public function blocksSlot(): bool
    {
        return match ($this) {
            self::CONFIRMED, self::PENDING_APPROVAL => true,
            self::CANCELLED, self::REJECTED => false,
        };
    }

    /** @return list<self> */
    public static function blocking(): array
    {
        return array_values(array_filter(self::cases(), fn (self $s) => $s->blocksSlot()));
    }

    /** @return list<string> */
    public static function blockingValues(): array
    {
        return array_map(fn (self $s) => $s->value, self::blocking());
    }
}
```

`app/Domain/Enums/AuditAction.php` — add after `CANCEL`:

```php
    case APPROVE = 'APPROVE';
    case REJECT = 'REJECT';
```

`app/Domain/Enums/MailNotification.php` — full new body (every `match` must list the new cases or PHP throws `UnhandledMatchError`):

```php
<?php

namespace App\Domain\Enums;

/**
 * The transactional e-mails the app sends. Each one can be switched off
 * independently from the admin diagnostics page; the backing value doubles
 * as the JSON key inside the `mail_notifications` setting AND inside a
 * user's `notificationPrefs` for the user-configurable ones.
 *
 * Two of them (password reset, account invitation) are the only way a
 * member can get into their account — {@see isCritical()} marks those so
 * the UI can warn before an admin switches them off. They stay switchable
 * on purpose: when SMTP is broken, disabling the send is what keeps the
 * surrounding request from failing.
 *
 * {@see isUserConfigurable()} marks the ones a member may switch off for
 * themselves in the profile (REZ-062). Operational mail stays mandatory.
 */
enum MailNotification: string
{
    case PASSWORD_RESET = 'password_reset';
    case ACCOUNT_INVITATION = 'account_invitation';
    case MEMBERSHIP_APPROVED = 'membership_approved';
    case PENDING_MEMBER_ADMIN = 'pending_member_admin';
    case RESERVATION_APPROVAL_REQUESTED = 'reservation_approval_requested';
    case RESERVATION_DECIDED = 'reservation_decided';

    public function label(): string
    {
        return match ($this) {
            self::PASSWORD_RESET => 'Obnova hesla',
            self::ACCOUNT_INVITATION => 'Pozvánka do systému',
            self::MEMBERSHIP_APPROVED => 'Členstvo schválené',
            self::PENDING_MEMBER_ADMIN => 'Upozornenie správcovi o novom členovi',
            self::RESERVATION_APPROVAL_REQUESTED => 'Žiadosť o schválenie rezervácie',
            self::RESERVATION_DECIDED => 'Výsledok schvaľovania rezervácie',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::PASSWORD_RESET => 'Odkaz na nastavenie nového hesla po kliknutí na „Zabudnuté heslo“.',
            self::ACCOUNT_INVITATION => 'Odkaz na nastavenie prvého hesla pre účet založený správcom alebo hromadným importom.',
            self::MEMBERSHIP_APPROVED => 'Oznámenie členovi, že správca schválil jeho registráciu.',
            self::PENDING_MEMBER_ADMIN => 'Oznámenie na klubovú adresu, že sa zaregistroval nový člen a čaká na schválenie.',
            self::RESERVATION_APPROVAL_REQUESTED => 'Oznámenie schvaľovateľom zdroja, že niekto požiadal o jeho rezerváciu a čaká na rozhodnutie.',
            self::RESERVATION_DECIDED => 'Oznámenie rezervujúcemu, že jeho žiadosť o rezerváciu bola schválená alebo zamietnutá.',
        };
    }

    /** A member cannot reach their account without this e-mail. */
    public function isCritical(): bool
    {
        return match ($this) {
            self::PASSWORD_RESET, self::ACCOUNT_INVITATION => true,
            self::MEMBERSHIP_APPROVED,
            self::PENDING_MEMBER_ADMIN,
            self::RESERVATION_APPROVAL_REQUESTED,
            self::RESERVATION_DECIDED => false,
        };
    }

    /** A member may switch this one off for themselves in the profile. */
    public function isUserConfigurable(): bool
    {
        return match ($this) {
            self::RESERVATION_APPROVAL_REQUESTED, self::RESERVATION_DECIDED => true,
            self::PASSWORD_RESET,
            self::ACCOUNT_INVITATION,
            self::MEMBERSHIP_APPROVED,
            self::PENDING_MEMBER_ADMIN => false,
        };
    }

    /** What stops working while this notification is switched off. */
    public function consequence(): string
    {
        return match ($this) {
            self::PASSWORD_RESET => 'Členovia si nebudú vedieť obnoviť zabudnuté heslo. Formulár im napriek tomu potvrdí odoslanie — o vypnutí sa nedozvedia.',
            self::ACCOUNT_INVITATION => 'Novo založené účty nedostanú prihlasovacie údaje a nikto sa do nich neprihlási.',
            self::MEMBERSHIP_APPROVED => 'Schválený člen sa o schválení nedozvie e-mailom; prihlásiť sa však už môže.',
            self::PENDING_MEMBER_ADMIN => 'Správcovia nedostanú upozornenie na nového čakajúceho člena — treba ich kontrolovať ručne v zozname používateľov.',
            self::RESERVATION_APPROVAL_REQUESTED => 'Schvaľovatelia sa o čakajúcich žiadostiach nedozvedia e-mailom — musia ich kontrolovať na stránke „Na schválenie“.',
            self::RESERVATION_DECIDED => 'Rezervujúci sa o výsledku dozvie až v systéme, v zozname svojich rezervácií.',
        };
    }
}
```

- [ ] **Step 4: Run the unit tests — they pass**

Run: `vendor/bin/phpunit tests/Unit/ReservationStatusTest.php tests/Unit/MailNotificationTest.php`
Expected: PASS (5 tests).

- [ ] **Step 5: Write the failing schema test**

`tests/Feature/ApprovalSchemaTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Domain\Enums\ResourceType;
use App\Domain\Enums\UserRole;
use App\Models\Resource;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/** The additive approval-workflow schema (spec §3–4) exists and the models read it. */
class ApprovalSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_columns_and_pivot_table_exist(): void
    {
        $this->assertTrue(Schema::hasColumn('resources', 'requiresApproval'));
        $this->assertTrue(Schema::hasTable('resource_approvers'));
        $this->assertTrue(Schema::hasColumns('reservations', ['decidedById', 'decidedAt', 'decisionNote']));
        $this->assertTrue(Schema::hasColumn('users', 'notificationPrefs'));
    }

    public function test_a_resource_does_not_require_approval_by_default(): void
    {
        $r = Resource::create(['identifier' => 'K-9', 'type' => ResourceType::WW_KAYAK, 'name' => 'Nine']);

        $this->assertFalse($r->refresh()->requiresApproval);
        $this->assertSame('K-9 – Nine', $r->label());
    }

    public function test_approvers_relation_round_trips(): void
    {
        $r = Resource::create(['identifier' => 'S-1', 'type' => ResourceType::BOATHOUSE_SPACE, 'name' => 'Klubovňa', 'requiresApproval' => true]);
        $u = User::create(['name' => 'Schvaľovateľ', 'email' => 's@example.test', 'password' => 'password123', 'role' => UserRole::MEMBER, 'isActive' => true]);
        $other = User::create(['name' => 'Iný', 'email' => 'i@example.test', 'password' => 'password123', 'role' => UserRole::MEMBER, 'isActive' => true]);

        $r->approvers()->attach($u->id);

        $this->assertTrue($r->isApprover($u));
        $this->assertFalse($r->isApprover($other));
        $this->assertSame([$u->id], $r->approvers()->pluck('users.id')->all());
    }

    public function test_notification_prefs_are_an_array_cast(): void
    {
        $u = User::create(['name' => 'P', 'email' => 'p@example.test', 'password' => 'password123', 'role' => UserRole::MEMBER, 'isActive' => true]);
        $u->notificationPrefs = ['reservation_decided' => false];
        $u->save();

        $this->assertSame(['reservation_decided' => false], $u->refresh()->notificationPrefs);
    }
}
```

- [ ] **Step 6: Run it to see it fail**

Run: `vendor/bin/phpunit tests/Feature/ApprovalSchemaTest.php`
Expected: FAIL — missing column `requiresApproval`.

- [ ] **Step 7: Write the two migrations**

`database/migrations/2026_09_05_000000_add_approval_values_to_reservationstatus_enum.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Adds the two approval-workflow values to the Postgres "ReservationStatus"
 * enum (spec §4). Runs outside a transaction because ALTER TYPE … ADD VALUE
 * refuses to run inside one, and a value added in a transaction cannot be
 * referenced until that transaction commits — which is why the EXCLUDE
 * constraint that uses the new value lives in the next migration file.
 * SQLite stores the status as TEXT; nothing to do there.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TYPE "ReservationStatus" ADD VALUE IF NOT EXISTS \'PENDING_APPROVAL\'');
        DB::statement('ALTER TYPE "ReservationStatus" ADD VALUE IF NOT EXISTS \'REJECTED\'');
    }

    public function down(): void
    {
        // Postgres cannot drop an enum value once rows may reference it.
        // Same stance as 2026_06_17_000000_add_pending_to_userrole_enum.
    }
};
```

`database/migrations/2026_09_05_010000_add_reservation_approval.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Reservation approval workflow (spec §3–4), all additive:
 *  - resources.requiresApproval — admin flag, default FALSE, so nothing
 *    changes for existing inventory
 *  - resource_approvers — who may approve bookings of that resource
 *  - reservations.decidedById / decidedAt / decisionNote — the decision
 *  - users.notificationPrefs — per-user e-mail switches (JSON, missing = on)
 *  - the no-overlap EXCLUDE constraint now also covers PENDING_APPROVAL,
 *    because a request awaiting approval holds its slot (REZ-052)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('resources', function (Blueprint $table) {
            $table->boolean('requiresApproval')->default(false);
        });

        Schema::create('resource_approvers', function (Blueprint $table) {
            $table->uuid('resourceId');
            $table->uuid('userId');
            $table->timestamp('createdAt')->useCurrent();
            $table->primary(['resourceId', 'userId']);
            $table->foreign('resourceId')->references('id')->on('resources')
                ->cascadeOnUpdate()->cascadeOnDelete();
            $table->foreign('userId')->references('id')->on('users')
                ->cascadeOnUpdate()->cascadeOnDelete();
            $table->index('userId');
        });

        Schema::table('reservations', function (Blueprint $table) {
            $table->uuid('decidedById')->nullable();
            $table->timestamp('decidedAt', 3)->nullable();
            $table->text('decisionNote')->nullable();
            $table->foreign('decidedById')->references('id')->on('users')
                ->cascadeOnUpdate()->nullOnDelete();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->jsonb('notificationPrefs')->nullable();
        });

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE "reservations" DROP CONSTRAINT IF EXISTS "reservations_no_overlap_excl"');
            DB::statement(<<<'SQL'
                ALTER TABLE "reservations" ADD CONSTRAINT "reservations_no_overlap_excl"
                  EXCLUDE USING gist (
                    "resourceId" WITH =,
                    tsrange("startsAt", "endsAt", '[)') WITH &&
                  ) WHERE ("status" IN ('CONFIRMED', 'PENDING_APPROVAL'))
            SQL);
        }
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE "reservations" DROP CONSTRAINT IF EXISTS "reservations_no_overlap_excl"');
            DB::statement(<<<'SQL'
                ALTER TABLE "reservations" ADD CONSTRAINT "reservations_no_overlap_excl"
                  EXCLUDE USING gist (
                    "resourceId" WITH =,
                    tsrange("startsAt", "endsAt", '[)') WITH &&
                  ) WHERE ("status" = 'CONFIRMED')
            SQL);
        }

        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('notificationPrefs'));
        Schema::table('reservations', function (Blueprint $table) {
            $table->dropForeign(['decidedById']);
            $table->dropColumn(['decidedById', 'decidedAt', 'decisionNote']);
        });
        Schema::dropIfExists('resource_approvers');
        Schema::table('resources', fn (Blueprint $table) => $table->dropColumn('requiresApproval'));
    }
};
```

- [ ] **Step 8: Extend the models**

`app/Models/Resource.php` — add `use App\Models\User;` is same namespace (no import needed), add `use Illuminate\Database\Eloquent\Relations\BelongsToMany;`, then:

```php
    protected $attributes = [
        'isActive' => true,
        'requiresApproval' => false,
    ];
```

add to `$casts`: `'requiresApproval' => 'boolean',` and these methods after `damages()`:

```php
    /**
     * Members who may approve a booking of this resource (REZ-050). Admins
     * may always decide and are not listed here.
     */
    public function approvers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'resource_approvers', 'resourceId', 'userId')
            ->withPivot('createdAt');
    }

    public function isApprover(User $user): bool
    {
        return $this->approvers()->whereKey($user->id)->exists();
    }

    /** "K-1 – Kayak 1" — the form used in audit summaries and e-mails. */
    public function label(): string
    {
        return "{$this->identifier} – {$this->name}";
    }
```

`app/Models/Reservation.php` — add casts `'decidedAt' => 'datetime',` and methods:

```php
    /** The approver who confirmed or rejected the request (null while waiting). */
    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decidedById');
    }

    public function isPendingApproval(): bool
    {
        return $this->status === ReservationStatus::PENDING_APPROVAL;
    }

    public function blocksSlot(): bool
    {
        return $this->status->blocksSlot();
    }

    /**
     * "2026-09-10 09:00 – 2026-09-10 12:00" in the wall-clock UTC convention:
     * what the user typed is what we display. Shared by audit summaries and
     * e-mails so they never drift apart.
     */
    public function rangeLabel(): string
    {
        $start = $this->startsAt instanceof \DateTimeInterface ? $this->startsAt : new \DateTimeImmutable((string) $this->startsAt);
        $end = $this->endsAt instanceof \DateTimeInterface ? $this->endsAt : new \DateTimeImmutable((string) $this->endsAt);

        return $start->format('Y-m-d H:i').' – '.$end->format('Y-m-d H:i');
    }
```

`app/Models/User.php` — add to `$casts`: `'notificationPrefs' => 'array',`.

- [ ] **Step 9: Run the schema test and the full suite**

Run: `vendor/bin/phpunit tests/Feature/ApprovalSchemaTest.php` → PASS (4 tests).
Run: `vendor/bin/phpunit` → all green (baseline 301 + 9 new; 3 skipped).

- [ ] **Step 10: Extend the Postgres constraint test (skipped locally, real on a Postgres box)**

In `tests/Feature/PostgresExcludeConstraintTest.php` change the class docblock's first sentence to `…that protects against overlapping slot-blocking reservations (CONFIRMED and PENDING_APPROVAL, REZ-052)…` and add:

```php
    public function test_exclude_constraint_treats_pending_approval_as_occupied(): void
    {
        $space = Resource::create([
            'identifier' => 'TEST-EXCL-4',
            'type' => ResourceType::BOATHOUSE_SPACE,
            'name' => 'Excl test 4',
            'requiresApproval' => true,
        ]);

        $start = CarbonImmutable::parse('2099-04-01T09:00:00Z');
        Reservation::create([
            'resourceId' => $space->id,
            'customerName' => 'A',
            'startsAt' => $start,
            'endsAt' => $start->addHours(3),
            'status' => ReservationStatus::PENDING_APPROVAL,
        ]);

        $caught = null;
        try {
            DB::table('reservations')->insert([
                'id' => (string) \Ramsey\Uuid\Uuid::uuid4(),
                'resourceId' => $space->id,
                'customerName' => 'B',
                'startsAt' => $start->addHour(),
                'endsAt' => $start->addHours(2),
                'status' => 'CONFIRMED',
            ]);
        } catch (QueryException $e) {
            $caught = $e;
        }

        $this->assertNotNull($caught, 'A pending request must hold its slot.');
        $this->assertSame('23P01', $caught->getCode());
    }

    public function test_exclude_constraint_ignores_rejected_reservations(): void
    {
        $space = Resource::create([
            'identifier' => 'TEST-EXCL-5',
            'type' => ResourceType::BOATHOUSE_SPACE,
            'name' => 'Excl test 5',
            'requiresApproval' => true,
        ]);

        $start = CarbonImmutable::parse('2099-05-01T09:00:00Z');
        Reservation::create([
            'resourceId' => $space->id,
            'customerName' => 'A',
            'startsAt' => $start,
            'endsAt' => $start->addHours(3),
            'status' => ReservationStatus::REJECTED,
        ]);

        Reservation::create([
            'resourceId' => $space->id,
            'customerName' => 'B',
            'startsAt' => $start,
            'endsAt' => $start->addHours(3),
            'status' => ReservationStatus::CONFIRMED,
        ]);

        $this->assertSame(2, DB::table('reservations')->where('resourceId', $space->id)->count());
    }
```

Run: `vendor/bin/phpunit tests/Feature/PostgresExcludeConstraintTest.php` → 5 skipped locally (no Postgres). If `pgsql_test` is reachable, run `php artisan migrate --database=pgsql_test` first and expect PASS.

- [ ] **Step 11: Commit**

```bash
git add app/Domain/Enums/ReservationStatus.php app/Domain/Enums/AuditAction.php app/Domain/Enums/MailNotification.php \
  database/migrations/2026_09_05_000000_add_approval_values_to_reservationstatus_enum.php \
  database/migrations/2026_09_05_010000_add_reservation_approval.php \
  app/Models/Resource.php app/Models/Reservation.php app/Models/User.php \
  tests/Unit/ReservationStatusTest.php tests/Unit/MailNotificationTest.php \
  tests/Feature/ApprovalSchemaTest.php tests/Feature/PostgresExcludeConstraintTest.php
git commit -m "feat(reservations): approval statuses, approver pivot and decision columns

PENDING_APPROVAL holds the slot, REJECTED frees it; the EXCLUDE constraint
now covers both blocking statuses. Adds resources.requiresApproval,
resource_approvers, the decision record on reservations and per-user
notificationPrefs. No behaviour change yet — nothing sets the flag.

Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>"
```

---

### Task 3: Notification layer — per-user preferences, `sendToUser`, mailables, `ReservationNotifier`

Spec §5.4. Builds the mail side first so Task 4 can wire it into `create()`.

**Files:**
- Create: `backend-php/app/Services/UserNotificationPreferences.php`
- Modify: `backend-php/app/Services/NotificationMailer.php`
- Create: `backend-php/app/Mail/ReservationApprovalRequestedMail.php`, `app/Mail/ReservationDecidedMail.php`
- Create: `backend-php/resources/views/emails/reservation-approval-requested.blade.php`, `reservation-decided.blade.php`
- Create: `backend-php/app/Services/ReservationNotifier.php`
- Test: `backend-php/tests/Feature/UserNotificationPreferencesTest.php`, `tests/Feature/ReservationNotifierTest.php`

**Interfaces:**
- Consumes: Task 2 enums/models.
- Produces: `UserNotificationPreferences::all(User): array<string,bool>`, `::wants(User, MailNotification): bool`, `::update(User, array): array`, `::configurable(): list<MailNotification>` (static); `NotificationMailer::sendToUser(MailNotification, User, Mailable): bool`; `ReservationNotifier::approvalRequested(Reservation): void`, `::decided(Reservation): void`; mailables `ReservationApprovalRequestedMail(resourceLabel, range, customerName, ?customerContact, ?note, approvalsUrl)`, `ReservationDecidedMail(bool approved, ?name, resourceLabel, range, ?decisionNote, reservationsUrl)`.

- [ ] **Step 1: Write the failing preferences test**

`tests/Feature/UserNotificationPreferencesTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Domain\Enums\AuditEntityType;
use App\Domain\Enums\MailNotification;
use App\Domain\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\UserNotificationPreferences;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** REZ-062: a member's own e-mail switches. Missing key = on; admin switch still wins (tested in ReservationNotifierTest). */
class UserNotificationPreferencesTest extends TestCase
{
    use RefreshDatabase;

    private function prefs(): UserNotificationPreferences
    {
        return app(UserNotificationPreferences::class);
    }

    private function member(): User
    {
        return User::create([
            'name' => 'Clen', 'email' => 'clen@example.test',
            'password' => 'password123', 'role' => UserRole::MEMBER, 'isActive' => true,
        ]);
    }

    public function test_everything_configurable_is_on_by_default(): void
    {
        $all = $this->prefs()->all($this->member());

        $this->assertSame(
            ['reservation_approval_requested' => true, 'reservation_decided' => true],
            $all,
        );
    }

    public function test_non_configurable_notifications_are_always_wanted(): void
    {
        $user = $this->member();
        $user->notificationPrefs = ['password_reset' => false];
        $user->save();

        $this->assertTrue($this->prefs()->wants($user, MailNotification::PASSWORD_RESET));
    }

    public function test_partial_update_persists_and_keeps_the_rest(): void
    {
        $user = $this->member();

        $state = $this->prefs()->update($user, ['reservation_decided' => false]);

        $this->assertFalse($state['reservation_decided']);
        $this->assertTrue($state['reservation_approval_requested']);
        $this->assertFalse($this->prefs()->wants($user->refresh(), MailNotification::RESERVATION_DECIDED));
        $this->assertTrue($this->prefs()->wants($user, MailNotification::RESERVATION_APPROVAL_REQUESTED));
    }

    public function test_unknown_keys_are_ignored(): void
    {
        $user = $this->member();

        $state = $this->prefs()->update($user, ['vymyslene' => false, 'password_reset' => false]);

        $this->assertArrayNotHasKey('vymyslene', $state);
        $this->assertArrayNotHasKey('password_reset', $state);
    }

    public function test_a_change_is_audited_on_the_user(): void
    {
        $user = $this->member();

        $this->prefs()->update($user, ['reservation_decided' => false]);

        $this->assertTrue(
            AuditLog::query()
                ->where('entityType', AuditEntityType::USER->value)
                ->where('entityId', $user->id)
                ->exists(),
        );
    }
}
```

- [ ] **Step 2: Run it to see it fail**

Run: `vendor/bin/phpunit tests/Feature/UserNotificationPreferencesTest.php`
Expected: error — class `UserNotificationPreferences` not found.

- [ ] **Step 3: Implement `UserNotificationPreferences` and `sendToUser`**

`app/Services/UserNotificationPreferences.php`:

```php
<?php

namespace App\Services;

use App\Domain\Enums\AuditEntityType;
use App\Domain\Enums\MailNotification;
use App\Models\User;

/**
 * A member's own e-mail switches (REZ-062), stored as JSON in
 * `users.notificationPrefs`. Only user-configurable notifications
 * ({@see MailNotification::isUserConfigurable}) are exposed; a missing key
 * reads as ON, mirroring MailNotificationSettings for the admin switches.
 *
 * The admin switch is consulted separately in NotificationMailer — a user
 * preference can only ever turn an e-mail OFF, never back on.
 */
class UserNotificationPreferences
{
    public function __construct(private readonly AuditLogger $audit) {}

    /** @return array<string, bool> keyed by MailNotification value, configurable types only */
    public function all(User $user): array
    {
        $stored = is_array($user->notificationPrefs) ? $user->notificationPrefs : [];

        $result = [];
        foreach (self::configurable() as $type) {
            $result[$type->value] = !array_key_exists($type->value, $stored)
                || (bool) $stored[$type->value];
        }

        return $result;
    }

    public function wants(User $user, MailNotification $type): bool
    {
        if (!$type->isUserConfigurable()) {
            return true;
        }

        return $this->all($user)[$type->value];
    }

    /**
     * Partial update — keys absent from $changes keep their value, keys that
     * are not user-configurable are ignored (the controller rejects them up
     * front with a validation error).
     *
     * @param  array<string, bool>  $changes
     * @return array<string, bool>  the full state after the write
     */
    public function update(User $user, array $changes): array
    {
        $before = $this->all($user);
        $next = $before;
        foreach ($changes as $key => $enabled) {
            if (array_key_exists($key, $next)) {
                $next[$key] = (bool) $enabled;
            }
        }

        $user->notificationPrefs = $next;
        $user->save();

        $this->audit->logUpdate(
            AuditEntityType::USER,
            $user,
            "Upravené e-mailové notifikácie používateľa „{$user->name}“",
            ['notificationPrefs' => $before],
            ['notificationPrefs' => $next],
        );

        return $next;
    }

    /** @return list<MailNotification> */
    public static function configurable(): array
    {
        return array_values(array_filter(
            MailNotification::cases(),
            fn (MailNotification $t) => $t->isUserConfigurable(),
        ));
    }
}
```

`app/Services/NotificationMailer.php` — replace the constructor and add `sendToUser` (keep `send()` as is):

```php
use App\Models\User;
// …
    public function __construct(
        private readonly MailNotificationSettings $settings,
        private readonly UserNotificationPreferences $preferences,
    ) {}

    /**
     * Like {@see send()}, but for a recipient who has an account: also
     * honours their own preference for notifications they may switch off
     * (REZ-062). Both switches are checked here so no caller has to remember
     * the second one.
     */
    public function sendToUser(MailNotification $type, User $user, Mailable $mail): bool
    {
        if (!$this->preferences->wants($user, $type)) {
            Log::info("E-mail „{$type->label()}“ preskočený (vypnutý používateľom) — príjemca {$user->email}");

            return false;
        }

        return $this->send($type, $user->email, $mail);
    }
```

Also update the class docblock's first paragraph to mention: "`send()` checks the admin switch; `sendToUser()` additionally checks the recipient's own preference."

- [ ] **Step 4: Run the preferences test — PASS; run the whole suite — still green**

Run: `vendor/bin/phpunit tests/Feature/UserNotificationPreferencesTest.php` → PASS (5).
Run: `vendor/bin/phpunit` → green (the mailer constructor change is resolved by the container everywhere).

- [ ] **Step 5: Write the failing notifier test**

`tests/Feature/ReservationNotifierTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Domain\Enums\MailNotification;
use App\Domain\Enums\ReservationStatus;
use App\Domain\Enums\ResourceType;
use App\Domain\Enums\UserRole;
use App\Mail\ReservationApprovalRequestedMail;
use App\Mail\ReservationDecidedMail;
use App\Models\Reservation;
use App\Models\Resource;
use App\Models\User;
use App\Services\MailNotificationSettings;
use App\Services\ReservationNotifier;
use App\Services\UserNotificationPreferences;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/** REZ-053 and REZ-057: who gets which approval e-mail, and which switches silence it. */
class ReservationNotifierTest extends TestCase
{
    use RefreshDatabase;

    private Resource $space;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        config(['mail.admin_address' => 'admins@example.test', 'app.url' => 'https://rez.example.test']);
        $this->space = Resource::create([
            'identifier' => 'S-1', 'type' => ResourceType::BOATHOUSE_SPACE,
            'name' => 'Klubovňa', 'requiresApproval' => true,
        ]);
    }

    private function user(string $email, bool $active = true, UserRole $role = UserRole::MEMBER): User
    {
        return User::create([
            'name' => 'U '.$email, 'email' => $email, 'password' => 'password123',
            'role' => $role, 'isActive' => $active,
        ]);
    }

    private function pending(?User $creator = null, ReservationStatus $status = ReservationStatus::PENDING_APPROVAL): Reservation
    {
        return Reservation::create([
            'resourceId' => $this->space->id,
            'createdById' => $creator?->id,
            'customerName' => 'Žiadateľ',
            'customerContact' => 'ziadatel@example.test',
            'startsAt' => '2027-06-01 09:00:00',
            'endsAt' => '2027-06-01 12:00:00',
            'note' => 'Oslava',
            'status' => $status,
        ]);
    }

    private function notifier(): ReservationNotifier
    {
        return app(ReservationNotifier::class);
    }

    public function test_each_active_approver_gets_their_own_mail(): void
    {
        $a = $this->user('a@example.test');
        $b = $this->user('b@example.test');
        $inactive = $this->user('off@example.test', active: false);
        $this->space->approvers()->attach([$a->id, $b->id, $inactive->id]);

        $this->notifier()->approvalRequested($this->pending());

        Mail::assertSent(ReservationApprovalRequestedMail::class, 2);
        Mail::assertSent(ReservationApprovalRequestedMail::class, fn ($m) => $m->hasTo('a@example.test') && !$m->hasTo('b@example.test'));
        Mail::assertSent(ReservationApprovalRequestedMail::class, fn ($m) => $m->hasTo('b@example.test') && !$m->hasTo('a@example.test'));
        Mail::assertNotSent(ReservationApprovalRequestedMail::class, fn ($m) => $m->hasTo('off@example.test'));
    }

    public function test_without_approvers_the_club_address_is_notified(): void
    {
        $this->notifier()->approvalRequested($this->pending());

        Mail::assertSent(ReservationApprovalRequestedMail::class, 1);
        Mail::assertSent(ReservationApprovalRequestedMail::class, fn ($m) => $m->hasTo('admins@example.test'));
    }

    public function test_the_mail_carries_the_booking_details_and_the_approvals_link(): void
    {
        $a = $this->user('a@example.test');
        $this->space->approvers()->attach($a->id);

        $this->notifier()->approvalRequested($this->pending());

        Mail::assertSent(ReservationApprovalRequestedMail::class, function (ReservationApprovalRequestedMail $m) {
            return $m->resourceLabel === 'S-1 – Klubovňa'
                && $m->customerName === 'Žiadateľ'
                && $m->note === 'Oslava'
                && $m->approvalsUrl === 'https://rez.example.test/approvals'
                && str_contains($m->range, '2027-06-01 09:00');
        });
    }

    public function test_an_approver_who_switched_the_mail_off_is_skipped(): void
    {
        $a = $this->user('a@example.test');
        $b = $this->user('b@example.test');
        $this->space->approvers()->attach([$a->id, $b->id]);
        app(UserNotificationPreferences::class)->update($a, ['reservation_approval_requested' => false]);

        $this->notifier()->approvalRequested($this->pending());

        Mail::assertSent(ReservationApprovalRequestedMail::class, 1);
        Mail::assertSent(ReservationApprovalRequestedMail::class, fn ($m) => $m->hasTo('b@example.test'));
    }

    public function test_the_admin_switch_silences_everyone(): void
    {
        $a = $this->user('a@example.test');
        $this->space->approvers()->attach($a->id);
        app(MailNotificationSettings::class)->update([MailNotification::RESERVATION_APPROVAL_REQUESTED->value => false]);

        $this->notifier()->approvalRequested($this->pending());

        Mail::assertNothingSent();
    }

    public function test_decision_goes_to_the_creator(): void
    {
        $creator = $this->user('creator@example.test');
        $reservation = $this->pending($creator, ReservationStatus::CONFIRMED);
        $reservation->decisionNote = 'Kľúče u správcu.';
        $reservation->save();

        $this->notifier()->decided($reservation);

        Mail::assertSent(ReservationDecidedMail::class, function (ReservationDecidedMail $m) {
            return $m->hasTo('creator@example.test')
                && $m->approved === true
                && $m->decisionNote === 'Kľúče u správcu.'
                && $m->reservationsUrl === 'https://rez.example.test/reservations?mine=1';
        });
    }

    public function test_rejection_is_flagged_as_not_approved(): void
    {
        $creator = $this->user('creator@example.test');

        $this->notifier()->decided($this->pending($creator, ReservationStatus::REJECTED));

        Mail::assertSent(ReservationDecidedMail::class, fn (ReservationDecidedMail $m) => $m->approved === false);
    }

    public function test_decision_without_a_creator_sends_nothing(): void
    {
        $this->notifier()->decided($this->pending(null, ReservationStatus::CONFIRMED));

        Mail::assertNothingSent();
    }

    public function test_creator_who_switched_the_mail_off_is_skipped(): void
    {
        $creator = $this->user('creator@example.test');
        app(UserNotificationPreferences::class)->update($creator, ['reservation_decided' => false]);

        $this->notifier()->decided($this->pending($creator, ReservationStatus::CONFIRMED));

        Mail::assertNothingSent();
    }

    public function test_admin_switch_silences_decisions_too(): void
    {
        $creator = $this->user('creator@example.test');
        app(MailNotificationSettings::class)->update([MailNotification::RESERVATION_DECIDED->value => false]);

        $this->notifier()->decided($this->pending($creator, ReservationStatus::CONFIRMED));

        Mail::assertNothingSent();
    }
}
```

- [ ] **Step 6: Run it to see it fail**

Run: `vendor/bin/phpunit tests/Feature/ReservationNotifierTest.php`
Expected: error — class `ReservationNotifier` not found.

- [ ] **Step 7: Implement the mailables and templates**

`app/Mail/ReservationApprovalRequestedMail.php`:

```php
<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

/**
 * Sent to each approver of a resource (or the club address when none is
 * listed) when somebody requests a booking of it (REZ-053).
 */
class ReservationApprovalRequestedMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $resourceLabel,
        public readonly string $range,
        public readonly string $customerName,
        public readonly ?string $customerContact,
        public readonly ?string $note,
        public readonly string $approvalsUrl,
    ) {}

    public function build(): self
    {
        return $this
            ->subject("Rezervácia čaká na schválenie: {$this->resourceLabel} — Lodenica KVŠ")
            ->view('emails.reservation-approval-requested', [
                'resourceLabel' => $this->resourceLabel,
                'range' => $this->range,
                'customerName' => $this->customerName,
                'customerContact' => $this->customerContact,
                'note' => $this->note,
                'approvalsUrl' => $this->approvalsUrl,
            ]);
    }
}
```

`app/Mail/ReservationDecidedMail.php`:

```php
<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

/** Sent to the account that made a booking once an approver decided (REZ-057). */
class ReservationDecidedMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly bool $approved,
        public readonly ?string $name,
        public readonly string $resourceLabel,
        public readonly string $range,
        public readonly ?string $decisionNote,
        public readonly string $reservationsUrl,
    ) {}

    public function build(): self
    {
        $outcome = $this->approved ? 'schválená' : 'zamietnutá';

        return $this
            ->subject("Rezervácia {$outcome}: {$this->resourceLabel} — Lodenica KVŠ")
            ->view('emails.reservation-decided', [
                'approved' => $this->approved,
                'name' => $this->name,
                'resourceLabel' => $this->resourceLabel,
                'range' => $this->range,
                'decisionNote' => $this->decisionNote,
                'reservationsUrl' => $this->reservationsUrl,
            ]);
    }
}
```

`resources/views/emails/reservation-approval-requested.blade.php`:

```blade
@component('emails.layout', ['title' => 'Rezervácia čaká na schválenie'])
    <h1 style="margin:0 0 16px;font-size:20px;color:#0f172a;">Rezervácia čaká na tvoje schválenie</h1>
    <p style="margin:0 0 16px;font-size:15px;line-height:1.6;color:#334155;">
        Niekto požiadal o rezerváciu zdroja, ktorý schvaľuješ. Termín je
        medzitým pre ostatných blokovaný, kým o žiadosti nerozhodneš.
    </p>
    <div style="margin:0 0 20px;padding:14px 16px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;">
        <p style="margin:0;font-size:14px;color:#0f172a;"><strong>{{ $resourceLabel }}</strong></p>
        <p style="margin:4px 0 0;font-size:13px;color:#475569;">{{ $range }}</p>
        <p style="margin:8px 0 0;font-size:13px;color:#475569;">
            Rezervuje: <strong>{{ $customerName }}</strong>@if($customerContact) · {{ $customerContact }}@endif
        </p>
        @if($note)
            <p style="margin:8px 0 0;font-size:13px;color:#475569;">Poznámka: {{ $note }}</p>
        @endif
    </div>
    <p style="margin:0 0 24px;">
        <a href="{{ $approvalsUrl }}" style="display:inline-block;background:#0f172a;color:#ffffff;text-decoration:none;padding:12px 24px;border-radius:8px;font-size:15px;font-weight:600;">
            Rozhodnúť o žiadosti
        </a>
    </p>
    <p style="margin:0;font-size:13px;line-height:1.6;color:#64748b;">
        Schváliť alebo zamietnuť môžeš na stránke „Na schválenie“ alebo
        priamo v detaile rezervácie. Tieto e-maily si vieš vypnúť v profile.
    </p>
@endcomponent
```

`resources/views/emails/reservation-decided.blade.php`:

```blade
@component('emails.layout', ['title' => $approved ? 'Rezervácia schválená' : 'Rezervácia zamietnutá'])
    <h1 style="margin:0 0 16px;font-size:20px;color:#0f172a;">
        {{ $approved ? 'Tvoja rezervácia bola schválená' : 'Tvoja rezervácia bola zamietnutá' }}
    </h1>
    <p style="margin:0 0 16px;font-size:15px;line-height:1.6;color:#334155;">
        Dobrý deň{{ $name ? ', '.$name : '' }},
        @if($approved)
            schvaľovateľ potvrdil tvoju žiadosť. Rezervácia je platná a termín je tvoj.
        @else
            schvaľovateľ tvoju žiadosť zamietol. Termín sa uvoľnil pre ostatných.
        @endif
    </p>
    <div style="margin:0 0 20px;padding:14px 16px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;">
        <p style="margin:0;font-size:14px;color:#0f172a;"><strong>{{ $resourceLabel }}</strong></p>
        <p style="margin:4px 0 0;font-size:13px;color:#475569;">{{ $range }}</p>
        @if($decisionNote)
            <p style="margin:8px 0 0;font-size:13px;color:#475569;">Poznámka schvaľovateľa: {{ $decisionNote }}</p>
        @endif
    </div>
    <p style="margin:0 0 24px;">
        <a href="{{ $reservationsUrl }}" style="display:inline-block;background:#0f172a;color:#ffffff;text-decoration:none;padding:12px 24px;border-radius:8px;font-size:15px;font-weight:600;">
            Moje rezervácie
        </a>
    </p>
    <p style="margin:0;font-size:13px;line-height:1.6;color:#64748b;">
        Tieto e-maily si vieš vypnúť v profile.
    </p>
@endcomponent
```

- [ ] **Step 8: Implement `ReservationNotifier`**

A mailable's `to` list **accumulates** across sends (`Mailable::to()` appends), so one instance per recipient — hence the factory closure.

`app/Services/ReservationNotifier.php`:

```php
<?php

namespace App\Services;

use App\Domain\Enums\MailNotification;
use App\Domain\Enums\ReservationStatus;
use App\Mail\ReservationApprovalRequestedMail;
use App\Mail\ReservationDecidedMail;
use App\Models\Reservation;
use App\Models\User;
use Illuminate\Support\Facades\Log;

/**
 * E-mails around the approval workflow (REZ-053, REZ-057). Same contract as
 * AdminNotifier: failures are logged, never thrown — a broken mail server
 * must not undo the booking or the decision that triggered the notice.
 */
class ReservationNotifier
{
    public function __construct(private readonly NotificationMailer $mailer) {}

    /**
     * Tell every active approver of the resource that a request is waiting;
     * the club address when nobody is listed. One mailable per recipient —
     * a Mailable's `to` list accumulates, so sharing an instance would leak
     * approver A's address into approver B's copy.
     */
    public function approvalRequested(Reservation $reservation): void
    {
        try {
            $resource = $reservation->resource;
            $mail = fn () => new ReservationApprovalRequestedMail(
                resourceLabel: $resource->label(),
                range: $reservation->rangeLabel(),
                customerName: $reservation->customerName,
                customerContact: $reservation->customerContact,
                note: $reservation->note,
                approvalsUrl: rtrim((string) config('app.url'), '/').'/approvals',
            );

            $approvers = $resource->approvers()->where('users.isActive', true)->get();
            if ($approvers->isEmpty()) {
                $to = (string) config('mail.admin_address');
                if ($to !== '') {
                    $this->mailer->send(MailNotification::RESERVATION_APPROVAL_REQUESTED, $to, $mail());
                }

                return;
            }

            foreach ($approvers as $approver) {
                $this->mailer->sendToUser(MailNotification::RESERVATION_APPROVAL_REQUESTED, $approver, $mail());
            }
        } catch (\Throwable $e) {
            Log::warning('Approval-requested notice failed for reservation '.$reservation->id.': '.$e->getMessage());
        }
    }

    /** Tell the account that made the booking how it was decided. */
    public function decided(Reservation $reservation): void
    {
        try {
            $creator = $reservation->creator;
            if (!$creator instanceof User) {
                return;
            }

            $this->mailer->sendToUser(
                MailNotification::RESERVATION_DECIDED,
                $creator,
                new ReservationDecidedMail(
                    approved: $reservation->status === ReservationStatus::CONFIRMED,
                    name: $creator->name,
                    resourceLabel: $reservation->resource->label(),
                    range: $reservation->rangeLabel(),
                    decisionNote: $reservation->decisionNote,
                    reservationsUrl: rtrim((string) config('app.url'), '/').'/reservations?mine=1',
                ),
            );
        } catch (\Throwable $e) {
            Log::warning('Decision notice failed for reservation '.$reservation->id.': '.$e->getMessage());
        }
    }
}
```

- [ ] **Step 9: Run the notifier test and the suite**

Run: `vendor/bin/phpunit tests/Feature/ReservationNotifierTest.php` → PASS (10).
Run: `vendor/bin/phpunit` → green.

- [ ] **Step 10: Commit**

```bash
git add app/Services/UserNotificationPreferences.php app/Services/NotificationMailer.php \
  app/Services/ReservationNotifier.php app/Mail/ReservationApprovalRequestedMail.php \
  app/Mail/ReservationDecidedMail.php resources/views/emails/reservation-approval-requested.blade.php \
  resources/views/emails/reservation-decided.blade.php \
  tests/Feature/UserNotificationPreferencesTest.php tests/Feature/ReservationNotifierTest.php
git commit -m "feat(mail): approval e-mails with per-user switches on top of the admin ones

NotificationMailer::sendToUser checks the admin switch and the recipient's
own preference in one place; ReservationNotifier addresses approvers (or
the club address) and the booking's creator.

Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>"
```

---

### Task 4: `ReservationsService` — pending creation, status lock, blocking overlaps, multi-status list, ICS

Spec §5.2, §5.6. This is where flagged resources start behaving differently.

**Files:**
- Create: `backend-php/app/Exceptions/ApprovalMemberRequiredException.php`, `ReservationStatusLockedException.php`
- Modify: `backend-php/app/Services/ReservationsService.php`
- Modify: `backend-php/app/Http/Requests/UpdateReservationRequest.php`, `ListReservationsRequest.php`
- Modify: `backend-php/app/Http/Resources/ReservationResource.php`, `app/Services/AuditSnapshot.php`
- Modify: `backend-php/app/Http/Controllers/Api/ReservationsController.php` (ICS status)
- Test: `backend-php/tests/Feature/Api/ReservationApprovalApiTest.php` (new), `tests/Feature/Api/ReservationsApiTest.php`, `tests/Feature/Api/ReservationIcsApiTest.php`

**Interfaces:**
- Consumes: `ReservationNotifier::approvalRequested`, `ReservationStatus::blocksSlot/blockingValues`, `Reservation::rangeLabel`, `Resource::label`.
- Produces: `POST /reservations` → 201 `PENDING_APPROVAL` for gated resources; 403 `RESERVATION_APPROVAL_MEMBER_REQUIRED`; 409 `RESERVATION_STATUS_LOCKED`; `GET /reservations?status[]=A&status[]=B`; `ReservationResource` fields `decidedById`, `decidedAt`, `decisionNote`.

- [ ] **Step 1: Write the failing API tests (creation half)**

`tests/Feature/Api/ReservationApprovalApiTest.php`:

```php
<?php

namespace Tests\Feature\Api;

use App\Domain\Enums\ResourceType;
use App\Domain\Enums\UserRole;
use App\Mail\ReservationApprovalRequestedMail;
use App\Models\Reservation;
use App\Models\Resource;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The approval workflow end to end (spec §5.2–5.3, REZ-050…060).
 * "gated" = a resource with requiresApproval = true.
 */
class ReservationApprovalApiTest extends TestCase
{
    use RefreshDatabase;

    private Resource $kayak;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->kayak = Resource::create(['identifier' => 'K-1', 'type' => ResourceType::WW_KAYAK, 'name' => 'Kayak 1']);
    }

    /** @param  User[]  $approvers */
    private function gated(array $approvers = []): Resource
    {
        $r = Resource::create([
            'identifier' => 'S-'.bin2hex(random_bytes(2)), 'type' => ResourceType::BOATHOUSE_SPACE,
            'name' => 'Klubovňa', 'requiresApproval' => true,
        ]);
        if ($approvers !== []) {
            $r->approvers()->attach(array_map(fn (User $u) => $u->id, $approvers));
        }

        return $r;
    }

    private function user(UserRole $role = UserRole::MEMBER): User
    {
        $suffix = bin2hex(random_bytes(3));

        return User::create([
            'name' => "User {$suffix}", 'email' => "u{$suffix}@example.test",
            'password' => 'password123', 'role' => $role, 'isActive' => true,
        ]);
    }

    private function book(Resource $r, string $from = '2027-06-01T09:00:00Z', string $to = '2027-06-01T12:00:00Z'): TestResponse
    {
        return $this->postJson('/api/v1/reservations', [
            'resourceId' => $r->id, 'customerName' => 'Ján', 'startsAt' => $from, 'endsAt' => $to,
        ]);
    }

    /* ────────────── Creation (REZ-051, REZ-052, REZ-053) ────────────── */

    public function test_member_booking_of_a_gated_resource_waits_for_approval(): void
    {
        $member = $this->actingAsMember();
        $space = $this->gated();

        $this->book($space)
            ->assertCreated()
            ->assertJsonPath('status', 'PENDING_APPROVAL')
            ->assertJsonPath('createdById', $member->id)
            ->assertJsonPath('decidedAt', null)
            ->assertJsonPath('decisionNote', null);
    }

    public function test_member_booking_of_a_normal_resource_is_confirmed_as_before(): void
    {
        $this->actingAsMember();

        $this->book($this->kayak)->assertCreated()->assertJsonPath('status', 'CONFIRMED');
        Mail::assertNothingSent();
    }

    public function test_anonymous_cannot_book_a_gated_resource(): void
    {
        $this->book($this->gated())
            ->assertStatus(403)
            ->assertJsonPath('code', 'RESERVATION_APPROVAL_MEMBER_REQUIRED');
        $this->assertSame(0, Reservation::count());
    }

    public function test_pending_account_cannot_book_a_gated_resource(): void
    {
        $this->actingAsPending();

        $this->book($this->gated())->assertStatus(403)->assertJsonPath('code', 'RESERVATION_APPROVAL_MEMBER_REQUIRED');
    }

    public function test_a_pending_request_holds_the_slot(): void
    {
        $this->actingAsMember();
        $space = $this->gated();
        $this->book($space)->assertCreated();

        $this->actingAsMember();
        $this->book($space, '2027-06-01T10:00:00Z', '2027-06-01T11:00:00Z')
            ->assertStatus(409)
            ->assertJsonPath('code', 'RESERVATION_OVERLAP');
    }

    public function test_approvers_are_mailed_when_a_request_is_created(): void
    {
        $approver = $this->user();
        $space = $this->gated([$approver]);
        $this->actingAsMember();

        $this->book($space)->assertCreated();

        Mail::assertSent(ReservationApprovalRequestedMail::class, fn ($m) => $m->hasTo($approver->email));
    }

    /* ────────────── PATCH lock (REZ-058) and cancel (REZ-059, REZ-024) ────────────── */

    public function test_patch_cannot_change_status_of_a_pending_reservation(): void
    {
        $this->actingAsMember();
        $id = $this->book($this->gated())->json('id');

        $this->patchJson("/api/v1/reservations/{$id}", ['status' => 'CONFIRMED'])
            ->assertStatus(409)
            ->assertJsonPath('code', 'RESERVATION_STATUS_LOCKED');
        $this->assertSame('PENDING_APPROVAL', Reservation::find($id)->status->value);
    }

    public function test_patch_of_other_fields_keeps_the_request_pending(): void
    {
        $this->actingAsMember();
        $id = $this->book($this->gated())->json('id');

        $this->patchJson("/api/v1/reservations/{$id}", ['note' => 'Prídeme 20 ľudí', 'endsAt' => '2027-06-01T14:00:00Z'])
            ->assertOk()
            ->assertJsonPath('status', 'PENDING_APPROVAL')
            ->assertJsonPath('note', 'Prídeme 20 ľudí');
    }

    public function test_patch_cannot_confirm_on_a_gated_resource(): void
    {
        $this->actingAsMember();
        $space = $this->gated();
        $id = $this->book($space)->json('id');
        $this->patchJson("/api/v1/reservations/{$id}/cancel")->assertOk()->assertJsonPath('status', 'CANCELLED');

        $this->patchJson("/api/v1/reservations/{$id}", ['status' => 'CONFIRMED'])
            ->assertStatus(409)
            ->assertJsonPath('code', 'RESERVATION_STATUS_LOCKED');
    }

    public function test_patch_status_still_works_on_a_normal_resource(): void
    {
        $this->actingAsMember();
        $id = $this->book($this->kayak)->json('id');

        $this->patchJson("/api/v1/reservations/{$id}", ['status' => 'CANCELLED'])->assertOk()->assertJsonPath('status', 'CANCELLED');
        $this->patchJson("/api/v1/reservations/{$id}", ['status' => 'CONFIRMED'])->assertOk()->assertJsonPath('status', 'CONFIRMED');
    }

    public function test_patch_rejects_the_approval_statuses_as_input(): void
    {
        $this->actingAsMember();
        $id = $this->book($this->kayak)->json('id');

        $this->patchJson("/api/v1/reservations/{$id}", ['status' => 'PENDING_APPROVAL'])->assertStatus(400);
        $this->patchJson("/api/v1/reservations/{$id}", ['status' => 'REJECTED'])->assertStatus(400);
    }

    public function test_cancelling_a_pending_request_frees_the_slot(): void
    {
        $this->actingAsMember();
        $space = $this->gated();
        $id = $this->book($space)->json('id');

        $this->patchJson("/api/v1/reservations/{$id}/cancel")->assertOk()->assertJsonPath('status', 'CANCELLED');
        $this->book($space)->assertCreated();
    }

    public function test_cancelling_a_rejected_reservation_is_a_no_op(): void
    {
        $this->actingAsMember();
        $space = $this->gated();
        $id = $this->book($space)->json('id');
        Reservation::whereKey($id)->update(['status' => 'REJECTED']);

        $this->patchJson("/api/v1/reservations/{$id}/cancel")->assertOk()->assertJsonPath('status', 'REJECTED');
    }

    /* ────────────── Visibility ────────────── */

    public function test_decision_note_is_hidden_from_anonymous_readers(): void
    {
        // Anonymous request FIRST — Sanctum::actingAs cannot be undone within a
        // test, and refreshApplication() would drop the in-memory SQLite DB.
        $space = $this->gated();
        $id = Reservation::create([
            'resourceId' => $space->id, 'customerName' => 'Ján',
            'startsAt' => '2027-06-01 09:00:00', 'endsAt' => '2027-06-01 12:00:00',
            'status' => 'REJECTED', 'decisionNote' => 'Plné.',
        ])->id;

        $this->getJson("/api/v1/reservations/{$id}")->assertOk()->assertJsonPath('decisionNote', null);

        $this->actingAsMember();
        $this->getJson("/api/v1/reservations/{$id}")->assertOk()->assertJsonPath('decisionNote', 'Plné.');
    }
}
```

Add to `tests/Feature/Api/ReservationsApiTest.php`:

```php
    public function test_list_accepts_multiple_statuses(): void
    {
        $member = $this->actingAsMember();
        $space = Resource::create(['identifier' => 'S-1', 'type' => ResourceType::BOATHOUSE_SPACE, 'name' => 'Klubovňa', 'requiresApproval' => true]);
        $this->postJson('/api/v1/reservations', ['resourceId' => $space->id, 'customerName' => 'P', 'startsAt' => '2027-01-01T09:00:00Z', 'endsAt' => '2027-01-01T10:00:00Z'])->assertCreated();
        $confirmed = $this->postJson('/api/v1/reservations', ['resourceId' => $this->kayak->id, 'customerName' => 'C', 'startsAt' => '2027-01-01T09:00:00Z', 'endsAt' => '2027-01-01T10:00:00Z'])->assertCreated()->json('id');
        $this->patchJson("/api/v1/reservations/{$confirmed}/cancel")->assertOk();
        $this->postJson('/api/v1/reservations', ['resourceId' => $this->kayak->id, 'customerName' => 'C2', 'startsAt' => '2027-01-02T09:00:00Z', 'endsAt' => '2027-01-02T10:00:00Z'])->assertCreated();

        $both = $this->getJson('/api/v1/reservations?status[]=CONFIRMED&status[]=PENDING_APPROVAL')->assertOk()->json('items');
        $this->assertSame(['CONFIRMED', 'PENDING_APPROVAL'], collect($both)->pluck('status')->sort()->values()->all());

        $this->getJson('/api/v1/reservations?status=CONFIRMED')->assertOk()->assertJsonPath('total', 1);
        $this->getJson('/api/v1/reservations?status[]=nezmysel')->assertStatus(400);
    }
```

Add to `tests/Feature/Api/ReservationIcsApiTest.php`:

```php
    public function test_ics_marks_a_pending_reservation_tentative(): void
    {
        $this->actingAsMember();
        $space = Resource::create(['identifier' => 'S-ICS', 'type' => ResourceType::BOATHOUSE_SPACE, 'name' => 'Klubovňa', 'requiresApproval' => true]);
        $id = $this->postJson('/api/v1/reservations', [
            'resourceId' => $space->id, 'customerName' => 'P',
            'startsAt' => '2099-08-12T09:00:00Z', 'endsAt' => '2099-08-12T12:00:00Z',
        ])->assertCreated()->json('id');

        $this->assertStringContainsString('STATUS:TENTATIVE', $this->get("/api/v1/reservations/{$id}/ics")->getContent());
    }
```

- [ ] **Step 2: Run them to see them fail**

Run: `vendor/bin/phpunit tests/Feature/Api/ReservationApprovalApiTest.php tests/Feature/Api/ReservationsApiTest.php tests/Feature/Api/ReservationIcsApiTest.php`
Expected: many failures — gated booking returns `CONFIRMED`, anonymous gets 201, etc.

- [ ] **Step 3: Add the exceptions**

`app/Exceptions/ApprovalMemberRequiredException.php`:

```php
<?php

namespace App\Exceptions;

use Symfony\Component\HttpFoundation\Response;

/**
 * REZ-051. 403 for anonymous callers too (not 401): the SPA treats 401 as
 * a lost session and would log a PENDING account out.
 */
class ApprovalMemberRequiredException extends DomainException
{
    public function __construct(string $resourceId)
    {
        parent::__construct(
            errorCode: 'RESERVATION_APPROVAL_MEMBER_REQUIRED',
            message: 'Tento zdroj vyžaduje schválenie. Rezervovať ho môže iba prihlásený člen klubu.',
            details: ['resourceId' => $resourceId],
        );
    }

    public function httpStatus(): int
    {
        return Response::HTTP_FORBIDDEN;
    }
}
```

`app/Exceptions/ReservationStatusLockedException.php`:

```php
<?php

namespace App\Exceptions;

use Symfony\Component\HttpFoundation\Response;

/** REZ-058: the status of a waiting/rejected reservation is not editable through PATCH. */
class ReservationStatusLockedException extends DomainException
{
    public function __construct(string $message)
    {
        parent::__construct(errorCode: 'RESERVATION_STATUS_LOCKED', message: $message);
    }

    public function httpStatus(): int
    {
        return Response::HTTP_CONFLICT;
    }
}
```

- [ ] **Step 4: Rewrite `ReservationsService`**

Replace the whole file with:

```php
<?php

namespace App\Services;

use App\Domain\Enums\AuditAction;
use App\Domain\Enums\AuditEntityType;
use App\Domain\Enums\ReservationStatus;
use App\Domain\ValueObjects\TimeRange;
use App\Exceptions\ApprovalMemberRequiredException;
use App\Exceptions\InactiveResourceException;
use App\Exceptions\NotFoundDomainException;
use App\Exceptions\ReservationOverlapException;
use App\Exceptions\ReservationStatusLockedException;
use App\Models\Reservation;
use App\Models\Resource;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Reservation lifecycle. Overlap is checked at the application layer for
 * clear validation messages; the Postgres EXCLUDE constraint enforces the
 * same rule at the DB level and is the safety net for race conditions.
 *
 * Every reservation is `[startsAt, endsAt)` — a single uniform shape.
 * "All-day" or multi-day reservations are just longer ranges; the model
 * does not distinguish them.
 *
 * Approval (REZ-050…): a resource flagged `requiresApproval` produces a
 * PENDING_APPROVAL reservation that holds its slot until an approver
 * decides — see ReservationApprovalService for the decision itself.
 */
class ReservationsService
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly ReservationNotifier $notifier,
    ) {}

    public function create(array $cmd): Reservation
    {
        $range = TimeRange::fromInstants($cmd['startsAt'], $cmd['endsAt']);

        $resource = Resource::find($cmd['resourceId']);
        if ($resource === null) {
            throw new NotFoundDomainException('Resource', $cmd['resourceId']);
        }
        if (!$resource->isActive) {
            throw new InactiveResourceException($resource->id);
        }

        // REZ-051 / REZ-052: a gated resource may only be requested by a
        // confirmed member, and the request waits for an approver.
        $status = ReservationStatus::CONFIRMED;
        if ($resource->requiresApproval) {
            $actor = isset($cmd['createdById']) ? User::find($cmd['createdById']) : null;
            if (!$actor instanceof User || !$actor->isMember()) {
                throw new ApprovalMemberRequiredException($resource->id);
            }
            $status = ReservationStatus::PENDING_APPROVAL;
        }

        $this->assertNoOverlap($cmd['resourceId'], $range);

        $reservation = Reservation::create([
            'resourceId' => $cmd['resourceId'],
            'eventId' => $cmd['eventId'] ?? null,
            'createdById' => $cmd['createdById'] ?? null,
            'memberId' => $cmd['memberId'] ?? null,
            'customerName' => $cmd['customerName'],
            'customerContact' => $cmd['customerContact'] ?? null,
            'startsAt' => $range->startsAt,
            'endsAt' => $range->endsAt,
            'note' => $cmd['note'] ?? null,
            'status' => $status,
        ]);

        $pending = $status === ReservationStatus::PENDING_APPROVAL;
        $this->audit->logCreate(
            AuditEntityType::RESERVATION,
            $reservation,
            ($pending ? 'Požiadaná' : 'Pridaná')
                ." rezervácia „{$reservation->customerName}“ pre „{$resource->label()}“ ({$reservation->rangeLabel()})"
                .($pending ? ' — čaká na schválenie' : ''),
            AuditSnapshot::reservation($reservation),
        );

        if ($pending) {
            $this->notifier->approvalRequested($reservation);
        }

        return $reservation;
    }

    public function update(string $id, array $cmd): Reservation
    {
        $existing = $this->requireExisting($id);
        $before = AuditSnapshot::reservation($existing);

        $newStatus = isset($cmd['status'])
            ? ($cmd['status'] instanceof ReservationStatus
                ? $cmd['status']
                : ReservationStatus::from($cmd['status']))
            : $existing->status;

        $this->assertStatusChangeAllowed($existing, $newStatus);

        $newStartsAt = $existing->startsAt;
        $newEndsAt = $existing->endsAt;
        $rangeChanged = false;

        if (array_key_exists('startsAt', $cmd) || array_key_exists('endsAt', $cmd)) {
            $range = TimeRange::fromInstants(
                $cmd['startsAt'] ?? $existing->startsAt,
                $cmd['endsAt'] ?? $existing->endsAt,
            );
            $newStartsAt = $range->startsAt;
            $newEndsAt = $range->endsAt;
            $rangeChanged = true;

            if ($newStatus->blocksSlot()) {
                $this->assertNoOverlap($existing->resourceId, $range, $id);
            }
        }

        $updates = array_intersect_key($cmd, array_flip([
            'customerName',
            'customerContact',
            'eventId',
            'note',
            'status',
        ]));

        if ($rangeChanged) {
            $updates['startsAt'] = $newStartsAt;
            $updates['endsAt'] = $newEndsAt;
        }

        $existing->fill($updates);

        // Admin reassignment of ownership: set the member ID and point
        // createdById at the matching user (if that member is registered), so
        // "my reservations" follows the new owner both ways. An empty value
        // detaches it.
        if (array_key_exists('memberId', $cmd)) {
            $memberId = is_string($cmd['memberId']) && trim($cmd['memberId']) !== ''
                ? trim($cmd['memberId'])
                : null;
            $existing->memberId = $memberId;
            $existing->createdById = $memberId !== null
                ? User::query()->where('memberId', $memberId)->value('id')
                : null;
        }

        $existing->save();
        $existing->refresh();

        $this->audit->logUpdate(
            AuditEntityType::RESERVATION,
            $existing,
            "Upravená rezervácia „{$existing->customerName}“ ({$existing->rangeLabel()})",
            $before,
            AuditSnapshot::reservation($existing),
        );

        return $existing;
    }

    /**
     * REZ-059 (and REZ-024): only a slot-blocking reservation has anything
     * to cancel. Cancelling an already cancelled or rejected one changes
     * nothing and writes no audit row.
     */
    public function cancel(string $id): Reservation
    {
        $existing = $this->requireExisting($id);
        $previous = $existing->status;
        if (!$previous->blocksSlot()) {
            return $existing;
        }

        $existing->status = ReservationStatus::CANCELLED;
        $existing->save();
        $existing->refresh();

        $this->audit->logAction(
            AuditEntityType::RESERVATION,
            $existing->id,
            AuditAction::CANCEL,
            "Zrušená rezervácia „{$existing->customerName}“ ({$existing->rangeLabel()})",
            ['before' => ['status' => $previous->value], 'after' => ['status' => ReservationStatus::CANCELLED->value]],
        );

        return $existing;
    }

    public function remove(string $id): void
    {
        $existing = $this->requireExisting($id);
        $snapshot = AuditSnapshot::reservation($existing);
        $summary = "Zmazaná rezervácia „{$existing->customerName}“ ({$existing->rangeLabel()})";
        $existing->delete();

        $this->audit->logDelete(
            AuditEntityType::RESERVATION,
            $existing,
            $summary,
            $snapshot,
        );
    }

    public function findById(string $id): Reservation
    {
        return $this->requireExisting($id);
    }

    public function list(array $options): array
    {
        $query = Reservation::query();

        if (!empty($options['resourceId'])) {
            $query->where('resourceId', $options['resourceId']);
        }
        if (!empty($options['eventId'])) {
            $query->where('eventId', $options['eventId']);
        }
        if (!empty($options['createdById'])) {
            $query->where('createdById', $options['createdById']);
        }
        // "My reservations": everything I created OR everything tagged with
        // my internal member ID (so history follows the member identity even
        // if the ID is later bound to a different account). `mine` says the
        // filter was asked for; the two IDs say who "I" am. An anonymous
        // caller asking for `mine` has no identity, so the seed `1 = 0`
        // makes that case match nothing rather than everything.
        if (!empty($options['mine']) || !empty($options['mineUserId'])) {
            $mineUserId = $options['mineUserId'] ?? null;
            $mineMemberId = $options['mineMemberId'] ?? null;
            $query->where(function ($q) use ($mineUserId, $mineMemberId) {
                $q->whereRaw('1 = 0');
                if ($mineUserId !== null && $mineUserId !== '') {
                    $q->orWhere('createdById', $mineUserId);
                }
                if ($mineMemberId !== null && $mineMemberId !== '') {
                    $q->orWhere('memberId', $mineMemberId);
                }
            });
        }
        // One status or several (REZ-061) — the schedule views ask for
        // "confirmed + waiting" in one request.
        if (!empty($options['status'])) {
            $statuses = is_array($options['status']) ? $options['status'] : [$options['status']];
            $query->whereIn('status', array_map(
                fn ($s) => $s instanceof ReservationStatus ? $s->value : ReservationStatus::from($s)->value,
                $statuses,
            ));
        }
        if (!empty($options['range'])) {
            /** @var TimeRange $range */
            $range = $options['range'];
            $query->where('startsAt', '<', $range->endsAt)
                  ->where('endsAt', '>', $range->startsAt);
        }
        // Independent half-open bounds (used when caller only knows
        // one side, e.g. "future only" → startsAtFrom=now, no upper).
        if (!empty($options['startsAtFrom'])) {
            $query->where('endsAt', '>=', self::toInstant($options['startsAtFrom']));
        }
        if (!empty($options['endsAtTo'])) {
            $query->where('startsAt', '<', self::toInstant($options['endsAtTo']));
        }
        if (!empty($options['search'])) {
            $needle = '%'.strtolower($options['search']).'%';
            // Match against the reservation's own free-text fields AND
            // the joined resource — so typing "K-007" or "Pyranha" in the
            // box finds every booking for that boat, not just bookings
            // where the customer happened to type the identifier into
            // the note field.
            $query->where(function ($q) use ($needle) {
                $q->whereRaw('LOWER("customerName") LIKE ?', [$needle])
                  ->orWhereRaw('LOWER(COALESCE("customerContact", \'\')) LIKE ?', [$needle])
                  ->orWhereRaw('LOWER(COALESCE("note", \'\')) LIKE ?', [$needle])
                  ->orWhereHas('resource', function ($rq) use ($needle) {
                      $rq->whereRaw('LOWER("identifier") LIKE ?', [$needle])
                         ->orWhereRaw('LOWER("name") LIKE ?', [$needle])
                         ->orWhereRaw('LOWER(COALESCE("model", \'\')) LIKE ?', [$needle]);
                  });
            });
        }

        $total = (clone $query)->count();

        // "My reservations" wants newest-first; the schedule views want
        // chronological. Default stays chronological.
        if (!empty($options['orderByLatest'])) {
            $query->orderByDesc('startsAt')->orderByDesc('createdAt');
        } else {
            $query->orderBy('startsAt')->orderBy('createdAt');
        }

        $items = $query
            ->skip($options['skip'] ?? 0)
            ->take($options['take'] ?? 25)
            ->get();

        return ['items' => $items, 'total' => $total];
    }

    /**
     * Bounds reach us as ISO-8601 strings from the API. Bind them as a
     * DateTime so the driver renders them in the column's own storage
     * format — a raw "2027-08-01T10:00:00Z" would be compared
     * lexically against "2027-08-01 10:00:00" on SQLite and silently
     * drop matching rows.
     */
    private static function toInstant(\DateTimeInterface|string $value): \DateTimeInterface
    {
        return $value instanceof \DateTimeInterface
            ? $value
            : new \DateTimeImmutable($value);
    }

    /**
     * Slot-blocking reservations (CONFIRMED + PENDING_APPROVAL, REZ-010)
     * that intersect the range.
     *
     * @return \Illuminate\Support\Collection<int, Reservation>
     */
    public function findOverlapping(string $resourceId, TimeRange $range, ?string $excludeId = null)
    {
        return Reservation::query()
            ->where('resourceId', $resourceId)
            ->whereIn('status', ReservationStatus::blockingValues())
            ->where('startsAt', '<', $range->endsAt)
            ->where('endsAt', '>', $range->startsAt)
            ->when($excludeId, fn (Builder $q, $id) => $q->where('id', '!=', $id))
            ->get();
    }

    /**
     * REZ-058. A waiting or rejected reservation changes status only through
     * approve / reject / cancel, and on a resource that requires approval the
     * only door into CONFIRMED is an approver's decision — otherwise a member
     * could PATCH their own request straight past the approver.
     */
    private function assertStatusChangeAllowed(Reservation $existing, ReservationStatus $newStatus): void
    {
        if ($newStatus === $existing->status) {
            return;
        }
        if (in_array($existing->status, [ReservationStatus::PENDING_APPROVAL, ReservationStatus::REJECTED], true)) {
            throw new ReservationStatusLockedException(
                'Stav tejto rezervácie sa dá zmeniť iba schválením, zamietnutím alebo zrušením.',
            );
        }
        if ($newStatus === ReservationStatus::CONFIRMED && $existing->resource?->requiresApproval) {
            throw new ReservationStatusLockedException(
                'Rezerváciu tohto zdroja môže potvrdiť iba schvaľovateľ.',
            );
        }
    }

    private function assertNoOverlap(string $resourceId, TimeRange $range, ?string $excludeId = null): void
    {
        $conflicts = $this->findOverlapping($resourceId, $range, $excludeId);
        if ($conflicts->isNotEmpty()) {
            throw new ReservationOverlapException(
                $resourceId,
                $conflicts->pluck('id')->all(),
            );
        }
    }

    private function requireExisting(string $id): Reservation
    {
        $reservation = Reservation::find($id);
        if ($reservation === null) {
            throw new NotFoundDomainException('Reservation', $id);
        }

        return $reservation;
    }
}
```

- [ ] **Step 5: Requests, resource, snapshot, ICS**

`app/Http/Requests/UpdateReservationRequest.php` — replace the `status` rule and imports:

```php
use Illuminate\Validation\Rule;
// remove: use Illuminate\Validation\Rules\Enum;
// …
            // Only the two "ordinary" statuses can be set by hand. The approval
            // statuses are entered through approve/reject (REZ-058).
            'status' => ['sometimes', Rule::in([
                ReservationStatus::CONFIRMED->value,
                ReservationStatus::CANCELLED->value,
            ])],
```

`app/Http/Requests/ListReservationsRequest.php` — replace the `status` rule and add `prepareForValidation`:

```php
            // One value (`?status=CONFIRMED`) or several (`?status[]=…&status[]=…`),
            // normalised to an array below. REZ-061.
            'status' => ['nullable', 'array'],
            'status.*' => [new Enum(ReservationStatus::class)],
// …
    public function prepareForValidation(): void
    {
        if ($this->has('status') && !is_array($this->input('status'))) {
            $raw = $this->input('status');
            $this->merge(['status' => ($raw === null || $raw === '') ? null : [$raw]]);
        }
    }
```

`app/Http/Resources/ReservationResource.php` — add after `'status'`:

```php
            // Approval record (REZ-055). The approver's note may explain a
            // refusal in personal terms, so it follows the member-only rule.
            'decidedById' => $this->decidedById,
            'decidedAt' => $this->decidedAt?->toIso8601String(),
            'decisionNote' => $isMember ? $this->decisionNote : null,
```

`app/Services/AuditSnapshot.php` — in `reservation()` add `'decisionNote' => $r->decisionNote,` after `'status'`.

`app/Http/Controllers/Api/ReservationsController.php` — in `ics()` replace the `STATUS:` line:

```php
            // REZ-063: a waiting request is tentative in the user's calendar.
            'STATUS:'.match ($reservation->status) {
                \App\Domain\Enums\ReservationStatus::CONFIRMED => 'CONFIRMED',
                \App\Domain\Enums\ReservationStatus::PENDING_APPROVAL => 'TENTATIVE',
                default => 'CANCELLED',
            },
```

- [ ] **Step 6: Run the tests — PASS; run the suite — green**

Run: `vendor/bin/phpunit tests/Feature/Api/ReservationApprovalApiTest.php tests/Feature/Api/ReservationsApiTest.php tests/Feature/Api/ReservationIcsApiTest.php` → PASS.
Run: `vendor/bin/phpunit` → green. If `ReservationsServiceTest` or `AuditIntegrationTest` compare audit summaries or `before.status` literally, update them to the new wording/`$previous->value`.

- [ ] **Step 7: Commit**

```bash
git add app/Exceptions/ApprovalMemberRequiredException.php app/Exceptions/ReservationStatusLockedException.php \
  app/Services/ReservationsService.php app/Http/Requests/UpdateReservationRequest.php \
  app/Http/Requests/ListReservationsRequest.php app/Http/Resources/ReservationResource.php \
  app/Services/AuditSnapshot.php app/Http/Controllers/Api/ReservationsController.php \
  tests/Feature/Api/ReservationApprovalApiTest.php tests/Feature/Api/ReservationsApiTest.php \
  tests/Feature/Api/ReservationIcsApiTest.php
git commit -m "feat(reservations): gated resources wait for approval and hold their slot

A resource flagged requiresApproval can only be requested by a confirmed
member (403 otherwise); the request is created PENDING_APPROVAL, blocks
the slot, mails the approvers, and its status is locked against PATCH.
List filter accepts several statuses; ICS marks pending as TENTATIVE.

Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>"
```

---

### Task 5: `ReservationApprovalService` + approve / reject / approvals endpoints

Spec §5.3, §5.7.

**Files:**
- Create: `backend-php/app/Exceptions/ReservationNotPendingException.php`
- Create: `backend-php/app/Services/ReservationApprovalService.php`
- Create: `backend-php/app/Http/Requests/DecideReservationRequest.php`
- Create: `backend-php/app/Http/Controllers/Api/ReservationApprovalsController.php`
- Modify: `backend-php/routes/api.php`
- Test: `backend-php/tests/Feature/Api/ReservationApprovalApiTest.php` (append)

**Interfaces:**
- Consumes: `ReservationNotifier::decided`, `Resource::isApprover`, `AuditAction::APPROVE/REJECT`, `Reservation::rangeLabel`, `Resource::label`.
- Produces: `ReservationApprovalService::approve(string $id, User $actor, ?string $note = null): Reservation`, `::reject(...)`, `::canDecide(User, Reservation): bool`, `::pendingFor(User, int $skip = 0, int $take = 50): array{items, total}`, `::pendingCountFor(User): int`; routes `GET /reservations/approvals`, `POST /reservations/{id}/approve`, `POST /reservations/{id}/reject` (body `{ note?: string }`); error `RESERVATION_NOT_PENDING` (409).

- [ ] **Step 1: Append the failing decision tests**

Add to `tests/Feature/Api/ReservationApprovalApiTest.php` (imports `App\Mail\ReservationDecidedMail`, `App\Models\AuditLog`, `App\Domain\Enums\AuditAction` at the top):

```php
    /* ────────────── Decisions (REZ-054 … REZ-057) ────────────── */

    /** @return array{0: Resource, 1: User, 2: string} gated resource, its approver, id of a pending request by a member */
    private function pendingRequest(): array
    {
        $approver = $this->user();
        $space = $this->gated([$approver]);
        $this->actingAsMember();
        $id = $this->book($space)->assertCreated()->json('id');
        Mail::fake(); // drop the approval-requested mail so decision assertions are clean

        return [$space, $approver, $id];
    }

    public function test_approver_approves(): void
    {
        [, $approver, $id] = $this->pendingRequest();
        Sanctum::actingAs($approver, ['*']);

        $this->postJson("/api/v1/reservations/{$id}/approve", ['note' => 'Kľúče u správcu.'])
            ->assertOk()
            ->assertJsonPath('status', 'CONFIRMED')
            ->assertJsonPath('decidedById', $approver->id)
            ->assertJsonPath('decisionNote', 'Kľúče u správcu.');
        $this->assertNotNull(Reservation::find($id)->decidedAt);

        Mail::assertSent(ReservationDecidedMail::class, fn (ReservationDecidedMail $m) => $m->approved === true);
        $this->assertTrue(AuditLog::query()->where('entityId', $id)->where('action', AuditAction::APPROVE->value)->exists());
    }

    public function test_approver_rejects_and_frees_the_slot(): void
    {
        [$space, $approver, $id] = $this->pendingRequest();
        Sanctum::actingAs($approver, ['*']);

        $this->postJson("/api/v1/reservations/{$id}/reject", ['note' => 'Plné.'])
            ->assertOk()
            ->assertJsonPath('status', 'REJECTED')
            ->assertJsonPath('decisionNote', 'Plné.');
        Mail::assertSent(ReservationDecidedMail::class, fn (ReservationDecidedMail $m) => $m->approved === false);
        $this->assertTrue(AuditLog::query()->where('entityId', $id)->where('action', AuditAction::REJECT->value)->exists());

        // The slot is free again for another member.
        $this->actingAsMember();
        $this->book($space)->assertCreated();
    }

    public function test_note_is_optional_and_blank_becomes_null(): void
    {
        [, $approver, $id] = $this->pendingRequest();
        Sanctum::actingAs($approver, ['*']);

        $this->postJson("/api/v1/reservations/{$id}/approve", ['note' => '   '])
            ->assertOk()
            ->assertJsonPath('decisionNote', null);
    }

    public function test_admin_can_decide_without_being_listed(): void
    {
        [, , $id] = $this->pendingRequest();
        $this->actingAsAdmin();

        $this->postJson("/api/v1/reservations/{$id}/approve")->assertOk()->assertJsonPath('status', 'CONFIRMED');
    }

    public function test_member_outside_the_approver_list_cannot_decide(): void
    {
        [, , $id] = $this->pendingRequest();
        $this->actingAsMember();

        $this->postJson("/api/v1/reservations/{$id}/approve")->assertStatus(403)->assertJsonPath('code', 'FORBIDDEN');
        $this->postJson("/api/v1/reservations/{$id}/reject")->assertStatus(403);
        $this->assertSame('PENDING_APPROVAL', Reservation::find($id)->status->value);
    }

    public function test_anonymous_and_pending_accounts_cannot_decide(): void
    {
        // Anonymous request FIRST (see test_decision_note_is_hidden_from_anonymous_readers).
        $space = $this->gated();
        $id = Reservation::create([
            'resourceId' => $space->id, 'customerName' => 'Ján',
            'startsAt' => '2027-06-01 09:00:00', 'endsAt' => '2027-06-01 12:00:00',
            'status' => 'PENDING_APPROVAL',
        ])->id;

        $this->postJson("/api/v1/reservations/{$id}/approve")->assertStatus(401);

        $this->actingAsPending();
        $this->postJson("/api/v1/reservations/{$id}/approve")->assertStatus(403);
    }

    public function test_second_decision_is_rejected_with_409(): void
    {
        [, $approver, $id] = $this->pendingRequest();
        Sanctum::actingAs($approver, ['*']);
        $this->postJson("/api/v1/reservations/{$id}/approve")->assertOk();

        $this->postJson("/api/v1/reservations/{$id}/reject")
            ->assertStatus(409)
            ->assertJsonPath('code', 'RESERVATION_NOT_PENDING');
        $this->assertSame('CONFIRMED', Reservation::find($id)->status->value);
    }

    public function test_deciding_a_confirmed_normal_reservation_is_409(): void
    {
        $this->actingAsAdmin();
        $id = $this->book($this->kayak)->assertCreated()->json('id');

        $this->postJson("/api/v1/reservations/{$id}/approve")->assertStatus(409)->assertJsonPath('code', 'RESERVATION_NOT_PENDING');
    }

    public function test_deciding_an_unknown_reservation_is_404(): void
    {
        $this->actingAsAdmin();

        $this->postJson('/api/v1/reservations/00000000-0000-0000-0000-000000000000/approve')->assertStatus(404);
    }

    /* ────────────── Approvals list (REZ-060) ────────────── */

    public function test_approvals_list_is_scoped_to_the_caller(): void
    {
        // Anonymous request FIRST (see test_decision_note_is_hidden_from_anonymous_readers).
        $this->getJson('/api/v1/reservations/approvals')->assertStatus(401);

        $approverA = $this->user();
        $approverB = $this->user();
        $spaceA = $this->gated([$approverA]);
        $spaceB = $this->gated([$approverB]);
        $this->actingAsMember();
        $idA = $this->book($spaceA)->assertCreated()->json('id');
        $idB = $this->book($spaceB)->assertCreated()->json('id');
        $this->book($this->kayak)->assertCreated(); // confirmed, never listed

        Sanctum::actingAs($approverA, ['*']);
        $mine = $this->getJson('/api/v1/reservations/approvals')->assertOk();
        $this->assertSame([$idA], collect($mine->json('items'))->pluck('id')->all());
        $mine->assertJsonPath('total', 1);

        $this->actingAsAdmin();
        $all = $this->getJson('/api/v1/reservations/approvals')->assertOk();
        $this->assertEqualsCanonicalizing([$idA, $idB], collect($all->json('items'))->pluck('id')->all());

        $this->actingAsMember();
        $this->getJson('/api/v1/reservations/approvals')->assertOk()->assertJsonPath('total', 0);

        $this->actingAsPending();
        $this->getJson('/api/v1/reservations/approvals')->assertStatus(403);
    }

    public function test_approvals_list_drops_a_request_once_decided(): void
    {
        [, $approver, $id] = $this->pendingRequest();
        Sanctum::actingAs($approver, ['*']);
        $this->getJson('/api/v1/reservations/approvals')->assertOk()->assertJsonPath('total', 1);

        $this->postJson("/api/v1/reservations/{$id}/reject")->assertOk();

        $this->getJson('/api/v1/reservations/approvals')->assertOk()->assertJsonPath('total', 0);
    }
```

- [ ] **Step 2: Run to see them fail**

Run: `vendor/bin/phpunit tests/Feature/Api/ReservationApprovalApiTest.php`
Expected: the new tests fail with 404 (routes do not exist yet); the Task 4 tests still pass.

- [ ] **Step 3: Exception, request, service**

`app/Exceptions/ReservationNotPendingException.php`:

```php
<?php

namespace App\Exceptions;

use Symfony\Component\HttpFoundation\Response;

/** REZ-056: a decision is final; there is nothing left to approve or reject. */
class ReservationNotPendingException extends DomainException
{
    public function __construct(string $reservationId)
    {
        parent::__construct(
            errorCode: 'RESERVATION_NOT_PENDING',
            message: 'O tejto rezervácii už bolo rozhodnuté.',
            details: ['reservationId' => $reservationId],
        );
    }

    public function httpStatus(): int
    {
        return Response::HTTP_CONFLICT;
    }
}
```

`app/Http/Requests/DecideReservationRequest.php`:

```php
<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** Body of POST /reservations/{id}/approve and …/reject. */
class DecideReservationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'note' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
```

`app/Services/ReservationApprovalService.php`:

```php
<?php

namespace App\Services;

use App\Domain\Enums\AuditAction;
use App\Domain\Enums\AuditEntityType;
use App\Domain\Enums\ReservationStatus;
use App\Exceptions\ForbiddenException;
use App\Exceptions\NotFoundDomainException;
use App\Exceptions\ReservationNotPendingException;
use App\Models\Reservation;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Who may decide a waiting reservation and what a decision does (REZ-054 …
 * REZ-057, REZ-060). Kept apart from ReservationsService so that class stays
 * about lifecycle and overlaps.
 */
class ReservationApprovalService
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly ReservationNotifier $notifier,
    ) {}

    public function approve(string $id, User $actor, ?string $note = null): Reservation
    {
        return $this->decide($id, $actor, $note, ReservationStatus::CONFIRMED);
    }

    public function reject(string $id, User $actor, ?string $note = null): Reservation
    {
        return $this->decide($id, $actor, $note, ReservationStatus::REJECTED);
    }

    /** REZ-054: admins always; members only when listed on the resource. */
    public function canDecide(User $user, Reservation $reservation): bool
    {
        if ($user->isAdmin()) {
            return true;
        }
        if (!$user->isMember()) {
            return false;
        }

        return $reservation->resource->isApprover($user);
    }

    /**
     * Waiting reservations the user may decide, oldest slot first. Past
     * requests stay listed until decided or cancelled.
     *
     * @return array{items: \Illuminate\Support\Collection<int, Reservation>, total: int}
     */
    public function pendingFor(User $user, int $skip = 0, int $take = 50): array
    {
        $query = $this->pendingQuery($user);
        $total = (clone $query)->count();
        $items = $query->orderBy('startsAt')->orderBy('createdAt')->skip($skip)->take($take)->get();

        return ['items' => $items, 'total' => $total];
    }

    public function pendingCountFor(User $user): int
    {
        return $this->pendingQuery($user)->count();
    }

    private function pendingQuery(User $user): Builder
    {
        $query = Reservation::query()->where('status', ReservationStatus::PENDING_APPROVAL->value);
        if (!$user->isAdmin()) {
            $query->whereHas('resource.approvers', fn (Builder $q) => $q->whereKey($user->id));
        }

        return $query;
    }

    private function decide(string $id, User $actor, ?string $note, ReservationStatus $to): Reservation
    {
        $reservation = Reservation::find($id);
        if ($reservation === null) {
            throw new NotFoundDomainException('Reservation', $id);
        }
        // Permission before state, so an outsider learns nothing about it.
        if (!$this->canDecide($actor, $reservation)) {
            throw new ForbiddenException('Túto rezerváciu nemôžeš schvaľovať.');
        }

        $note = is_string($note) && trim($note) !== '' ? trim($note) : null;

        // REZ-056: conditional write. Two approvers clicking at once both pass
        // the checks above; only the first row-level update finds the row
        // still pending, the other gets 0 rows and a 409.
        $affected = Reservation::query()
            ->whereKey($id)
            ->where('status', ReservationStatus::PENDING_APPROVAL->value)
            ->update([
                'status' => $to->value,
                'decidedById' => $actor->id,
                'decidedAt' => now(),
                'decisionNote' => $note,
            ]);
        if ($affected === 0) {
            throw new ReservationNotPendingException($id);
        }

        $reservation->refresh();

        $approved = $to === ReservationStatus::CONFIRMED;
        $this->audit->logAction(
            AuditEntityType::RESERVATION,
            $reservation->id,
            $approved ? AuditAction::APPROVE : AuditAction::REJECT,
            ($approved ? 'Schválená' : 'Zamietnutá')
                ." rezervácia „{$reservation->customerName}“ pre „{$reservation->resource->label()}“ ({$reservation->rangeLabel()})",
            [
                'before' => ['status' => ReservationStatus::PENDING_APPROVAL->value],
                'after' => ['status' => $to->value, 'decisionNote' => $note],
            ],
        );

        $this->notifier->decided($reservation);

        return $reservation;
    }
}
```

- [ ] **Step 4: Controller and routes**

`app/Http/Controllers/Api/ReservationApprovalsController.php`:

```php
<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\DecideReservationRequest;
use App\Http\Resources\ReservationResource;
use App\Http\Support\Paginated;
use App\Services\ReservationApprovalService;
use Illuminate\Http\Request;

/**
 * The approver's side of the workflow. Routes sit behind `auth:sanctum` +
 * `member`; whether THIS member may decide THIS reservation is checked in
 * the service (REZ-054). See docs/AUTH-AND-PERMISSIONS.md.
 */
class ReservationApprovalsController extends Controller
{
    public function __construct(private readonly ReservationApprovalService $approvals) {}

    /** GET /api/v1/reservations/approvals — waiting requests the caller may decide (REZ-060). */
    public function index(Request $request): array
    {
        $page = max(1, (int) $request->query('page', '1'));
        $pageSize = min(200, max(1, (int) $request->query('pageSize', '50')));

        $result = $this->approvals->pendingFor($request->user(), ($page - 1) * $pageSize, $pageSize);

        return Paginated::from($result['items'], $result['total'], $page, $pageSize, ReservationResource::class);
    }

    /** POST /api/v1/reservations/{id}/approve */
    public function approve(DecideReservationRequest $request, string $id): ReservationResource
    {
        return new ReservationResource(
            $this->approvals->approve($id, $request->user(), $request->validated('note')),
        );
    }

    /** POST /api/v1/reservations/{id}/reject */
    public function reject(DecideReservationRequest $request, string $id): ReservationResource
    {
        return new ReservationResource(
            $this->approvals->reject($id, $request->user(), $request->validated('note')),
        );
    }
}
```

`routes/api.php` — add the import `use App\Http\Controllers\Api\ReservationApprovalsController;`, then **directly after** the `reservations/mine` route (it must precede `reservations/{id}`):

```php
// `approvals` likewise MUST precede `{id}`. Confirmed members only; whether
// the caller may decide a given reservation is enforced in the service.
Route::get('reservations/approvals', [ReservationApprovalsController::class, 'index'])
    ->middleware(['auth:sanctum', 'member']);
```

and inside the `['auth:sanctum', 'member']` group, after the `reservations/{id}/cancel` line:

```php
    // Approval workflow (REZ-054…): approvers and admins decide waiting requests.
    Route::post('reservations/{id}/approve', [ReservationApprovalsController::class, 'approve']);
    Route::post('reservations/{id}/reject', [ReservationApprovalsController::class, 'reject']);
```

- [ ] **Step 5: Run the tests — PASS; run the suite — green**

Run: `vendor/bin/phpunit tests/Feature/Api/ReservationApprovalApiTest.php` → PASS (all).
Run: `vendor/bin/phpunit` → green.

- [ ] **Step 6: Commit**

```bash
git add app/Exceptions/ReservationNotPendingException.php app/Services/ReservationApprovalService.php \
  app/Http/Requests/DecideReservationRequest.php app/Http/Controllers/Api/ReservationApprovalsController.php \
  routes/api.php tests/Feature/Api/ReservationApprovalApiTest.php
git commit -m "feat(reservations): approve / reject endpoints and the approvals list

Admins and listed approvers decide a waiting request; the transition is a
conditional UPDATE so a second decision gets 409. Rejection frees the
slot; both outcomes are audited and mailed to the booking's creator.

Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>"
```

---

### Task 6: Resources — `requiresApproval` + approvers through the API; blocking statuses on the dashboard

Spec §5.5, §5.6 (AvailabilityService).

**Files:**
- Modify: `backend-php/app/Http/Requests/CreateResourceRequest.php`, `UpdateResourceRequest.php`
- Modify: `backend-php/app/Services/ResourcesService.php`, `app/Services/AuditSnapshot.php`, `app/Http/Resources/ResourceResource.php`, `app/Http/Controllers/Api/ResourcesController.php`
- Modify: `backend-php/app/Services/AvailabilityService.php`
- Test: `backend-php/tests/Feature/Api/ResourcesApiTest.php`, `tests/Feature/Api/HealthAndDashboardTest.php`

**Interfaces:**
- Produces: `POST/PATCH /resources` accept `requiresApproval: bool`, `approverIds: string[]`; `ResourceResource` fields `requiresApproval` (public) and `approvers: [{id, name}] | null` (members); dashboard payload treats `PENDING_APPROVAL` as occupied and `renderResource` carries `requiresApproval`.

- [ ] **Step 1: Write the failing tests**

Append to `tests/Feature/Api/ResourcesApiTest.php` (add `use App\Domain\Enums\UserRole; use App\Models\AuditLog; use App\Models\User;`):

```php
    private function memberUser(string $email): User
    {
        return User::create(['name' => 'M '.$email, 'email' => $email, 'password' => 'password123', 'role' => UserRole::MEMBER, 'isActive' => true]);
    }

    public function test_admin_sets_requires_approval_and_approvers(): void
    {
        $this->actingAsAdmin();
        $a = $this->memberUser('a@example.test');
        $b = $this->memberUser('b@example.test');

        $created = $this->postJson('/api/v1/resources', [
            'identifier' => 'S-1', 'type' => 'BOATHOUSE_SPACE', 'name' => 'Klubovňa',
            'requiresApproval' => true, 'approverIds' => [$a->id, $b->id],
        ])->assertCreated()->assertJsonPath('requiresApproval', true);
        $this->assertEqualsCanonicalizing([$a->id, $b->id], collect($created->json('approvers'))->pluck('id')->all());
        $this->assertSame('M a@example.test', collect($created->json('approvers'))->firstWhere('id', $a->id)['name']);

        $id = $created->json('id');
        $updated = $this->patchJson("/api/v1/resources/{$id}", ['approverIds' => [$b->id]])->assertOk();
        $this->assertSame([$b->id], collect($updated->json('approvers'))->pluck('id')->all());

        // Approver changes land in the audit log (REZ-050).
        $audit = AuditLog::query()->where('entityId', $id)->where('action', 'UPDATE')->latest('createdAt')->first();
        $this->assertNotNull($audit);
        $this->assertSame([$b->id], $audit->changes['after']['approverIds']);
    }

    public function test_a_patch_without_approver_ids_leaves_the_list_alone(): void
    {
        $this->actingAsAdmin();
        $a = $this->memberUser('a@example.test');
        $id = $this->postJson('/api/v1/resources', [
            'identifier' => 'S-2', 'type' => 'BOATHOUSE_SPACE', 'name' => 'Sklad',
            'requiresApproval' => true, 'approverIds' => [$a->id],
        ])->assertCreated()->json('id');

        $r = $this->patchJson("/api/v1/resources/{$id}", ['name' => 'Sklad 2'])->assertOk();

        $this->assertSame([$a->id], collect($r->json('approvers'))->pluck('id')->all());
    }

    public function test_approver_must_be_a_confirmed_member(): void
    {
        $this->actingAsAdmin();
        $pending = User::create(['name' => 'P', 'email' => 'p@example.test', 'password' => 'password123', 'role' => UserRole::PENDING, 'isActive' => true]);

        $this->postJson('/api/v1/resources', [
            'identifier' => 'S-3', 'type' => 'BOATHOUSE_SPACE', 'name' => 'X',
            'requiresApproval' => true, 'approverIds' => [$pending->id],
        ])->assertStatus(400)->assertJsonPath('code', 'VALIDATION_ERROR');

        $this->postJson('/api/v1/resources', [
            'identifier' => 'S-4', 'type' => 'BOATHOUSE_SPACE', 'name' => 'Y',
            'approverIds' => ['00000000-0000-0000-0000-000000000000'],
        ])->assertStatus(400);
    }

    public function test_approvers_are_visible_to_members_only(): void
    {
        $a = $this->memberUser('a@example.test');
        $r = Resource::create(['identifier' => 'S-5', 'type' => ResourceType::BOATHOUSE_SPACE, 'name' => 'Z', 'requiresApproval' => true]);
        $r->approvers()->attach($a->id);

        $this->getJson("/api/v1/resources/{$r->id}")->assertOk()
            ->assertJsonPath('requiresApproval', true)
            ->assertJsonPath('approvers', null);

        $this->actingAsMember();
        $this->getJson("/api/v1/resources/{$r->id}")->assertOk()->assertJsonPath('approvers.0.id', $a->id);
        $this->assertSame($a->id, $this->getJson('/api/v1/resources')->json('items.0.approvers.0.id'));
    }
```

Append to `tests/Feature/Api/HealthAndDashboardTest.php` (add imports for `ReservationStatus`, `ResourceType`, `Reservation`, `Resource`, `CarbonImmutable` as needed):

```php
    public function test_dashboard_counts_a_pending_request_as_occupied(): void
    {
        $space = Resource::create(['identifier' => 'S-1', 'type' => ResourceType::CANOE, 'name' => 'Gated canoe', 'requiresApproval' => true]);
        $now = \Carbon\CarbonImmutable::now();
        Reservation::create([
            'resourceId' => $space->id, 'customerName' => 'P',
            'startsAt' => $now->startOfDay()->addHours(9), 'endsAt' => $now->startOfDay()->addHours(12),
            'status' => ReservationStatus::PENDING_APPROVAL,
        ]);

        $r = $this->getJson('/api/v1/availability/dashboard')->assertOk();

        $this->assertContains($space->id, collect($r->json('occupiedToday'))->pluck('resourceId')->all());
        $this->assertSame('PENDING_APPROVAL', collect($r->json('occupiedToday'))->firstWhere('resourceId', $space->id)['status']);
        $this->assertNotContains($space->id, collect($r->json('available'))->pluck('id')->all());
        $this->assertTrue(collect($r->json('occupiedToday'))->firstWhere('resourceId', $space->id)['resource']['requiresApproval']);
    }
```

- [ ] **Step 2: Run to see them fail**

Run: `vendor/bin/phpunit tests/Feature/Api/ResourcesApiTest.php tests/Feature/Api/HealthAndDashboardTest.php`
Expected: FAIL — `requiresApproval` missing from the payload, approver list absent, dashboard does not list the pending row.

- [ ] **Step 3: Requests and service**

In both `CreateResourceRequest::rules()` and `UpdateResourceRequest::rules()` add:

```php
            // Approval workflow (REZ-050). Membership of the approvers is
            // checked in ResourcesService — `exists` only proves the account.
            'requiresApproval' => ['sometimes', 'boolean'],
            'approverIds' => ['sometimes', 'array'],
            'approverIds.*' => ['uuid', Rule::exists('users', 'id')],
```

(`CreateResourceRequest` already imports `Rule`; make it `'nullable'` instead of `'sometimes'` there for `requiresApproval` to match its neighbours — both are fine.)

`app/Services/ResourcesService.php` — add `use App\Models\User; use Illuminate\Validation\ValidationException;` and change `create`/`update`/`findById`/`list`:

```php
    public function create(array $input): Resource
    {
        $approverIds = $this->takeApproverIds($input);
        $resource = Resource::create($input);
        if ($approverIds !== null) {
            $this->syncApprovers($resource, $approverIds);
        }
        $resource->load('approvers');

        $this->audit->logCreate(
            AuditEntityType::RESOURCE,
            $resource,
            "Pridaná {$this->kind($resource)} „{$resource->identifier} – {$resource->name}“",
            AuditSnapshot::resource($resource),
        );

        return $resource;
    }

    public function update(string $id, array $input): Resource
    {
        $resource = $this->requireExisting($id);
        $before = AuditSnapshot::resource($resource);

        $approverIds = $this->takeApproverIds($input);
        $resource->fill($input);
        $resource->save();
        if ($approverIds !== null) {
            $this->syncApprovers($resource, $approverIds);
        }
        $resource->refresh()->load('approvers');

        $this->audit->logUpdate(
            AuditEntityType::RESOURCE,
            $resource,
            "Upravená {$this->kind($resource)} „{$resource->identifier}“",
            $before,
            AuditSnapshot::resource($resource),
        );

        return $resource;
    }

    public function findById(string $id): Resource
    {
        return $this->requireExisting($id)->load('approvers');
    }
```

In `list()` change `->with('openDamages')` to `->with(['openDamages', 'approvers'])` and extend the comment: "approvers too, so ResourceResource can name them for members without a query per row."

Add the two helpers before `kind()`:

```php
    /**
     * Pull `approverIds` out of the input (it is not a column) — null when the
     * caller did not send the key at all, so a PATCH without it leaves the
     * list untouched.
     *
     * @return list<string>|null
     */
    private function takeApproverIds(array &$input): ?array
    {
        if (!array_key_exists('approverIds', $input)) {
            return null;
        }
        $ids = array_values(array_unique(array_filter((array) $input['approverIds'], 'is_string')));
        unset($input['approverIds']);

        return $ids;
    }

    /**
     * REZ-050: only confirmed members (or admins) may approve. `exists` in the
     * request already proved the accounts exist; this proves their role.
     *
     * @param  list<string>  $ids
     */
    private function syncApprovers(Resource $resource, array $ids): void
    {
        if ($ids !== []) {
            $eligible = User::query()->whereIn('id', $ids)->get()->filter(fn (User $u) => $u->isMember());
            if ($eligible->count() !== count($ids)) {
                throw ValidationException::withMessages([
                    'approverIds' => 'Schvaľovateľ musí byť potvrdený člen.',
                ]);
            }
        }
        $resource->approvers()->sync($ids);
    }
```

- [ ] **Step 4: Snapshot, resource, controller, availability**

`app/Services/AuditSnapshot.php` — in `resource()` add after `'isActive'`:

```php
            'requiresApproval' => (bool) $r->requiresApproval,
            // Queried, not read from a loaded relation: the "before" snapshot
            // is taken right before a sync and must see the current pivot rows.
            'approverIds' => $r->approvers()->pluck('users.id')->sort()->values()->all(),
```

`app/Http/Resources/ResourceResource.php` — add `use App\Models\User;` and at the top of `toArray()`:

```php
        // Approver names are member names → member-only (CORE-030). Public
        // route, so ask the sanctum guard explicitly (CORE-031).
        $viewer = $request->user('sanctum') ?? $request->user();
        $isMember = $viewer instanceof User && $viewer->isMember();
```

and in the returned array after `'isActive'`:

```php
            // Approval workflow (REZ-050). The flag is public so the booking
            // form can explain what will happen; who approves is for members.
            'requiresApproval' => (bool) $this->requiresApproval,
            'approvers' => $isMember
                ? $this->approvers->map(fn (User $u) => ['id' => $u->id, 'name' => $u->name])->values()->all()
                : null,
```

`app/Http/Controllers/Api/ResourcesController.php` — no logic change needed: `store` gets a loaded resource from the service; `activate`/`deactivate`/`addPhoto` return resources whose `approvers` lazy-load on access (one query, acceptable).

`app/Services/AvailabilityService.php` — in `reservationsActiveDuring()` and `spaceReservationsBetween()` replace `->where('status', ReservationStatus::CONFIRMED->value)` with `->whereIn('status', ReservationStatus::blockingValues())` (REZ-052: a waiting request occupies the boat). In `renderReservation()` add after `'status'`:

```php
            'decidedById' => $r->decidedById,
            'decidedAt' => $r->decidedAt?->toIso8601String(),
            'decisionNote' => $isMember ? $r->decisionNote : null,
```

and in `renderResource()` after `'isActive'`: `'requiresApproval' => (bool) $r->requiresApproval,`.

- [ ] **Step 5: Run the tests — PASS; suite — green**

Run: `vendor/bin/phpunit tests/Feature/Api/ResourcesApiTest.php tests/Feature/Api/HealthAndDashboardTest.php` → PASS.
Run: `vendor/bin/phpunit` → green.

- [ ] **Step 6: Commit**

```bash
git add app/Http/Requests/CreateResourceRequest.php app/Http/Requests/UpdateResourceRequest.php \
  app/Services/ResourcesService.php app/Services/AuditSnapshot.php app/Http/Resources/ResourceResource.php \
  app/Services/AvailabilityService.php tests/Feature/Api/ResourcesApiTest.php tests/Feature/Api/HealthAndDashboardTest.php
git commit -m "feat(resources): admin flags a resource for approval and picks approvers

approverIds syncs a pivot (members/admins only), changes are audited, the
flag is public while approver names are member-only. The dashboard now
treats a waiting request as occupying the boat.

Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>"
```

---

### Task 7: Event attach carries the actor

Spec §5.8. Without this, attaching a gated resource to an event would be refused (no creator = not a member).

**Files:**
- Modify: `backend-php/app/Services/EventsService.php`, `app/Http/Controllers/Api/EventsController.php`
- Test: `backend-php/tests/Feature/Api/EventsApiTest.php`

**Interfaces:**
- Produces: `EventsService::attachResources(string $eventId, array $resourceIds, ?User $actor = null): array`.

- [ ] **Step 1: Write the failing test**

Append to `tests/Feature/Api/EventsApiTest.php` (imports: `App\Models\Resource`, `App\Domain\Enums\ResourceType`):

```php
    public function test_attaching_resources_stamps_the_actor_and_respects_approval(): void
    {
        $member = $this->actingAsMember();
        $kayak = Resource::create(['identifier' => 'K-A', 'type' => ResourceType::WW_KAYAK, 'name' => 'A']);
        $gated = Resource::create(['identifier' => 'S-A', 'type' => ResourceType::BOATHOUSE_SPACE, 'name' => 'Klubovňa', 'requiresApproval' => true]);
        $eventId = $this->postJson('/api/v1/events', [
            'title' => 'Splav', 'startsAt' => '2027-07-01T08:00:00Z', 'endsAt' => '2027-07-01T18:00:00Z',
        ])->assertCreated()->json('id');

        $r = $this->postJson("/api/v1/events/{$eventId}/reservations", ['resourceIds' => [$kayak->id, $gated->id]])->assertCreated();

        $byResource = collect($r->json())->keyBy('resourceId');
        $this->assertSame('CONFIRMED', $byResource[$kayak->id]['status']);
        $this->assertSame('PENDING_APPROVAL', $byResource[$gated->id]['status']);
        $this->assertSame($member->id, $byResource[$kayak->id]['createdById']);
        $this->assertSame($member->id, $byResource[$gated->id]['createdById']);
    }
```

- [ ] **Step 2: Run to see it fail**

Run: `vendor/bin/phpunit tests/Feature/Api/EventsApiTest.php`
Expected: FAIL — 403 `RESERVATION_APPROVAL_MEMBER_REQUIRED` (no creator on the gated one).

- [ ] **Step 3: Thread the actor through**

`app/Services/EventsService.php` — add `use App\Models\User;` and change `attachResources`:

```php
    /**
     * Book the given resources for the event's whole window. The acting member
     * is stamped as creator so a resource that requires approval can be
     * attached at all (REZ-051) — it then waits like any other request.
     */
    public function attachResources(string $eventId, array $resourceIds, ?User $actor = null): array
    {
        $event = $this->requireExisting($eventId);
        $created = [];
        foreach ($resourceIds as $resourceId) {
            $created[] = $this->reservations->create([
                'resourceId' => $resourceId,
                'eventId' => $eventId,
                'customerName' => $event->title,
                'startsAt' => $event->startsAt,
                'endsAt' => $event->endsAt,
                'createdById' => $actor?->id,
                'memberId' => $actor?->memberId,
            ]);
        }
        // … the audit block stays exactly as it is …
```

`app/Http/Controllers/Api/EventsController.php`:

```php
    public function attachResources(AttachResourcesRequest $request, string $id): JsonResponse
    {
        $reservations = $this->events->attachResources(
            $id,
            $request->validated('resourceIds'),
            $request->user(),
        );
```

- [ ] **Step 4: Run — PASS; suite — green; commit**

Run: `vendor/bin/phpunit tests/Feature/Api/EventsApiTest.php` → PASS. Run: `vendor/bin/phpunit` → green.

```bash
git add app/Services/EventsService.php app/Http/Controllers/Api/EventsController.php tests/Feature/Api/EventsApiTest.php
git commit -m "feat(events): attached boats carry the member who attached them

Needed so a resource that requires approval can be attached to an event
(the request then waits like a direct booking); also gives event
reservations a creator for \"my reservations\".

Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>"
```

---

### Task 8: Frontend contract, labels, API clients and the status pill

Spec §6.1, §6.2 (`ReservationStatusPill`).

**Files:**
- Modify: `frontend/src/api/types.ts`, `src/i18n/labels.ts`, `src/api/reservations.api.ts`, `src/api/resources.api.ts`, `src/api/profile.api.ts`
- Create: `frontend/src/components/ui/ReservationStatusPill.vue`
- Test: `frontend/src/components/ui/ReservationStatusPill.spec.ts`

**Interfaces:**
- Produces: `ReservationStatus.PENDING_APPROVAL | REJECTED`; `RESERVATION_BLOCKING_STATUSES: ReservationStatus[]`; `ResourceApprover { id; name }`; `Resource.requiresApproval: boolean`, `Resource.approvers: ResourceApprover[] | null`; `Reservation.decidedById?/decidedAt?/decisionNote?`; `NotificationPreference { key; label; description; enabled }`; `AuditAction` `'APPROVE' | 'REJECT'`; `RESERVATION_STATUS_LABEL`, `AUDIT_ACTION_LABEL`, `NAV_LABELS.approvals`; `reservationsApi.approvals(params)`, `.approve(id, note?)`, `.reject(id, note?)`, `ListReservationsParams.status: ReservationStatus | ReservationStatus[]`; `CreateResourceInput.requiresApproval?`, `.approverIds?`; `profileApi.notifications()`, `.setNotifications(changes)`; component `<ReservationStatusPill :status show-confirmed?>`.

- [ ] **Step 1: Write the failing pill test**

`src/components/ui/ReservationStatusPill.spec.ts`:

```ts
import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';

import ReservationStatusPill from './ReservationStatusPill.vue';

describe('ReservationStatusPill', () => {
  it('names a waiting request and colours it amber', () => {
    const w = mount(ReservationStatusPill, { props: { status: 'PENDING_APPROVAL' } });
    expect(w.text()).toContain('Čaká na schválenie');
    expect(w.html()).toContain('pill-amber');
  });

  it('names a rejection and colours it red', () => {
    const w = mount(ReservationStatusPill, { props: { status: 'REJECTED' } });
    expect(w.text()).toContain('Zamietnutá');
    expect(w.html()).toContain('pill-red');
  });

  it('stays quiet for a confirmed booking unless asked', () => {
    expect(mount(ReservationStatusPill, { props: { status: 'CONFIRMED' } }).text()).toBe('');
    const shown = mount(ReservationStatusPill, { props: { status: 'CONFIRMED', showConfirmed: true } });
    expect(shown.text()).toContain('Potvrdená');
    expect(shown.html()).toContain('pill-green');
  });

  it('marks a cancellation in slate', () => {
    expect(mount(ReservationStatusPill, { props: { status: 'CANCELLED' } }).html()).toContain('pill-slate');
  });
});
```

- [ ] **Step 2: Run to see it fail**

Run: `cd frontend && pnpm test -- ReservationStatusPill`
Expected: FAIL — cannot resolve `./ReservationStatusPill.vue`.

- [ ] **Step 3: Contract + labels**

`src/api/types.ts`:

```ts
export const ReservationStatus = {
  CONFIRMED: 'CONFIRMED',
  /** Booked on a resource that requires approval; waiting for an approver. Holds the slot. */
  PENDING_APPROVAL: 'PENDING_APPROVAL',
  CANCELLED: 'CANCELLED',
  /** An approver turned the request down. Frees the slot like CANCELLED. */
  REJECTED: 'REJECTED',
} as const;
export type ReservationStatus = (typeof ReservationStatus)[keyof typeof ReservationStatus];

/** Statuses that occupy the slot — what schedule views ask the API for. Mirrors ReservationStatus::blocking() on the backend. */
export const RESERVATION_BLOCKING_STATUSES: ReservationStatus[] = [
  ReservationStatus.CONFIRMED,
  ReservationStatus.PENDING_APPROVAL,
];

/** A member who may approve bookings of a resource. Member-only (null for others). */
export interface ResourceApprover {
  id: string;
  name: string;
}
```

In `interface Resource` add after `isActive`:

```ts
  /** Bookings of this resource wait for an approver (REZ-050). Public. */
  requiresApproval: boolean;
  /** Who may approve — confirmed members only see this; null otherwise. */
  approvers: ResourceApprover[] | null;
```

In `interface Reservation` add after `status`:

```ts
  /** Approval record — set once an approver decided. */
  decidedById?: string | null;
  decidedAt?: string | null;
  /** Approver's note. Member-only (null for anonymous/PENDING viewers). */
  decisionNote?: string | null;
```

Extend `AuditAction`: add `| 'APPROVE' | 'REJECT'` after `'CANCEL'`.

Add near `UserIdentity`:

```ts
/** One of the user's own e-mail switches (profile screen). */
export interface NotificationPreference {
  key: string;
  label: string;
  description: string;
  enabled: boolean;
}
```

`src/i18n/labels.ts`:

```ts
export const RESERVATION_STATUS_LABEL: Record<ReservationStatus, string> = {
  CONFIRMED: 'Potvrdená',
  PENDING_APPROVAL: 'Čaká na schválenie',
  CANCELLED: 'Zrušená',
  REJECTED: 'Zamietnutá',
};
```

add `approvals: 'Na schválenie',` to `NAV_LABELS`, and to `AUDIT_ACTION_LABEL` after `CANCEL`: `APPROVE: 'Schválenie',` `REJECT: 'Zamietnutie',`.

- [ ] **Step 4: API clients**

`src/api/reservations.api.ts` — change the `status` param and add three calls:

```ts
  /** One status, or several — the schedule views ask for RESERVATION_BLOCKING_STATUSES. */
  status?: ReservationStatus | ReservationStatus[];
```

```ts
  /** Waiting requests the caller may decide (admins: all; members: their resources). */
  async approvals(params: { page?: number; pageSize?: number } = {}): Promise<Paginated<Reservation>> {
    const { data } = await http.get<Paginated<Reservation>>('/reservations/approvals', { params });
    return data;
  },
  async approve(id: string, note?: string): Promise<Reservation> {
    const { data } = await http.post<Reservation>(`/reservations/${id}/approve`, { note: note ?? null });
    return data;
  },
  async reject(id: string, note?: string): Promise<Reservation> {
    const { data } = await http.post<Reservation>(`/reservations/${id}/reject`, { note: note ?? null });
    return data;
  },
```

`src/api/resources.api.ts` — in `CreateResourceInput` add:

```ts
  /** Bookings wait for an approver (admin-only to set). */
  requiresApproval?: boolean;
  /** User ids of confirmed members who may approve. Omit to leave unchanged. */
  approverIds?: string[];
```

`src/api/profile.api.ts` — import `NotificationPreference` and add:

```ts
  /** The user's own e-mail switches (only the user-configurable notifications). */
  async notifications(): Promise<NotificationPreference[]> {
    const { data } = await http.get<{ notifications: NotificationPreference[] }>('/profile/notifications');
    return data.notifications;
  },
  /** Partial update — only the keys sent change. Returns the full state. */
  async setNotifications(changes: Record<string, boolean>): Promise<NotificationPreference[]> {
    const { data } = await http.patch<{ notifications: NotificationPreference[] }>('/profile/notifications', changes);
    return data.notifications;
  },
```

- [ ] **Step 5: The pill**

`src/components/ui/ReservationStatusPill.vue`:

```vue
<script setup lang="ts">
/**
 * One place for a reservation status' wording and colour. Confirmed is the
 * normal case and stays silent unless `show-confirmed` is set, so lists only
 * light up for the exceptions (waiting, rejected, cancelled).
 */
import { computed } from 'vue';

import { ReservationStatus } from '@/api/types';
import { RESERVATION_STATUS_LABEL } from '@/i18n/labels';

const props = withDefaults(
  defineProps<{ status: ReservationStatus; showConfirmed?: boolean }>(),
  { showConfirmed: false },
);

const PILL_CLASS: Record<ReservationStatus, string> = {
  CONFIRMED: 'pill-green',
  PENDING_APPROVAL: 'pill-amber',
  CANCELLED: 'pill-slate',
  REJECTED: 'pill-red',
};

const ICON: Record<ReservationStatus, string> = {
  CONFIRMED: '✅',
  PENDING_APPROVAL: '⏳',
  CANCELLED: '—',
  REJECTED: '✕',
};

const visible = computed(() => props.status !== ReservationStatus.CONFIRMED || props.showConfirmed);
</script>

<template>
  <span v-if="visible" :class="PILL_CLASS[status]" :title="RESERVATION_STATUS_LABEL[status]">
    <span aria-hidden="true">{{ ICON[status] }}</span>
    {{ RESERVATION_STATUS_LABEL[status] }}
  </span>
</template>
```

- [ ] **Step 6: Run the pill test, typecheck, full test run**

Run: `pnpm test -- ReservationStatusPill` → PASS (4).
Run: `pnpm typecheck` → clean. If a spec fixture typed `: Resource` (not `as Resource`) now lacks `requiresApproval`/`approvers`, add `requiresApproval: false, approvers: null` to that fixture.
Run: `pnpm test` → 39 passed.

- [ ] **Step 7: Commit**

```bash
git add src/api/types.ts src/i18n/labels.ts src/api/reservations.api.ts src/api/resources.api.ts \
  src/api/profile.api.ts src/components/ui/ReservationStatusPill.vue src/components/ui/ReservationStatusPill.spec.ts
git commit -m "feat(frontend): approval statuses, approver fields and a status pill

Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>"
```

---

### Task 9: Schedule views ask for blocking statuses and render waiting requests

Spec §6.3 (Timeline, Calendar, Spaces, AvailabilityHints, EventDetail, ResourceDetail, ReservationsView, Dashboard lists). No approval UI yet — that is Task 10.

**Files:**
- Modify: `frontend/src/views/TimelineView.vue`, `CalendarView.vue`, `SpacesView.vue`, `ResourceDetailView.vue`, `EventDetailView.vue`, `ReservationsView.vue`, `DashboardView.vue`, `src/components/ui/AvailabilityHints.vue`
- Test: `frontend/src/views/ReservationsView.spec.ts`

**Interfaces:**
- Consumes: `RESERVATION_BLOCKING_STATUSES`, `ReservationStatus`, `ReservationStatusPill`.

- [ ] **Step 1: Write the failing list-view test**

Append to `src/views/ReservationsView.spec.ts` inside the existing `describe`:

```ts
  it('asks for confirmed AND waiting reservations by default, everything once "zrušené" is on', async () => {
    const w = await mountView();
    expect(lastQuery().status).toEqual(['CONFIRMED', 'PENDING_APPROVAL']);

    await w.find('input#r-cancelled').setValue(true);
    await flushPromises();
    expect(lastQuery().status).toBeUndefined();
  });

  it('labels a waiting request in the table', async () => {
    listReservations.mockResolvedValue({
      items: [{
        id: 'r-1', resourceId: 'res-1', eventId: null, customerName: 'Peter', customerContact: null,
        createdById: 'u1', startsAt: '2027-06-01T09:00:00+00:00', endsAt: '2027-06-01T12:00:00+00:00',
        note: null, status: 'PENDING_APPROVAL', createdAt: '2027-05-01T00:00:00+00:00', updatedAt: '2027-05-01T00:00:00+00:00',
      }],
      total: 1,
    });
    const w = await mountView();
    expect(w.text()).toContain('Čaká na schválenie');
  });
```

- [ ] **Step 2: Run to see it fail**

Run: `pnpm test -- ReservationsView` → FAIL (`status` is `'CONFIRMED'`, no label).

- [ ] **Step 3: ReservationsView**

Imports: replace `import { ReservationStatus, type Reservation } from '@/api/types';` with `import { RESERVATION_BLOCKING_STATUSES, type Reservation } from '@/api/types';` and add `import ReservationStatusPill from '@/components/ui/ReservationStatusPill.vue';`.

In `load()`: `status: showCancelled.value ? undefined : [...RESERVATION_BLOCKING_STATUSES],`.

Update the header comment lines: `- status (CONFIRMED / PENDING_APPROVAL / CANCELLED / REJECTED, several allowed)` and `- "Zobraziť zrušené a zamietnuté" → drop the default status filter (confirmed + waiting)`.

Template: rename the checkbox label text to `Zobraziť zrušené a zamietnuté`. In the desktop table's first cell:

```vue
              <td class="font-medium">
                <div class="flex flex-wrap items-center gap-2">
                  {{ formatReservationRange(r.startsAt, r.endsAt) }}
                  <ReservationStatusPill :status="r.status" />
                </div>
              </td>
```

In the mobile card, next to the range text (the `<p>`/`<span>` that renders `formatReservationRange(r.startsAt, r.endsAt)`), add `<ReservationStatusPill :status="r.status" class="ml-1" />`.

- [ ] **Step 4: Timeline**

`src/views/TimelineView.vue` — import `RESERVATION_BLOCKING_STATUSES` and `ReservationStatus` from `@/api/types` (keep the existing type imports). In `load()`: `status: [...RESERVATION_BLOCKING_STATUSES],`. In `blockLabel()` prefix waiting requests: change the two `return` lines to

```ts
  const prefix = r.status === ReservationStatus.PENDING_APPROVAL ? '⏳ ' : '';
  if (startsAtMidnight && endsAtMidnight) return prefix + name;
  return `${prefix}${formatTime(r.startsAt)}–${formatTime(r.endsAt)} ${name}`;
```

At the top of `blockColor()`:

```ts
  // A waiting request holds the slot but is not a done deal — dashed amber
  // instead of the booker's solid colour (REZ-061).
  if (r.status === ReservationStatus.PENDING_APPROVAL) {
    return 'bg-amber-50 text-amber-900 ring-amber-400 border border-dashed border-amber-500';
  }
```

- [ ] **Step 5: Calendar, Spaces, AvailabilityHints, EventDetail, ResourceDetail**

`src/views/CalendarView.vue`: import `RESERVATION_BLOCKING_STATUSES` and `ReservationStatusPill`; in `load()` `status: [...RESERVATION_BLOCKING_STATUSES],`. In both month/week `<li>` items put `<span v-if="r.status === 'PENDING_APPROVAL'" aria-hidden="true">⏳</span>` before the `formatTime` span. In the day-detail list add `<ReservationStatusPill :status="r.status" />` inside the `<div class="flex items-center gap-2">` after the resource name span.

`src/views/SpacesView.vue`: import both; `status: [...RESERVATION_BLOCKING_STATUSES],`; in the per-space list change the name line to

```vue
              <p class="flex flex-wrap items-center gap-2 font-medium text-slate-800">
                {{ r.customerName ?? '** rezervácia' }}
                <ReservationStatusPill :status="r.status" />
              </p>
```

`src/components/ui/AvailabilityHints.vue`: import `RESERVATION_BLOCKING_STATUSES`; `status: [...RESERVATION_BLOCKING_STATUSES],` (a waiting request is a busy slot for the next booker).

`src/views/EventDetailView.vue`: import `RESERVATION_BLOCKING_STATUSES`; in `load()` the overlap query uses `status: [...RESERVATION_BLOCKING_STATUSES],`; `attachedResourceIds` filters with `.filter((r) => RESERVATION_BLOCKING_STATUSES.includes(r.status))`. `ReservationStatus` is then unused in this file — remove it from the import (`noUnusedLocals` is on).

`src/views/ResourceDetailView.vue`: import `RESERVATION_BLOCKING_STATUSES` and `ReservationStatusPill`; `reservedNow` and `upcomingReservations` test `RESERVATION_BLOCKING_STATUSES.includes(r.status)` instead of `=== ReservationStatus.CONFIRMED`, and drop `ReservationStatus` from the types import (now unused; `noUnusedLocals` is on). In the "Práve obsadené" banner add `<ReservationStatusPill :status="reservedNow.status" class="ml-2" />` after the range; in the upcoming list change the name `<p>` to

```vue
                  <p class="flex flex-wrap items-center gap-2 font-medium text-slate-800">
                    {{ r.customerName ?? '** rezervácia' }}
                    <ReservationStatusPill :status="r.status" />
                  </p>
```

- [ ] **Step 6: Dashboard lists**

`src/views/DashboardView.vue`: import `ReservationStatusPill`. In *Moje rezervácie* replace the hand-rolled `Zrušená` span with `<ReservationStatusPill :status="r.status" />` and gate the edit button with `v-if="auth.isMember && r.status !== 'CANCELLED' && r.status !== 'REJECTED'"`. In *Dnes obsadené*, *Zajtra obsadené* and *Priestory* add `<ReservationStatusPill :status="r.status" />` at the end of the identifier/name `<div class="flex items-center gap-2">` (for Priestory, after the `<p class="font-medium …">` name).

- [ ] **Step 7: Run tests + typecheck; commit**

Run: `pnpm test` → all pass (41). Run: `pnpm typecheck` → clean.

```bash
git add src/views/TimelineView.vue src/views/CalendarView.vue src/views/SpacesView.vue \
  src/views/ResourceDetailView.vue src/views/EventDetailView.vue src/views/ReservationsView.vue \
  src/views/DashboardView.vue src/components/ui/AvailabilityHints.vue src/views/ReservationsView.spec.ts
git commit -m "feat(frontend): schedule views show waiting requests as occupied

Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>"
```

---

### Task 10: Approvals page, store, nav badge, dashboard banner, decision buttons in the dialog

Spec §6.2, §6.3 (AppShell, Dashboard banner, ReservationEditDialog).

**Files:**
- Create: `frontend/src/stores/approvals.store.ts`, `src/components/ui/ApprovalDecisionButtons.vue`, `src/views/ApprovalsView.vue`
- Modify: `frontend/src/router/index.ts`, `src/components/layout/AppShell.vue`, `src/views/DashboardView.vue`, `src/components/ui/ReservationEditDialog.vue`, `src/views/DashboardView.spec.ts`
- Test: `frontend/src/components/ui/ApprovalDecisionButtons.spec.ts`, `src/views/ApprovalsView.spec.ts`

**Interfaces:**
- Consumes: `reservationsApi.approvals/approve/reject`, `Resource.approvers`, `ReservationStatusPill`.
- Produces: `useApprovalsStore()` → `{ items, total, pendingCount, loading, error, refresh(), clear() }`; `<ApprovalDecisionButtons :reservation @decided(updated)>`; route `/approvals` (name `approvals`, `meta.auth: 'confirmed'`).

- [ ] **Step 1: Write the failing tests**

`src/components/ui/ApprovalDecisionButtons.spec.ts`:

```ts
import { flushPromises, mount } from '@vue/test-utils';
import { createPinia, setActivePinia } from 'pinia';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import type { Reservation } from '@/api/types';

import ApprovalDecisionButtons from './ApprovalDecisionButtons.vue';

const approve = vi.fn();
const reject = vi.fn();
const approvals = vi.fn();

vi.mock('@/api/reservations.api', () => ({
  reservationsApi: {
    approve: (...a: unknown[]) => approve(...a),
    reject: (...a: unknown[]) => reject(...a),
    approvals: (...a: unknown[]) => approvals(...a),
  },
}));

const pending = {
  id: 'r-1', resourceId: 'res-1', status: 'PENDING_APPROVAL', customerName: 'Peter',
  startsAt: '2027-06-01T09:00:00+00:00', endsAt: '2027-06-01T12:00:00+00:00',
} as Reservation;

describe('ApprovalDecisionButtons', () => {
  beforeEach(() => {
    setActivePinia(createPinia());
    approve.mockReset().mockResolvedValue({ ...pending, status: 'CONFIRMED' });
    reject.mockReset().mockResolvedValue({ ...pending, status: 'REJECTED' });
    approvals.mockReset().mockResolvedValue({ items: [], total: 0 });
  });

  it('approves with the trimmed note and reports the updated reservation', async () => {
    const w = mount(ApprovalDecisionButtons, { props: { reservation: pending } });
    await w.find('textarea').setValue('  Kľúče u správcu.  ');
    await w.find('[data-testid="approve"]').trigger('click');
    await flushPromises();

    expect(approve).toHaveBeenCalledWith('r-1', 'Kľúče u správcu.');
    expect(w.emitted('decided')?.[0]?.[0]).toMatchObject({ status: 'CONFIRMED' });
  });

  it('rejects without a note when the field is empty', async () => {
    const w = mount(ApprovalDecisionButtons, { props: { reservation: pending } });
    await w.find('[data-testid="reject"]').trigger('click');
    await flushPromises();

    expect(reject).toHaveBeenCalledWith('r-1', undefined);
    expect(w.emitted('decided')?.[0]?.[0]).toMatchObject({ status: 'REJECTED' });
  });

  it('shows the API error and emits nothing', async () => {
    approve.mockRejectedValue(new Error('O tejto rezervácii už bolo rozhodnuté.'));
    const w = mount(ApprovalDecisionButtons, { props: { reservation: pending } });
    await w.find('[data-testid="approve"]').trigger('click');
    await flushPromises();

    expect(w.text()).toContain('už bolo rozhodnuté');
    expect(w.emitted('decided')).toBeUndefined();
  });
});
```

`src/views/ApprovalsView.spec.ts`:

```ts
import { flushPromises, mount } from '@vue/test-utils';
import { createPinia, setActivePinia } from 'pinia';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { createMemoryHistory, createRouter } from 'vue-router';

import type { Reservation, Resource } from '@/api/types';
import { useAuthStore } from '@/stores/auth.store';

import ApprovalsView from './ApprovalsView.vue';

const approvals = vi.fn();
const approve = vi.fn();
const reject = vi.fn();
const listResources = vi.fn();

vi.mock('@/api/reservations.api', () => ({
  reservationsApi: {
    approvals: (...a: unknown[]) => approvals(...a),
    approve: (...a: unknown[]) => approve(...a),
    reject: (...a: unknown[]) => reject(...a),
  },
}));
vi.mock('@/api/resources.api', () => ({
  resourcesApi: { list: (...a: unknown[]) => listResources(...a) },
}));

const space = { id: 'res-1', identifier: 'S-1', type: 'BOATHOUSE_SPACE', name: 'Klubovňa', isActive: true, requiresApproval: true, approvers: [] } as Resource;
const request = {
  id: 'r-1', resourceId: 'res-1', status: 'PENDING_APPROVAL', customerName: 'Peter', customerContact: 'peter@example.test',
  note: 'Oslava', startsAt: '2027-06-01T09:00:00+00:00', endsAt: '2027-06-01T12:00:00+00:00', createdAt: '2027-05-01T10:00:00+00:00',
} as Reservation;

async function mountView() {
  setActivePinia(createPinia());
  const auth = useAuthStore();
  auth.token = 't';
  auth.user = { id: 'u1', name: 'Approver', email: 'a@example.test', role: 'MEMBER' } as never;
  const router = createRouter({ history: createMemoryHistory(), routes: [{ path: '/approvals', component: ApprovalsView }] });
  await router.push('/approvals');
  await router.isReady();
  const w = mount(ApprovalsView, { global: { plugins: [router] } });
  await flushPromises();
  return w;
}

describe('ApprovalsView', () => {
  beforeEach(() => {
    approvals.mockReset().mockResolvedValue({ items: [request], total: 1 });
    approve.mockReset().mockResolvedValue({ ...request, status: 'CONFIRMED' });
    reject.mockReset();
    listResources.mockReset().mockResolvedValue({ items: [space], total: 1 });
  });

  it('lists the waiting request with resource, booker and note', async () => {
    const w = await mountView();
    expect(w.text()).toContain('S-1');
    expect(w.text()).toContain('Klubovňa');
    expect(w.text()).toContain('Peter');
    expect(w.text()).toContain('Oslava');
  });

  it('approving refreshes the list and empties it', async () => {
    const w = await mountView();
    approvals.mockResolvedValue({ items: [], total: 0 });

    await w.find('[data-testid="approve"]').trigger('click');
    await flushPromises();

    expect(approve).toHaveBeenCalledWith('r-1', undefined);
    expect(w.text()).toContain('Nič nečaká na tvoje schválenie');
  });

  it('shows the empty state when there is nothing to decide', async () => {
    approvals.mockResolvedValue({ items: [], total: 0 });
    const w = await mountView();
    expect(w.text()).toContain('Nič nečaká na tvoje schválenie');
  });
});
```

- [ ] **Step 2: Run to see them fail**

Run: `pnpm test -- Approval` → FAIL (modules not found).

- [ ] **Step 3: Store and buttons**

`src/stores/approvals.store.ts`:

```ts
import { defineStore } from 'pinia';
import { computed, ref } from 'vue';

import { reservationsApi } from '@/api/reservations.api';
import type { Reservation } from '@/api/types';
import { useAuthStore } from '@/stores/auth.store';

/**
 * Waiting requests the current user may decide — drives the "Na schválenie"
 * page, the nav badge and the dashboard banner. Refreshed when membership
 * changes (AppShell), on the dashboard/approvals page, and after every
 * decision (ApprovalDecisionButtons). Non-members never hit the endpoint.
 */
export const useApprovalsStore = defineStore('approvals', () => {
  const items = ref<Reservation[]>([]);
  const total = ref(0);
  const loading = ref(false);
  const error = ref<string | null>(null);

  const pendingCount = computed(() => total.value);

  async function refresh(): Promise<void> {
    const auth = useAuthStore();
    if (!auth.isMember) {
      clear();
      return;
    }
    loading.value = true;
    error.value = null;
    try {
      const data = await reservationsApi.approvals({ pageSize: 100 });
      items.value = data.items;
      total.value = data.total;
    } catch (e) {
      error.value = (e as Error).message;
    } finally {
      loading.value = false;
    }
  }

  function clear(): void {
    items.value = [];
    total.value = 0;
    error.value = null;
  }

  return { items, total, pendingCount, loading, error, refresh, clear };
});
```

`src/components/ui/ApprovalDecisionButtons.vue`:

```vue
<script setup lang="ts">
/**
 * Approve / Reject with an optional note, shared by the approvals page and
 * the reservation dialog. Emits the updated reservation and refreshes the
 * approvals store so badges and lists follow.
 */
import { ref } from 'vue';

import { reservationsApi } from '@/api/reservations.api';
import type { Reservation } from '@/api/types';
import { useApprovalsStore } from '@/stores/approvals.store';

import LoadError from './LoadError.vue';

const props = defineProps<{ reservation: Reservation }>();
const emit = defineEmits<{ (e: 'decided', updated: Reservation): void }>();

const approvals = useApprovalsStore();
const note = ref('');
const busy = ref<'approve' | 'reject' | null>(null);
const error = ref<string | null>(null);

async function decide(kind: 'approve' | 'reject'): Promise<void> {
  error.value = null;
  busy.value = kind;
  try {
    const trimmed = note.value.trim() || undefined;
    const updated =
      kind === 'approve'
        ? await reservationsApi.approve(props.reservation.id, trimmed)
        : await reservationsApi.reject(props.reservation.id, trimmed);
    note.value = '';
    emit('decided', updated);
    void approvals.refresh();
  } catch (e) {
    error.value = (e as Error).message;
  } finally {
    busy.value = null;
  }
}
</script>

<template>
  <div class="grid gap-2">
    <label class="label" :for="`decision-note-${reservation.id}`">
      Poznámka pre rezervujúceho <span class="font-normal text-slate-400">(nepovinná)</span>
    </label>
    <textarea
      :id="`decision-note-${reservation.id}`"
      v-model="note"
      class="input"
      rows="2"
      maxlength="1000"
      placeholder="Napr. dôvod zamietnutia alebo pokyny k prevzatiu…"
    ></textarea>
    <LoadError :message="error" />
    <div class="flex flex-wrap justify-end gap-2">
      <button type="button" class="btn-danger" :disabled="busy !== null" data-testid="reject" @click="decide('reject')">
        {{ busy === 'reject' ? 'Zamietam…' : '✕ Zamietnuť' }}
      </button>
      <button type="button" class="btn-primary" :disabled="busy !== null" data-testid="approve" @click="decide('approve')">
        {{ busy === 'approve' ? 'Schvaľujem…' : '✓ Schváliť' }}
      </button>
    </div>
  </div>
</template>
```

- [ ] **Step 4: The approvals page and its route**

`src/views/ApprovalsView.vue`:

```vue
<script setup lang="ts">
/**
 * "Na schválenie" — waiting requests the signed-in member may decide
 * (REZ-060). Target of the approver e-mail. The store refresh triggered by
 * the decision buttons drops a decided item from the list.
 */
import { onMounted } from 'vue';
import { RouterLink } from 'vue-router';

import ApprovalDecisionButtons from '@/components/ui/ApprovalDecisionButtons.vue';
import EmptyState from '@/components/ui/EmptyState.vue';
import LoadError from '@/components/ui/LoadError.vue';
import PageHeader from '@/components/ui/PageHeader.vue';
import ResourceTypeBadge from '@/components/ui/ResourceTypeBadge.vue';
import Spinner from '@/components/ui/Spinner.vue';
import { useApprovalsStore } from '@/stores/approvals.store';
import { useResourcesStore } from '@/stores/resources.store';
import { formatDateTime, formatReservationRange } from '@/utils/format';

const approvals = useApprovalsStore();
const resources = useResourcesStore();

onMounted(async () => {
  await Promise.all([
    approvals.refresh(),
    resources.items.length ? Promise.resolve() : resources.fetch(),
  ]);
});
</script>

<template>
  <PageHeader
    title="Na schválenie"
    subtitle="Rezervácie, ktoré čakajú na tvoje rozhodnutie. Termín je medzitým pre ostatných blokovaný."
  >
    <template #actions>
      <button class="btn-secondary" type="button" @click="approvals.refresh()">Obnoviť</button>
    </template>
  </PageHeader>

  <LoadError :message="approvals.error" />
  <Spinner v-if="approvals.loading && approvals.items.length === 0" />

  <EmptyState
    v-else-if="approvals.items.length === 0"
    title="Nič nečaká na tvoje schválenie"
    description="Keď niekto požiada o rezerváciu zdroja, ktorý schvaľuješ, objaví sa tu a príde ti e-mail."
  />

  <ul v-else class="grid gap-4">
    <li v-for="r in approvals.items" :key="r.id" class="card-padded grid gap-4 lg:grid-cols-[1fr_20rem]">
      <div>
        <div class="flex flex-wrap items-center gap-2">
          <ResourceTypeBadge
            v-if="resources.byId.get(r.resourceId)"
            :type="resources.byId.get(r.resourceId)!.type"
          />
          <RouterLink
            :to="`/resources/${r.resourceId}`"
            class="font-mono text-sm font-semibold text-slate-900 hover:text-brand-700"
          >
            {{ resources.byId.get(r.resourceId)?.identifier ?? '—' }}
          </RouterLink>
          <span class="text-slate-600">{{ resources.byId.get(r.resourceId)?.name ?? '' }}</span>
        </div>
        <p class="mt-2 text-sm font-medium text-slate-900">
          {{ formatReservationRange(r.startsAt, r.endsAt) }}
        </p>
        <p class="text-sm text-slate-700">
          {{ r.customerName ?? '** rezervácia' }}
          <span v-if="r.customerContact" class="text-slate-500"> · {{ r.customerContact }}</span>
        </p>
        <p v-if="r.note" class="mt-1 text-sm text-slate-600">„{{ r.note }}“</p>
        <p class="mt-1 text-xs text-slate-400">Požiadané {{ formatDateTime(r.createdAt) }}</p>
      </div>
      <ApprovalDecisionButtons :reservation="r" />
    </li>
  </ul>
</template>
```

`src/router/index.ts` — add after the `/reservations/new` route:

```ts
  {
    path: '/approvals',
    name: 'approvals',
    component: () => import('@/views/ApprovalsView.vue'),
    meta: { title: 'Na schválenie', auth: 'confirmed' },
  },
```

- [ ] **Step 5: Nav badge and dashboard banner**

`src/components/layout/AppShell.vue` — script changes:

```ts
import { useApprovalsStore } from '@/stores/approvals.store';
// …
const approvals = useApprovalsStore();

interface NavItem {
  to: string;
  label: string;
  icon: string;
  /** Visibility gate. `undefined` = always visible. */
  requires?: 'member' | 'confirmed' | 'admin';
  /** External URL — rendered as a regular <a target="_blank"> instead of a RouterLink. */
  external?: boolean;
  /** Small count shown at the right edge (e.g. requests waiting for approval). */
  badge?: number;
  /** Hide the entry while `badge` is 0 — for pages only useful when there is work. */
  hideWhenZero?: boolean;
}

function visible(item: NavItem): boolean {
  if (item.hideWhenZero && !(item.badge && item.badge > 0)) return false;
  if (!item.requires) return true;
  if (item.requires === 'member') return auth.isAuthenticated;
  if (item.requires === 'confirmed') return auth.isMember;
  if (item.requires === 'admin') return auth.isAdmin;
  return true;
}
```

In `navItems` insert right after the `/reservations` entry:

```ts
    // Approvers see it while something waits; admins always (they can
    // decide anything and it is where the approver e-mail links).
    {
      to: '/approvals',
      label: NAV_LABELS.approvals,
      icon: '✅',
      requires: 'confirmed',
      badge: approvals.pendingCount,
      hideWhenZero: !auth.isAdmin,
    },
```

After `infoOpen`'s watch add:

```ts
// Keep the approvals count in step with the session: load it when the user
// becomes a confirmed member, drop it on logout.
watch(
  () => auth.isMember,
  (member) => {
    if (member) void approvals.refresh();
    else approvals.clear();
  },
  { immediate: true },
);
```

Template: inside the non-external `<RouterLink>` of the main nav, after `<span>{{ item.label }}</span>`:

```vue
              <span
                v-if="item.badge"
                class="ml-auto rounded-full bg-amber-500 px-1.5 py-0.5 text-[10px] font-bold text-white"
              >{{ item.badge }}</span>
```

`src/views/DashboardView.vue` — script: `import { useApprovalsStore } from '@/stores/approvals.store';`, `const approvals = useApprovalsStore();`, in `onMounted` add `if (auth.isMember) void approvals.refresh();`, and a helper:

```ts
/** Slovak plural for "rezervácia": 1 rezervácia, 2–4 rezervácie, 5+ rezervácií. */
function reservationsWord(n: number): string {
  if (n === 1) return 'rezervácia';
  if (n >= 2 && n <= 4) return 'rezervácie';
  return 'rezervácií';
}
```

Template — directly after the PENDING banner `</div>`:

```vue
  <!-- Approver's to-do: waiting requests this user may decide (REZ-060). -->
  <RouterLink
    v-if="approvals.pendingCount > 0"
    to="/approvals"
    class="mb-5 flex items-center gap-3 rounded-2xl border border-amber-200 bg-amber-50/60 p-4 transition hover:bg-amber-100/60"
  >
    <span class="text-2xl" aria-hidden="true">⏳</span>
    <div class="flex-1">
      <p class="font-semibold text-amber-900">
        Na tvoje schválenie čaká {{ approvals.pendingCount }} {{ reservationsWord(approvals.pendingCount) }}
      </p>
      <p class="text-sm text-amber-800">Otvor zoznam a rozhodni — termín je medzitým blokovaný.</p>
    </div>
    <span class="btn-primary text-xs">Rozhodnúť →</span>
  </RouterLink>
```

`src/views/DashboardView.spec.ts` — the mocked `reservationsApi` must gain `approvals`: add `const approvalsList = vi.fn();`, `approvals: (...a: unknown[]) => approvalsList(...a),` inside the mock object, and `approvalsList.mockReset().mockResolvedValue({ items: [], total: 0 });` in `beforeEach`.

- [ ] **Step 6: Decision UI in the reservation dialog**

`src/components/ui/ReservationEditDialog.vue` — script additions:

```ts
import { ReservationStatus, type AuditLog, type Reservation } from '@/api/types';
import { useResourcesStore } from '@/stores/resources.store';

import ApprovalDecisionButtons from './ApprovalDecisionButtons.vue';
import ReservationStatusPill from './ReservationStatusPill.vue';
// …
const resources = useResourcesStore();

/** The booked resource, for the approver check. Store may be empty on some screens → fetch once. */
const resource = computed(() =>
  props.reservation ? resources.byId.get(props.reservation.resourceId) ?? null : null,
);
/** REZ-054 mirrored for UX only — the API enforces it. */
const canDecide = computed(
  () => auth.isAdmin || !!resource.value?.approvers?.some((a) => a.id === auth.user?.id),
);
```

(Replace the existing `import type { AuditLog, Reservation } from '@/api/types';` with the line above.) In the `watch(() => props.reservation, …)` body add `if (resources.items.length === 0) void resources.fetch();` next to the `loadMembers()` call.

Template — header: wrap the title so the pill sits beside it:

```vue
        <div>
          <div class="flex flex-wrap items-center gap-2">
            <h3 class="text-lg font-semibold text-slate-900">Upraviť rezerváciu</h3>
            <ReservationStatusPill :status="reservation.status" />
          </div>
          <p v-if="resourceName" class="text-sm text-slate-500">{{ resourceName }}</p>
        </div>
```

Directly before `<form …>`:

```vue
      <!-- Approval workflow: decide here for those who may; explain for the rest. -->
      <div
        v-if="reservation.status === ReservationStatus.PENDING_APPROVAL && canDecide"
        class="mb-4 rounded-lg border border-amber-200 bg-amber-50/60 p-3"
      >
        <p class="mb-2 text-sm font-medium text-amber-900">⏳ Táto rezervácia čaká na tvoje schválenie.</p>
        <ApprovalDecisionButtons :reservation="reservation" @decided="emit('saved', $event)" />
      </div>
      <div
        v-else-if="reservation.status === ReservationStatus.PENDING_APPROVAL"
        class="mb-4 rounded-lg border border-amber-200 bg-amber-50/60 p-3 text-sm text-amber-900"
      >
        ⏳ Čaká na schválenie schvaľovateľom zdroja. Termín je medzitým blokovaný.
      </div>
      <div
        v-else-if="reservation.status === ReservationStatus.REJECTED"
        class="mb-4 rounded-lg border border-red-200 bg-red-50/60 p-3 text-sm text-red-900"
      >
        ✕ Zamietnutá schvaľovateľom<template v-if="reservation.decisionNote">: „{{ reservation.decisionNote }}“</template>.
      </div>
```

- [ ] **Step 7: Run tests + typecheck; commit**

Run: `pnpm test` → all pass (47). Run: `pnpm typecheck` → clean.

```bash
git add src/stores/approvals.store.ts src/components/ui/ApprovalDecisionButtons.vue \
  src/components/ui/ApprovalDecisionButtons.spec.ts src/views/ApprovalsView.vue src/views/ApprovalsView.spec.ts \
  src/router/index.ts src/components/layout/AppShell.vue src/views/DashboardView.vue src/views/DashboardView.spec.ts \
  src/components/ui/ReservationEditDialog.vue
git commit -m "feat(frontend): approvals page, nav badge, dashboard banner and in-dialog decisions

Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>"
```

---

### Task 11: Booking form — approval notice, login gate, request wording

Spec §6.3 (`ReservationFormView`).

**Files:**
- Modify: `frontend/src/views/ReservationFormView.vue`

**Interfaces:**
- Consumes: `Resource.requiresApproval`, `ReservationStatus`, `auth.isMember`, `route.fullPath` (login redirect is already supported by `LoginView`/router via `?redirect=`).

- [ ] **Step 1: Script additions**

Change the types import to `import { ReservationStatus, ResourceType, type Event, type Reservation } from '@/api/types';` and add after `selectedResource`:

```ts
/**
 * Approval workflow (REZ-051/052). A flagged resource can only be REQUESTED,
 * and only by a confirmed member — anonymous and PENDING visitors get a
 * login link instead of the submit button. The API enforces the same rule.
 */
const requiresApproval = computed(() => !!selectedResource.value?.requiresApproval);
const approvalBlocked = computed(() => requiresApproval.value && !auth.isMember);
const createdIsPending = computed(
  () => createdReservation.value?.status === ReservationStatus.PENDING_APPROVAL,
);
```

In `submit()` add as the first guard:

```ts
  if (approvalBlocked.value) {
    error.value = 'Tento zdroj vyžaduje schválenie — rezervovať ho môže iba prihlásený člen.';
    return;
  }
```

- [ ] **Step 2: Mark flagged resources before they are picked**

In the **global search results** button, after `<p class="truncate text-xs text-slate-500">{{ r.name }}</p>` add:

```vue
                <span v-if="r.requiresApproval" class="pill-amber mt-1 inline-flex">🔒 Schvaľuje sa</span>
```

In the **step-2 grid** button, after the `<DamageBadge v-if="r.openDamage" …/>` line add the same `<span v-if="r.requiresApproval" class="pill-amber mt-1 inline-flex">🔒 Schvaľuje sa</span>`.

- [ ] **Step 3: Explain on the selected resource**

Inside the "Picked state" box, after the damage `<div v-if="selectedResource.openDamage" …>…</div>`, add:

```vue
        <!-- REZ-051/052: the booking becomes a request; members only. -->
        <div
          v-if="selectedResource.requiresApproval"
          class="mt-3 border-t pt-3 text-sm"
          :class="selectedResource.openDamage ? 'border-amber-200 text-amber-900' : 'border-emerald-200 text-emerald-900'"
        >
          <p class="font-medium">🔒 Tento zdroj vyžaduje schválenie.</p>
          <p class="mt-1">
            Po odoslaní bude rezervácia čakať na schválenie a termín bude medzitým pre ostatných
            blokovaný. O výsledku ťa budeme informovať e-mailom.
          </p>
          <p v-if="approvalBlocked" class="mt-2 font-medium">
            Rezervovať ho môže iba prihlásený člen klubu.
            <RouterLink :to="{ path: '/login', query: { redirect: route.fullPath } }" class="underline">
              Prihlásiť sa
            </RouterLink>
          </p>
        </div>
```

- [ ] **Step 4: Submit row and success card**

Replace the submit `<button type="submit" …>…</button>` with:

```vue
      <RouterLink
        v-if="approvalBlocked"
        :to="{ path: '/login', query: { redirect: route.fullPath } }"
        class="btn-primary"
      >
        Prihlásiť sa a požiadať
      </RouterLink>
      <button
        v-else
        type="submit"
        class="btn-primary"
        :disabled="submitting || !rangeIsValid || !form.resourceId || !acceptedTerms"
      >
        {{ submitting ? 'Ukladám…' : requiresApproval ? 'Odoslať žiadosť o rezerváciu' : 'Vytvoriť rezerváciu' }}
      </button>
```

Success card — change the wrapper and heading block to:

```vue
  <div
    v-if="createdReservation"
    class="card-padded grid gap-4 border"
    :class="createdIsPending ? 'border-amber-200 bg-amber-50/40' : 'border-emerald-200 bg-emerald-50/40'"
  >
    <div class="flex items-start gap-3">
      <span class="text-3xl" aria-hidden="true">{{ createdIsPending ? '⏳' : '✅' }}</span>
      <div class="flex-1">
        <h2 class="text-lg font-semibold" :class="createdIsPending ? 'text-amber-900' : 'text-emerald-900'">
          {{ createdIsPending ? 'Žiadosť odoslaná — čaká na schválenie' : 'Rezervácia vytvorená' }}
        </h2>
        <p class="mt-1 text-sm" :class="createdIsPending ? 'text-amber-800' : 'text-emerald-800'">
          {{ formatReservationRange(createdReservation.startsAt, createdReservation.endsAt) }}
          <template v-if="selectedResource">
            · {{ selectedResource.identifier }} · {{ selectedResource.name }}
          </template>
        </p>
        <p v-if="createdIsPending" class="mt-2 text-sm text-amber-800">
          Schvaľovateľ dostal e-mail. O výsledku ťa budeme informovať
          <template v-if="auth.user?.email">na <strong>{{ auth.user.email }}</strong></template>.
          Stav žiadosti vidíš v „Moje rezervácie“ na prehľade.
        </p>
      </div>
    </div>
```

and gate the calendar block with `v-if="!createdIsPending"` on its `<div class="border-t border-emerald-200 pt-3">` (a tentative slot has no place in a personal calendar yet).

- [ ] **Step 5: Typecheck, test, manual check, commit**

Run: `pnpm typecheck` → clean. Run: `pnpm test` → green.
Manual (optional, `pnpm dev` against a local backend): flag a resource, open `/reservations/new` logged out → picking it shows the lock note and "Prihlásiť sa a požiadať"; logged in as member → "Odoslať žiadosť o rezerváciu" → amber success card.

```bash
git add src/views/ReservationFormView.vue
git commit -m "feat(frontend): booking form explains approval and gates it to members

Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>"
```

---

### Task 12: Admin resource form (flag + approver picker), resource list and detail badges

Spec §6.3 (`ResourceFormView`, `ResourcesView`, `ResourceDetailView`).

**Files:**
- Modify: `frontend/src/views/ResourceFormView.vue`, `src/views/ResourcesView.vue`, `src/views/ResourceDetailView.vue`

**Interfaces:**
- Consumes: `usersApi.list`, `CreateResourceInput.requiresApproval/approverIds`, `Resource.approvers`.

- [ ] **Step 1: Resource form — state and loading**

`src/views/ResourceFormView.vue` script: add imports `import { computed, onMounted, reactive, ref } from 'vue';` (add `computed`), `import { usersApi } from '@/api/users.api';`, `import { RESOURCE_TYPE_VALUES, ResourceType, type User } from '@/api/types';`. Extend `form`:

```ts
  isActive: true,
  requiresApproval: false,
  approverIds: [] as string[],
```

In `load()`'s `Object.assign(form, {…})` add `requiresApproval: r.requiresApproval,` and `approverIds: r.approvers?.map((a) => a.id) ?? [],`.

Add after `load()`:

```ts
// Approver picker (REZ-050): confirmed members + admins, searchable.
const members = ref<User[]>([]);
const approverSearch = ref('');

async function loadMembers(): Promise<void> {
  try {
    const data = await usersApi.list({ pageSize: 500 });
    members.value = data.items
      .filter((u) => u.isActive && (u.role === 'MEMBER' || u.role === 'ADMIN'))
      .sort((a, b) => a.name.localeCompare(b.name, 'sk'));
  } catch (e) {
    error.value = (e as Error).message;
  }
}

const filteredMembers = computed(() => {
  const q = approverSearch.value.trim().toLowerCase();
  if (!q) return members.value;
  return members.value.filter(
    (u) => u.name.toLowerCase().includes(q) || u.email.toLowerCase().includes(q),
  );
});

function toggleApprover(id: string): void {
  const i = form.approverIds.indexOf(id);
  if (i >= 0) form.approverIds.splice(i, 1);
  else form.approverIds.push(id);
}
```

Change `onMounted(load);` to:

```ts
onMounted(() => {
  void load();
  void loadMembers();
});
```

(`submit()` already spreads `...form`, so `requiresApproval` and `approverIds` travel with the payload.)

- [ ] **Step 2: Resource form — template**

After the "Aktívny zdroj" checkbox `<div>` add:

```vue
    <!-- Approval workflow (REZ-050). -->
    <div class="sm:col-span-2 rounded-lg border border-slate-200 p-4">
      <label class="flex items-center gap-2">
        <input id="requiresApproval" v-model="form.requiresApproval" type="checkbox" class="h-4 w-4 rounded" />
        <span class="text-sm font-medium text-slate-700">Vyžaduje schválenie pred rezerváciou</span>
      </label>
      <p class="mt-1 text-xs text-slate-500">
        Rezervácia tohto zdroja bude čakať, kým ju niekto zo schvaľovateľov schváli.
        Rezervovať ho môžu iba prihlásení členovia; termín je medzitým blokovaný.
      </p>

      <div v-if="form.requiresApproval" class="mt-3">
        <span class="label">Schvaľovatelia</span>
        <p class="mt-1 text-xs text-slate-500">
          Ak nevyberieš nikoho, žiadosti pôjdu na klubovú adresu administrátorom.
          Správcovia môžu schvaľovať vždy, aj keď tu nie sú.
        </p>
        <input
          v-model="approverSearch"
          type="search"
          class="input mt-2 text-sm"
          placeholder="Hľadať člena — meno alebo e-mail…"
          maxlength="60"
        />
        <ul class="mt-2 max-h-56 divide-y divide-slate-100 overflow-y-auto rounded-lg border border-slate-200">
          <li v-for="u in filteredMembers" :key="u.id">
            <label class="flex cursor-pointer items-center gap-2 px-3 py-2 text-sm hover:bg-slate-50">
              <input
                type="checkbox"
                class="h-4 w-4 rounded"
                :checked="form.approverIds.includes(u.id)"
                @change="toggleApprover(u.id)"
              />
              <span class="font-medium text-slate-800">{{ u.name }}</span>
              <span class="text-xs text-slate-400">{{ u.email }}</span>
              <span v-if="u.role === 'ADMIN'" class="ml-auto text-[10px] font-semibold uppercase text-amber-700">admin</span>
            </label>
          </li>
          <li v-if="filteredMembers.length === 0" class="px-3 py-2 text-sm text-slate-400">
            Žiadny člen nezodpovedá hľadaniu.
          </li>
        </ul>
        <p class="mt-1 text-xs text-slate-500">Vybraní schvaľovatelia: {{ form.approverIds.length }}</p>
      </div>
    </div>
```

- [ ] **Step 3: Resource list and detail badges**

`src/views/ResourcesView.vue` — in the "Stav" cell after the active/inactive pill:

```vue
              <span v-if="r.requiresApproval" class="pill-amber ml-1">🔒 Schvaľuje sa</span>
```

`src/views/ResourceDetailView.vue` — next to `<span v-if="!resource.isActive" class="pill-slate">Neaktívny</span>`:

```vue
      <span v-if="resource.requiresApproval" class="pill-amber">🔒 Schvaľuje sa</span>
```

and in the "Informácie" `<dl>` after the Identifikátor row:

```vue
          <template v-if="resource.requiresApproval">
            <dt class="text-slate-500">Schvaľovanie</dt>
            <dd class="col-span-2 text-slate-800">
              Rezervácia čaká na schválenie.
              <template v-if="resource.approvers && resource.approvers.length">
                Schvaľuje: {{ resource.approvers.map((a) => a.name).join(', ') }}.
              </template>
              <template v-else-if="resource.approvers">Schvaľujú administrátori.</template>
            </dd>
          </template>
```

- [ ] **Step 4: Typecheck, test, commit**

Run: `pnpm typecheck` → clean. Run: `pnpm test` → green.

```bash
git add src/views/ResourceFormView.vue src/views/ResourcesView.vue src/views/ResourceDetailView.vue
git commit -m "feat(frontend): admin flags a resource for approval and picks approvers

Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>"
```

---

### Task 13: Own e-mail preferences — backend endpoints + profile section

Spec §5.4 (`ProfileController`), §5.7 routes, §6.3 (`ProfileView`). Backend and frontend land together because they change together.

**Files:**
- Modify: `backend-php/app/Http/Controllers/Api/ProfileController.php`, `backend-php/routes/api.php`
- Test: `backend-php/tests/Feature/Api/ProfileApiTest.php`
- Modify: `frontend/src/views/ProfileView.vue`
- Test: `frontend/src/views/ProfileView.spec.ts` (new)

**Interfaces:**
- Consumes: `UserNotificationPreferences::all/update/configurable`, `profileApi.notifications/setNotifications`.
- Produces: `GET /profile/notifications` → `{ notifications: [{ key, label, description, enabled }] }`; `PATCH /profile/notifications` body `{ <key>: bool }` (unknown key → 400).

- [ ] **Step 1: Write the failing backend tests**

Append to `tests/Feature/Api/ProfileApiTest.php`:

```php
    public function test_user_reads_and_changes_own_notification_preferences(): void
    {
        $user = User::create([
            'name' => 'Self', 'email' => 'prefs@example.test',
            'password' => 'currentpass1', 'role' => UserRole::PENDING, 'isActive' => true,
        ]);
        \Laravel\Sanctum\Sanctum::actingAs($user, ['*']);

        $r = $this->getJson('/api/v1/profile/notifications')->assertOk();
        $r->assertJsonStructure(['notifications' => [['key', 'label', 'description', 'enabled']]]);
        $this->assertSame(
            ['reservation_approval_requested', 'reservation_decided'],
            collect($r->json('notifications'))->pluck('key')->all(),
        );
        $this->assertTrue(collect($r->json('notifications'))->every(fn ($n) => $n['enabled'] === true));

        $this->patchJson('/api/v1/profile/notifications', ['reservation_decided' => false])
            ->assertOk()
            ->assertJsonPath('notifications.1.enabled', false)
            ->assertJsonPath('notifications.0.enabled', true);
        $this->assertSame(['reservation_approval_requested' => true, 'reservation_decided' => false], $user->refresh()->notificationPrefs);
    }

    public function test_notification_preferences_reject_unknown_or_non_configurable_keys(): void
    {
        $user = User::create([
            'name' => 'Self', 'email' => 'prefs2@example.test',
            'password' => 'currentpass1', 'role' => UserRole::MEMBER, 'isActive' => true,
        ]);
        \Laravel\Sanctum\Sanctum::actingAs($user, ['*']);

        $this->patchJson('/api/v1/profile/notifications', ['vymyslene' => false])->assertStatus(400);
        $this->patchJson('/api/v1/profile/notifications', ['password_reset' => false])->assertStatus(400);
        $this->patchJson('/api/v1/profile/notifications', ['reservation_decided' => 'nie'])->assertStatus(400);
    }

    public function test_notification_preferences_require_login(): void
    {
        $this->getJson('/api/v1/profile/notifications')->assertStatus(401);
        $this->patchJson('/api/v1/profile/notifications', ['reservation_decided' => false])->assertStatus(401);
    }
```

- [ ] **Step 2: Run to see them fail**

Run: `cd backend-php && vendor/bin/phpunit tests/Feature/Api/ProfileApiTest.php` → FAIL (404).

- [ ] **Step 3: Controller + routes**

`app/Http/Controllers/Api/ProfileController.php` — add imports `use App\Domain\Enums\MailNotification; use App\Services\UserNotificationPreferences;` and two methods:

```php
    /**
     * GET /api/v1/profile/notifications — the user's own e-mail switches
     * (REZ-062). Only user-configurable notifications are listed; the label
     * and description come from the enum so the profile never drifts from
     * the admin diagnostics page.
     */
    public function notifications(Request $request, UserNotificationPreferences $prefs): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return new JsonResponse(['notifications' => $this->presentPreferences($prefs->all($user))]);
    }

    /** PATCH /api/v1/profile/notifications — partial update, `{ key: bool }`. */
    public function updateNotifications(Request $request, UserNotificationPreferences $prefs): JsonResponse
    {
        $known = array_map(fn (MailNotification $t) => $t->value, UserNotificationPreferences::configurable());

        $unknown = array_diff(array_keys($request->all()), $known);
        if ($unknown !== []) {
            throw ValidationException::withMessages([
                'notifications' => 'Túto notifikáciu si nemôžeš nastaviť: '.implode(', ', $unknown),
            ]);
        }
        $request->validate(array_fill_keys($known, ['sometimes', 'boolean']));

        /** @var User $user */
        $user = $request->user();
        $state = $prefs->update($user, $request->all());

        return new JsonResponse(['notifications' => $this->presentPreferences($state)]);
    }

    /**
     * @param  array<string, bool>  $state
     * @return list<array<string, mixed>>
     */
    private function presentPreferences(array $state): array
    {
        return array_map(fn (MailNotification $type) => [
            'key' => $type->value,
            'label' => $type->label(),
            'description' => $type->description(),
            'enabled' => $state[$type->value],
        ], UserNotificationPreferences::configurable());
    }
```

`routes/api.php` — inside the `auth:sanctum` group after the `profile/oauth/{provider}/link-url` line:

```php
    // Own e-mail switches for the user-configurable notifications (REZ-062).
    Route::get('profile/notifications', [ProfileController::class, 'notifications']);
    Route::patch('profile/notifications', [ProfileController::class, 'updateNotifications']);
```

Run: `vendor/bin/phpunit tests/Feature/Api/ProfileApiTest.php` → PASS. Run: `vendor/bin/phpunit` → green.

- [ ] **Step 4: Write the failing profile view test**

`frontend/src/views/ProfileView.spec.ts`:

```ts
import { flushPromises, mount } from '@vue/test-utils';
import { createPinia, setActivePinia } from 'pinia';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { createMemoryHistory, createRouter } from 'vue-router';

import { useAuthStore } from '@/stores/auth.store';

import ProfileView from './ProfileView.vue';

const notifications = vi.fn();
const setNotifications = vi.fn();

vi.mock('@/api/profile.api', () => ({
  profileApi: {
    notifications: (...a: unknown[]) => notifications(...a),
    setNotifications: (...a: unknown[]) => setNotifications(...a),
    identities: vi.fn().mockResolvedValue([]),
    unlinkIdentity: vi.fn(),
    linkUrl: vi.fn(),
  },
}));
vi.mock('@/api/auth.api', () => ({
  authApi: { providers: vi.fn().mockResolvedValue([]) },
}));

const prefs = [
  { key: 'reservation_approval_requested', label: 'Žiadosť o schválenie rezervácie', description: 'Schvaľovateľom.', enabled: true },
  { key: 'reservation_decided', label: 'Výsledok schvaľovania rezervácie', description: 'Rezervujúcemu.', enabled: true },
];

async function mountView() {
  setActivePinia(createPinia());
  const auth = useAuthStore();
  auth.token = 't';
  auth.user = { id: 'u1', name: 'Janko', email: 'j@example.test', role: 'MEMBER' } as never;
  const router = createRouter({ history: createMemoryHistory(), routes: [{ path: '/profil', component: ProfileView }] });
  await router.push('/profil');
  await router.isReady();
  const w = mount(ProfileView, { global: { plugins: [router] } });
  await flushPromises();
  return w;
}

describe('ProfileView — e-mail preferences', () => {
  beforeEach(() => {
    notifications.mockReset().mockResolvedValue(prefs);
    setNotifications.mockReset().mockResolvedValue([prefs[0], { ...prefs[1], enabled: false }]);
  });

  it('lists the user-configurable notifications', async () => {
    const w = await mountView();
    expect(w.text()).toContain('E-mailové notifikácie');
    expect(w.text()).toContain('Výsledok schvaľovania rezervácie');
  });

  it('toggling a switch patches just that key', async () => {
    const w = await mountView();
    await w.find('input#pref-reservation_decided').setValue(false);
    await flushPromises();

    expect(setNotifications).toHaveBeenCalledWith({ reservation_decided: false });
    expect((w.find('input#pref-reservation_decided').element as HTMLInputElement).checked).toBe(false);
  });
});
```

Run: `cd frontend && pnpm test -- ProfileView` → FAIL (no section).

- [ ] **Step 5: Profile section**

`src/views/ProfileView.vue` script — import `type { NotificationPreference, OAuthProviderInfo, UserIdentity } from '@/api/types';` and add:

```ts
// E-mail preferences (REZ-062) — only the user-configurable notifications.
const prefs = ref<NotificationPreference[]>([]);
const prefsLoading = ref(true);
const prefsError = ref<string | null>(null);

async function loadPrefs(): Promise<void> {
  prefsLoading.value = true;
  prefsError.value = null;
  try {
    prefs.value = await profileApi.notifications();
  } catch (e) {
    prefsError.value = (e as Error).message;
  } finally {
    prefsLoading.value = false;
  }
}

async function togglePref(p: NotificationPreference): Promise<void> {
  prefsError.value = null;
  try {
    prefs.value = await profileApi.setNotifications({ [p.key]: !p.enabled });
  } catch (e) {
    prefsError.value = (e as Error).message;
  }
}
```

Change `onMounted(loadLinks);` to:

```ts
onMounted(() => {
  void loadLinks();
  void loadPrefs();
});
```

Template — insert after the "Change password" `</section>`:

```vue
    <!-- E-mail preferences -->
    <section class="rounded-xl bg-white p-5 shadow-sm ring-1 ring-slate-200">
      <h2 class="text-sm font-semibold uppercase tracking-wide text-slate-500">E-mailové notifikácie</h2>
      <p class="mt-1 text-xs text-slate-500">
        Ktoré e-maily ti má systém posielať. Prevádzkové e-maily (obnova hesla, pozvánka) sa vypnúť nedajú.
      </p>
      <div v-if="prefsLoading" class="mt-3"><Spinner /></div>
      <template v-else>
        <LoadError :message="prefsError" />
        <ul class="mt-3 divide-y divide-slate-100">
          <li v-for="p in prefs" :key="p.key" class="flex items-start justify-between gap-3 py-3">
            <div>
              <p class="text-sm font-medium text-slate-800">{{ p.label }}</p>
              <p class="text-xs text-slate-500">{{ p.description }}</p>
            </div>
            <label class="inline-flex shrink-0 items-center gap-2 text-sm">
              <input
                :id="`pref-${p.key}`"
                type="checkbox"
                class="h-4 w-4 rounded"
                :checked="p.enabled"
                @change="togglePref(p)"
              />
              <span class="text-slate-600">{{ p.enabled ? 'Zapnuté' : 'Vypnuté' }}</span>
            </label>
          </li>
        </ul>
      </template>
    </section>
```

- [ ] **Step 6: Run, typecheck, commit**

Run: `pnpm test` → green (49). Run: `pnpm typecheck` → clean.

```bash
cd /home/tomas/projects/lodenica
git add backend-php/app/Http/Controllers/Api/ProfileController.php backend-php/routes/api.php \
  backend-php/tests/Feature/Api/ProfileApiTest.php frontend/src/views/ProfileView.vue frontend/src/views/ProfileView.spec.ts
git commit -m "feat(profile): members switch their own approval e-mails on and off

Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>"
```

---

### Task 14: Whole-system verification and doc consistency

**Files:**
- Read: `docs/spec/02-reservations.md`, `docs/superpowers/specs/2026-09-05-reservation-approval-design.md`
- Possibly modify: `docs/spec/02-reservations.md` (test names), `docs/superpowers/specs/2026-09-05-reservation-approval-design.md` (status line)

- [ ] **Step 1: Full backend run**

Run: `cd backend-php && vendor/bin/phpunit` → 0 failures, 0 errors; skipped only the Postgres tests (now 5) when Postgres is unavailable.

- [ ] **Step 2: Full frontend run + build**

Run: `cd frontend && pnpm test && pnpm typecheck && pnpm build` → all green, `dist/` produced.

- [ ] **Step 3: Spec ↔ tests cross-check**

For every `Test:` line under `## Schvaľovanie` in `docs/spec/02-reservations.md`, confirm the named method exists:

```bash
cd backend-php && for t in test_member_booking_of_a_gated_resource_waits_for_approval test_anonymous_cannot_book_a_gated_resource \
  test_pending_account_cannot_book_a_gated_resource test_a_pending_request_holds_the_slot test_approver_approves \
  test_approver_rejects_and_frees_the_slot test_member_outside_the_approver_list_cannot_decide test_admin_can_decide_without_being_listed \
  test_second_decision_is_rejected_with_409 test_patch_cannot_change_status_of_a_pending_reservation test_patch_cannot_confirm_on_a_gated_resource \
  test_patch_of_other_fields_keeps_the_request_pending test_cancelling_a_pending_request_frees_the_slot test_cancelling_a_rejected_reservation_is_a_no_op \
  test_approvals_list_is_scoped_to_the_caller test_list_accepts_multiple_statuses test_ics_marks_a_pending_reservation_tentative \
  test_admin_sets_requires_approval_and_approvers test_approver_must_be_a_confirmed_member test_decision_goes_to_the_creator \
  test_exclude_constraint_treats_pending_approval_as_occupied; do grep -rq "function $t" tests || echo "MISSING: $t"; done
```

Expected: no `MISSING:` lines. Fix the spec or the test name if one appears.

- [ ] **Step 4: Manual smoke on the test environment (user-driven)**

Deploy to `test_rezervacie.lodenicakvs.sk` with the usual script (`--secrets .deploy-secrets.test`, no `--import-sheet`). Then: flag a resource with one approver → book it as another member → approver's inbox gets the mail → `/approvals` shows it with a badge → approve → booker's inbox gets the result → timeline shows the slot solid. Confirm an unflagged boat books exactly as before.

- [ ] **Step 5: Close out**

Update the design doc's `**Status:**` line to `implemented — see docs/superpowers/plans/2026-09-05-reservation-approval.md` and commit:

```bash
cd /home/tomas/projects/lodenica
git add docs/superpowers/specs/2026-09-05-reservation-approval-design.md
git commit -m "docs(spec): mark the approval workflow design as implemented

Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>"
```
