<?php

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin User
 */
class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        // The internal member ID is admin-only — never exposed to the member
        // themselves. Resolve the sanctum guard explicitly so it works on
        // any route. See docs/AUTH-AND-PERMISSIONS.md.
        $viewer = $request->user('sanctum') ?? $request->user();
        $isAdmin = $viewer instanceof \App\Models\User && $viewer->isAdmin();

        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'role' => $this->role?->value,
            'isActive' => (bool) $this->isActive,
            // Only admins see it; everyone else (incl. the member) gets null.
            'memberId' => $isAdmin ? $this->memberId : null,
            'createdAt' => $this->createdAt?->toIso8601String(),
            'updatedAt' => $this->updatedAt?->toIso8601String(),
        ];
    }
}
