<?php

namespace App\Http\Resources;

use App\Models\Expedition;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Expedition
 */
class ExpeditionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $viewer = $request->user('sanctum') ?? $request->user();
        $isAdmin = $viewer instanceof User && $viewer->isAdmin();
        $canEdit = $isAdmin || ($viewer instanceof User && $viewer->id === $this->createdById);

        return [
            'id' => $this->id,
            'title' => $this->title,
            'place' => $this->place,
            'latitude' => (float) $this->latitude,
            'longitude' => (float) $this->longitude,
            'year' => $this->year,
            'waterType' => $this->waterType,
            'country' => $this->country,
            'participants' => $this->participants,
            'distanceKm' => $this->distanceKm !== null ? (float) $this->distanceKm : null,
            'route' => $this->route,
            'detail' => $this->detail,
            'createdById' => $this->createdById,
            'createdByName' => $this->relationLoaded('creator') ? $this->creator?->name : null,
            'canEdit' => (bool) $canEdit,
            'createdAt' => $this->createdAt?->toIso8601String(),
            'photos' => $this->relationLoaded('photos')
                ? $this->photos->map(fn ($p) => [
                    'id' => $p->id,
                    'url' => "/api/v1/expeditions/{$this->id}/photos/{$p->id}",
                ])->all()
                : [],
        ];
    }
}
