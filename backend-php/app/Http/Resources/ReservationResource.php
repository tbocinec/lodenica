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
        // Customer contact is private PII (email / phone of the booker).
        // The reservation list is intentionally public-readable so other
        // paddlers can see whose boat is out, but the contact column is
        // gated behind authentication — anonymous callers never receive
        // the value, not even on detail / edit endpoints. The frontend
        // edit dialog handles this gracefully (blank input; PATCH skips
        // the field if untouched so the DB value is preserved).
        $isAuthed = $request->user() !== null;

        return [
            'id' => $this->id,
            'resourceId' => $this->resourceId,
            'eventId' => $this->eventId,
            'customerName' => $this->customerName,
            'customerContact' => $isAuthed ? $this->customerContact : null,
            'startsAt' => $this->startsAt?->toIso8601String(),
            'endsAt' => $this->endsAt?->toIso8601String(),
            'note' => $this->note,
            'status' => $this->status->value,
            'createdAt' => $this->createdAt?->toIso8601String(),
            'updatedAt' => $this->updatedAt?->toIso8601String(),
        ];
    }
}
