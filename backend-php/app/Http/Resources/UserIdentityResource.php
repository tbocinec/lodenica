<?php

namespace App\Http\Resources;

use App\Models\UserIdentity;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin UserIdentity
 */
class UserIdentityResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'provider' => $this->provider?->value,
            'providerLabel' => $this->provider?->label(),
            'email' => $this->email,
            'createdAt' => $this->createdAt?->toIso8601String(),
        ];
    }
}
