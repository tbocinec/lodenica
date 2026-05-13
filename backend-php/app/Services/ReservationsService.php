<?php

namespace App\Services;

use App\Domain\Enums\AuditAction;
use App\Domain\Enums\AuditEntityType;
use App\Domain\Enums\ReservationStatus;
use App\Domain\ValueObjects\TimeRange;
use App\Exceptions\InactiveResourceException;
use App\Exceptions\NotFoundDomainException;
use App\Exceptions\ReservationOverlapException;
use App\Models\Reservation;
use App\Models\Resource;
use Illuminate\Database\Eloquent\Builder;

/**
 * Reservation lifecycle. Overlap is checked at the application layer for
 * clear validation messages; the Postgres EXCLUDE constraint enforces the
 * same rule at the DB level and is the safety net for race conditions.
 *
 * Every reservation is `[startsAt, endsAt)` — a single uniform shape.
 * "All-day" or multi-day reservations are just longer ranges; the model
 * does not distinguish them.
 */
class ReservationsService
{
    public function __construct(private readonly AuditLogger $audit) {}

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

        $this->assertNoOverlap($cmd['resourceId'], $range);

        $reservation = Reservation::create([
            'resourceId' => $cmd['resourceId'],
            'eventId' => $cmd['eventId'] ?? null,
            'customerName' => $cmd['customerName'],
            'customerContact' => $cmd['customerContact'] ?? null,
            'startsAt' => $range->startsAt,
            'endsAt' => $range->endsAt,
            'note' => $cmd['note'] ?? null,
            'status' => ReservationStatus::CONFIRMED,
        ]);

        $this->audit->logCreate(
            AuditEntityType::RESERVATION,
            $reservation,
            "Pridaná rezervácia „{$reservation->customerName}“ pre „{$resource->identifier} – {$resource->name}“ ({$this->fmtRange($reservation)})",
            AuditSnapshot::reservation($reservation),
        );

        return $reservation;
    }

    public function update(string $id, array $cmd): Reservation
    {
        $existing = $this->requireExisting($id);
        $before = AuditSnapshot::reservation($existing);

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

            $newStatus = isset($cmd['status'])
                ? ($cmd['status'] instanceof ReservationStatus
                    ? $cmd['status']
                    : ReservationStatus::from($cmd['status']))
                : $existing->status;

            if ($newStatus === ReservationStatus::CONFIRMED) {
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
        $existing->save();
        $existing->refresh();

        $this->audit->logUpdate(
            AuditEntityType::RESERVATION,
            $existing,
            "Upravená rezervácia „{$existing->customerName}“ ({$this->fmtRange($existing)})",
            $before,
            AuditSnapshot::reservation($existing),
        );

        return $existing;
    }

    public function cancel(string $id): Reservation
    {
        $existing = $this->requireExisting($id);
        $wasConfirmed = $existing->isConfirmed();
        $existing->status = ReservationStatus::CANCELLED;
        $existing->save();
        $existing->refresh();

        if ($wasConfirmed) {
            $this->audit->logAction(
                AuditEntityType::RESERVATION,
                $existing->id,
                AuditAction::CANCEL,
                "Zrušená rezervácia „{$existing->customerName}“ ({$this->fmtRange($existing)})",
                ['before' => ['status' => 'CONFIRMED'], 'after' => ['status' => 'CANCELLED']],
            );
        }

        return $existing;
    }

    public function remove(string $id): void
    {
        $existing = $this->requireExisting($id);
        $snapshot = AuditSnapshot::reservation($existing);
        $summary = "Zmazaná rezervácia „{$existing->customerName}“ ({$this->fmtRange($existing)})";
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
        if (!empty($options['status'])) {
            $status = $options['status'] instanceof ReservationStatus
                ? $options['status']
                : ReservationStatus::from($options['status']);
            $query->where('status', $status->value);
        }
        if (!empty($options['range'])) {
            /** @var TimeRange $range */
            $range = $options['range'];
            $query->where('startsAt', '<', $range->endsAt)
                  ->where('endsAt', '>', $range->startsAt);
        }

        $total = (clone $query)->count();

        $items = $query
            ->orderBy('startsAt')
            ->orderBy('createdAt')
            ->skip($options['skip'] ?? 0)
            ->take($options['take'] ?? 25)
            ->get();

        return ['items' => $items, 'total' => $total];
    }

    /**
     * @return \Illuminate\Support\Collection<int, Reservation>
     */
    public function findOverlapping(string $resourceId, TimeRange $range, ?string $excludeId = null)
    {
        return Reservation::query()
            ->where('resourceId', $resourceId)
            ->where('status', ReservationStatus::CONFIRMED->value)
            ->where('startsAt', '<', $range->endsAt)
            ->where('endsAt', '>', $range->startsAt)
            ->when($excludeId, fn (Builder $q, $id) => $q->where('id', '!=', $id))
            ->get();
    }

    private function fmtRange(Reservation $r): string
    {
        // Wall-clock UTC convention — what the user typed is what we display.
        $start = $r->startsAt instanceof \DateTimeInterface ? $r->startsAt : new \DateTimeImmutable((string) $r->startsAt);
        $end = $r->endsAt instanceof \DateTimeInterface ? $r->endsAt : new \DateTimeImmutable((string) $r->endsAt);

        return $start->format('Y-m-d H:i').' – '.$end->format('Y-m-d H:i');
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
