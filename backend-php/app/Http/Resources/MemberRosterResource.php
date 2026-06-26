<?php

namespace App\Http\Resources;

use App\Models\MemberRosterEntry;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin MemberRosterEntry
 */
class MemberRosterResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'email' => $this->email,
            'memberId' => $this->memberId,
            'name' => $this->name,
            'registeredUserId' => $this->registeredUserId,
            'registeredAt' => $this->registeredAt?->toIso8601String(),
            'createdAt' => $this->createdAt?->toIso8601String(),
        ];
    }
}
