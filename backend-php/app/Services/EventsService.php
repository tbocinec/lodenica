<?php

namespace App\Services;

use App\Domain\Enums\AuditAction;
use App\Domain\Enums\AuditEntityType;
use App\Domain\ValueObjects\TimeRange;
use App\Exceptions\NotFoundDomainException;
use App\Models\Event;
use App\Models\EventParticipant;
use App\Models\Reservation;

class EventsService
{
    public function __construct(
        private readonly ReservationsService $reservations,
        private readonly AuditLogger $audit,
    ) {}

    public function create(array $cmd): Event
    {
        $range = TimeRange::fromInstants($cmd['startsAt'], $cmd['endsAt']);

        $event = Event::create([
            'title' => $cmd['title'],
            'description' => $cmd['description'] ?? null,
            'location' => $cmd['location'] ?? null,
            'startsAt' => $range->startsAt,
            'endsAt' => $range->endsAt,
        ]);

        $this->audit->logCreate(
            AuditEntityType::EVENT,
            $event,
            "Pridaná udalosť „{$event->title}“",
            AuditSnapshot::event($event),
        );

        return $event;
    }

    public function update(string $id, array $cmd): Event
    {
        $event = $this->requireExisting($id);
        $before = AuditSnapshot::event($event);

        $newStartsAt = $event->startsAt;
        $newEndsAt = $event->endsAt;
        if (array_key_exists('startsAt', $cmd) || array_key_exists('endsAt', $cmd)) {
            $range = TimeRange::fromInstants(
                $cmd['startsAt'] ?? $event->startsAt,
                $cmd['endsAt'] ?? $event->endsAt,
            );
            $newStartsAt = $range->startsAt;
            $newEndsAt = $range->endsAt;
        }

        $updates = array_intersect_key($cmd, array_flip([
            'title', 'description', 'location',
        ]));
        $updates['startsAt'] = $newStartsAt;
        $updates['endsAt'] = $newEndsAt;

        $event->fill($updates);
        $event->save();
        $event->refresh();

        $this->audit->logUpdate(
            AuditEntityType::EVENT,
            $event,
            "Upravená udalosť „{$event->title}“",
            $before,
            AuditSnapshot::event($event),
        );

        return $event;
    }

    public function remove(string $id): void
    {
        $event = $this->requireExisting($id);
        $snapshot = AuditSnapshot::event($event);
        $title = $event->title;
        $event->delete();

        $this->audit->logDelete(
            AuditEntityType::EVENT,
            $event,
            "Zmazaná udalosť „{$title}“",
            $snapshot,
        );
    }

    public function findById(string $id): Event
    {
        return $this->requireExisting($id);
    }

    public function list(array $options): array
    {
        $query = Event::query();

        if (!empty($options['from']) || !empty($options['to'])) {
            if (!empty($options['to'])) {
                $query->where('startsAt', '<', $options['to']);
            }
            if (!empty($options['from'])) {
                $query->where('endsAt', '>', $options['from']);
            }
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
     * Bulk-attach resources to an event. Each resource becomes one reservation
     * using the event's time window. Sequential to surface clear per-resource
     * conflicts; the DB exclusion constraint is the ultimate safeguard.
     *
     * The per-reservation audit rows are written by ReservationsService;
     * we add a single event-level summary on top so the audit log has both
     * granularities side-by-side.
     *
     * @return Reservation[]
     */
    public function attachResources(string $eventId, array $resourceIds): array
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
            ]);
        }

        if (!empty($created)) {
            $count = count($created);
            $this->audit->logAction(
                AuditEntityType::EVENT,
                $event->id,
                AuditAction::ATTACH_RESOURCES,
                "K udalosti „{$event->title}“ pripojených {$count} ".$this->numUnit($count, 'zdroj', 'zdroje', 'zdrojov'),
                ['resourceIds' => array_values($resourceIds), 'reservationIds' => array_map(fn (Reservation $r) => $r->id, $created)],
            );
        }

        return $created;
    }

    public function addParticipant(string $eventId, array $cmd): EventParticipant
    {
        $event = $this->requireExisting($eventId);

        $participant = EventParticipant::create([
            'eventId' => $eventId,
            'name' => $cmd['name'],
            'contact' => $cmd['contact'] ?? null,
            'note' => $cmd['note'] ?? null,
        ]);

        $this->audit->logAction(
            AuditEntityType::EVENT_PARTICIPANT,
            $participant->id,
            AuditAction::ADD_PARTICIPANT,
            "K udalosti „{$event->title}“ pridaný účastník „{$participant->name}“",
            ['after' => AuditSnapshot::participant($participant)],
        );

        return $participant;
    }

    public function removeParticipant(string $eventId, string $participantId): void
    {
        $event = $this->requireExisting($eventId);

        $participant = EventParticipant::find($participantId);
        if ($participant === null || $participant->eventId !== $eventId) {
            throw new NotFoundDomainException('EventParticipant', $participantId);
        }
        $snapshot = AuditSnapshot::participant($participant);
        $name = $participant->name;
        $participant->delete();

        $this->audit->logAction(
            AuditEntityType::EVENT_PARTICIPANT,
            $participantId,
            AuditAction::REMOVE_PARTICIPANT,
            "Z udalosti „{$event->title}“ odobratý účastník „{$name}“",
            ['before' => $snapshot],
        );
    }

    /** @return \Illuminate\Support\Collection<int, EventParticipant> */
    public function listParticipants(string $eventId)
    {
        return EventParticipant::query()
            ->where('eventId', $eventId)
            ->orderBy('createdAt')
            ->get();
    }

    private function numUnit(int $n, string $one, string $few, string $many): string
    {
        if ($n === 1) {
            return $one;
        }
        if ($n >= 2 && $n <= 4) {
            return $few;
        }

        return $many;
    }

    private function requireExisting(string $id): Event
    {
        $event = Event::find($id);
        if ($event === null) {
            throw new NotFoundDomainException('Event', $id);
        }

        return $event;
    }
}
