<?php

namespace App\Services;

use App\Models\Damage;
use App\Models\Event;
use App\Models\EventParticipant;
use App\Models\Reservation;
use App\Models\Resource;

/**
 * Pure functions that turn Eloquent models into the compact arrays that
 * land in `audit_logs.changes`. We deliberately exclude `id`, `createdAt`
 * and `updatedAt` — those are metadata, not "what changed". Enums become
 * their string value; timestamps become ISO-8601 so the JSON stays
 * trivially diffable in tools that look at it later.
 */
final class AuditSnapshot
{
    public static function resource(Resource $r): array
    {
        return [
            'identifier' => $r->identifier,
            'type' => $r->type?->value,
            'name' => $r->name,
            'model' => $r->model,
            'color' => $r->color,
            'seats' => $r->seats,
            'lengthCm' => $r->lengthCm,
            'weightKg' => $r->weightKg,
            'note' => $r->note,
            'imageUrl' => $r->imageUrl,
            'isActive' => (bool) $r->isActive,
            'requiresApproval' => (bool) $r->requiresApproval,
            // Queried, not read from a loaded relation: the "before" snapshot
            // is taken right before a sync and must see the current pivot rows.
            'approverIds' => $r->approvers()->pluck('users.id')->sort()->values()->all(),
        ];
    }

    public static function reservation(Reservation $r): array
    {
        return [
            'resourceId' => $r->resourceId,
            'eventId' => $r->eventId,
            'customerName' => $r->customerName,
            'customerContact' => $r->customerContact,
            'startsAt' => self::iso($r->startsAt),
            'endsAt' => self::iso($r->endsAt),
            'note' => $r->note,
            'status' => $r->status?->value,
            'decisionNote' => $r->decisionNote,
        ];
    }

    public static function event(Event $e): array
    {
        return [
            'title' => $e->title,
            'description' => $e->description,
            'location' => $e->location,
            'startsAt' => self::iso($e->startsAt),
            'endsAt' => self::iso($e->endsAt),
        ];
    }

    public static function damage(Damage $d): array
    {
        return [
            'resourceId' => $d->resourceId,
            'description' => $d->description,
            'severity' => $d->severity?->value ?? $d->severity,
            'status' => $d->status?->value ?? $d->status,
            'note' => $d->note,
            'reportedByName' => $d->reportedByName,
            'assigneeName' => $d->assigneeName,
            'fixedAt' => self::iso($d->fixedAt ?? null),
        ];
    }

    public static function participant(EventParticipant $p): array
    {
        return [
            'eventId' => $p->eventId,
            'name' => $p->name,
            'contact' => $p->contact,
            'note' => $p->note,
        ];
    }

    private static function iso(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        if ($value instanceof \DateTimeInterface) {
            return $value->format(\DateTimeInterface::ATOM);
        }

        return (string) $value;
    }
}
