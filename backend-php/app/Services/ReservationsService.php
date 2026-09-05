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
