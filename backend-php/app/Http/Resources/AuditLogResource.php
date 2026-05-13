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
        return [
            'id' => $this->id,
            'entityType' => $this->entityType?->value,
            'entityId' => $this->entityId,
            'action' => $this->action?->value,
            'summary' => $this->summary,
            'changes' => $this->changes,
            'actor' => $this->actor,
            'createdAt' => $this->createdAt?->toIso8601String(),
        ];
    }
}
