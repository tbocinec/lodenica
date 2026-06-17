<?php

namespace App\Http\Resources;

use App\Models\Resource as ResourceModel;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ResourceModel
 */
class ResourceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
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
            'createdAt' => $this->createdAt?->toIso8601String(),
            'updatedAt' => $this->updatedAt?->toIso8601String(),
        ];
    }
}
