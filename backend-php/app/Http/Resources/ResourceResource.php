<?php

namespace App\Http\Resources;

use App\Models\Resource as ResourceModel;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ResourceModel
 */
class ResourceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        // Approver names are member names → member-only (CORE-030). Public
        // route, so ask the sanctum guard explicitly (CORE-031).
        $viewer = $request->user('sanctum') ?? $request->user();
        $isMember = $viewer instanceof User && $viewer->isMember();

        return [
            'id' => $this->id,
            'identifier' => $this->identifier,
            'type' => $this->type->value,
            'name' => $this->name,
            'model' => $this->model,
            'color' => $this->color,
            'seats' => $this->seats,
            'lengthCm' => $this->lengthCm,
            'weightKg' => $this->weightKg,
            'note' => $this->note,
            'imageUrl' => $this->imageUrl,
            // Uploaded photo served via the backend (distinct from the
            // external imageUrl). Cache-buster from updatedAt forces a
            // reload after re-upload. See ResourcesController::showPhoto.
            'photoUrl' => $this->photoPath
                ? "/api/v1/resources/{$this->id}/photo?v=".(int) ($this->updatedAt?->getTimestamp() ?? 0)
                : null,
            'isActive' => (bool) $this->isActive,
            // Approval workflow (REZ-050). The flag is public so the booking
            // form can explain what will happen; who approves is for members.
            'requiresApproval' => (bool) $this->requiresApproval,
            'approvers' => $isMember
                ? $this->approvers->map(fn (User $u) => ['id' => $u->id, 'name' => $u->name])->values()->all()
                : null,
            // Worst open damage, inline. Every screen that already holds
            // the resources store (picker, timeline, reservation form) can
            // warn about a damaged boat without fetching damages itself.
            'openDamage' => $this->presentOpenDamage(),
            'openDamageCount' => $this->openDamages->count(),
            'createdAt' => $this->createdAt?->toIso8601String(),
            'updatedAt' => $this->updatedAt?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function presentOpenDamage(): ?array
    {
        $damage = $this->resource->worstOpenDamage();
        if ($damage === null) {
            return null;
        }

        return [
            'id' => $damage->id,
            'status' => $damage->status->value,
            'severity' => $damage->severity->value,
            'description' => $damage->description,
            'reportedAt' => $damage->reportedAt?->toIso8601String(),
        ];
    }
}
