<?php

namespace App\Http\Resources;

use App\Models\AuditLog;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin AuditLog
 */
class AuditLogResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        // Who made the change is admin-only; regular members see the changes
        // but not the person behind them.
        $viewer = $request->user('sanctum') ?? $request->user();
        $isAdmin = $viewer instanceof \App\Models\User && $viewer->isAdmin();

        return [
            'id' => $this->id,
            'entityType' => $this->entityType?->value,
            'entityId' => $this->entityId,
            'action' => $this->action?->value,
            'summary' => $this->summary,
            'changes' => $this->changes,
            'actor' => $isAdmin ? $this->actor : null,
            'createdAt' => $this->createdAt?->toIso8601String(),
        ];
    }
}
