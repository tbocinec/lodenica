<?php

namespace App\Http\Resources;

use App\Models\EventParticipant;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin EventParticipant
 */
class EventParticipantResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'eventId' => $this->eventId,
            'name' => $this->name,
            'contact' => $this->contact,
            'note' => $this->note,
            'createdAt' => $this->createdAt?->toIso8601String(),
        ];
    }
}
