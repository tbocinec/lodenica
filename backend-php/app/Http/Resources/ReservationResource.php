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
        // using it. See docs/AUTH-AND-PERMISSIONS.md for the matrix.
        //
        // GET /reservations is a public route (no `auth:sanctum`
        // middleware), which means Laravel's default guard never runs
        // and `$request->user()` returns null even when a member
        // sends a Bearer token. Ask the sanctum guard explicitly so
        // the token gets resolved regardless of route gating.
        $user = $request->user('sanctum') ?? $request->user();
        $isMember = $user instanceof \App\Models\User && $user->isMember();
        $isAdmin = $user instanceof \App\Models\User && $user->isAdmin();

        return [
            'id' => $this->id,
            'resourceId' => $this->resourceId,
            'eventId' => $this->eventId,
            // The logged-in user who created the booking (null for
            // anonymous). Lets the SPA flag "my reservations". Not PII.
            'createdById' => $this->createdById,
            // Internal member ID this booking is mapped to — admin-only
            // (admins can reassign it). See docs/AUTH-AND-PERMISSIONS.md.
            'memberId' => $isAdmin ? $this->memberId : null,
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
