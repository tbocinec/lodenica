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
        // customerName + customerContact are private to confirmed
        // members. Anonymous visitors and PENDING accounts see the
        // schedule (which boat is out at which time) but not WHO is
        // using it. PII gate lives at the API boundary so the value
        // never reaches an unprivileged client — see
        // docs/AUTH-AND-PERMISSIONS.md for the full matrix.
        $user = $request->user();
        $isMember = $user !== null
            && method_exists($user, 'isMember')
            && $user->isMember();

        return [
            'id' => $this->id,
            'resourceId' => $this->resourceId,
            'eventId' => $this->eventId,
            'customerName' => $isMember ? $this->customerName : null,
            'customerContact' => $isMember ? $this->customerContact : null,
            'startsAt' => $this->startsAt?->toIso8601String(),
            'endsAt' => $this->endsAt?->toIso8601String(),
            'note' => $this->note,
            'status' => $this->status->value,
            'createdAt' => $this->createdAt?->toIso8601String(),
            'updatedAt' => $this->updatedAt?->toIso8601String(),
        ];
    }
}
