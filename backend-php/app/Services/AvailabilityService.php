<?php

namespace App\Services;

use App\Domain\Enums\DamageStatus;
use App\Domain\Enums\ReservationStatus;
use App\Domain\Enums\ResourceType;
use App\Models\Damage;
use App\Models\Reservation;
use App\Models\Resource;
use Carbon\CarbonImmutable;

class AvailabilityService
{
    public function snapshot(?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();
        $today = $now->startOfDay();
        $tomorrow = $today->addDay();
        $dayAfterTomorrow = $today->addDays(2);
        $horizonEnd = $today->addDays(30);

        $todays = $this->reservationsActiveDuring($today, $tomorrow);
        $tomorrows = $this->reservationsActiveDuring($tomorrow, $dayAfterTomorrow);
        $upcoming = $this->reservationsActiveDuring($today, $horizonEnd);
        $spaces = $this->spaceReservationsBetween($today, $horizonEnd);
        $openDamageRows = $this->openDamages();
        $allResources = Resource::query()->where('isActive', true)->get();

        $damagedResourceIds = $openDamageRows->pluck('resourceId')->all();
        $occupiedTodayIds = $todays->pluck('resourceId')->all();

        $available = $allResources->filter(
            fn (Resource $r) => $r->type !== ResourceType::BOATHOUSE_SPACE
                && !in_array($r->id, $damagedResourceIds, true)
                && !in_array($r->id, $occupiedTodayIds, true),
        )->values();

        $damaged = $openDamageRows->map(fn (Damage $d) => [
            'resourceId' => $d->resourceId,
            'resource' => $d->resource,
            'damageId' => $d->id,
            'description' => $d->description,
            'severity' => $d->severity->value,
            'status' => $d->status->value,
            'reportedAt' => $d->reportedAt?->toIso8601String(),
        ]);

        return [
            'generatedAt' => CarbonImmutable::now()->toIso8601String(),
            'today' => $today->toIso8601String(),
            'occupiedToday' => $todays->map($this->renderReservation(...))->all(),
            'occupiedTomorrow' => $tomorrows->map($this->renderReservation(...))->all(),
            'upcoming' => $upcoming->map($this->renderReservation(...))->all(),
            'spaceReservations' => $spaces->map($this->renderReservation(...))->all(),
            'available' => $available->map($this->renderResource(...))->all(),
            'damaged' => $damaged->all(),
            'totals' => [
                'activeResources' => $allResources->count(),
                'upcomingReservations' => $upcoming->count(),
                'openDamages' => $openDamageRows->count(),
            ],
        ];
    }

    private function reservationsActiveDuring(CarbonImmutable $from, CarbonImmutable $to)
    {
        return Reservation::query()
            ->with('resource')
            ->where('status', ReservationStatus::CONFIRMED->value)
            ->where('startsAt', '<', $to)
            ->where('endsAt', '>', $from)
            ->orderBy('startsAt')
            ->get();
    }

    private function spaceReservationsBetween(CarbonImmutable $from, CarbonImmutable $to)
    {
        return Reservation::query()
            ->with('resource')
            ->where('status', ReservationStatus::CONFIRMED->value)
            ->where('startsAt', '<', $to)
            ->where('endsAt', '>', $from)
            ->whereHas('resource', fn ($q) => $q->where('type', ResourceType::BOATHOUSE_SPACE->value))
            ->orderBy('startsAt')
            ->get();
    }

    private function openDamages()
    {
        return Damage::query()
            ->with('resource')
            ->whereIn('status', [DamageStatus::REPORTED->value, DamageStatus::IN_REPAIR->value])
            ->orderBy('status')
            ->orderByDesc('reportedAt')
            ->get();
    }

    private function renderReservation(Reservation $r): array
    {
        return [
            'id' => $r->id,
            'resourceId' => $r->resourceId,
            'eventId' => $r->eventId,
            'customerName' => $r->customerName,
            'customerContact' => $r->customerContact,
            'startsAt' => $r->startsAt?->toIso8601String(),
            'endsAt' => $r->endsAt?->toIso8601String(),
            'note' => $r->note,
            'status' => $r->status->value,
            'createdAt' => $r->createdAt?->toIso8601String(),
            'updatedAt' => $r->updatedAt?->toIso8601String(),
            'resource' => $r->resource ? $this->renderResource($r->resource) : null,
        ];
    }

    private function renderResource(Resource $r): array
    {
        return [
            'id' => $r->id,
            'identifier' => $r->identifier,
            'type' => $r->type->value,
            'name' => $r->name,
            'model' => $r->model,
            'color' => $r->color,
            'seats' => $r->seats,
            'lengthCm' => $r->lengthCm,
            'weightKg' => $r->weightKg,
            'note' => $r->note,
            'imageUrl' => $r->imageUrl,
            'isActive' => (bool) $r->isActive,
            'createdAt' => $r->createdAt?->toIso8601String(),
            'updatedAt' => $r->updatedAt?->toIso8601String(),
        ];
    }
}
