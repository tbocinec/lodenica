<?php

namespace App\Http\Resources;

use App\Models\Reservation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Reservation
 */
class ReservationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'resourceId' => $this->resourceId,
            'eventId' => $this->eventId,
            'customerName' => $this->customerName,
            'customerContact' => $this->customerContact,
            'startsAt' => $this->startsAt?->toIso8601String(),
            'endsAt' => $this->endsAt?->toIso8601String(),
            'note' => $this->note,
            'status' => $this->status->value,
            'createdAt' => $this->createdAt?->toIso8601String(),
            'updatedAt' => $this->updatedAt?->toIso8601String(),
        ];
    }
}
