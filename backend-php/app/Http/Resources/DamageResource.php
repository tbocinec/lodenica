<?php

namespace App\Http\Resources;

use App\Models\Damage;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Damage
 */
class DamageResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'resourceId' => $this->resourceId,
            'description' => $this->description,
            'severity' => $this->severity->value,
            'status' => $this->status->value,
            'reportedAt' => $this->reportedAt?->toIso8601String(),
            'fixedAt' => $this->fixedAt?->toIso8601String(),
            'note' => $this->note,
            'createdAt' => $this->createdAt?->toIso8601String(),
            'updatedAt' => $this->updatedAt?->toIso8601String(),
        ];
    }
}
