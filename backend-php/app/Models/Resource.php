<?php

namespace App\Models;

use App\Domain\Enums\ResourceType;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Single resources table backing every reservable asset — boats, trailers
 * and boathouse spaces. Type-specific fields (seats, length, weight) are
 * nullable; spaces and trailers simply don't populate them.
 */
class Resource extends Model
{
    use HasUuids;

    protected $table = 'resources';

    public const CREATED_AT = 'createdAt';
    public const UPDATED_AT = 'updatedAt';

    protected $guarded = ['id', 'createdAt', 'updatedAt'];

    /**
     * Default attribute values. Mirrors the DB-level defaults — Eloquent
     * doesn't read them back after insert, so we set them in PHP.
     */
    protected $attributes = [
        'isActive' => true,
    ];

    protected $casts = [
        'type' => ResourceType::class,
        'seats' => 'integer',
        'lengthCm' => 'integer',
        'weightKg' => 'integer',
        'isActive' => 'boolean',
        'createdAt' => 'datetime',
        'updatedAt' => 'datetime',
    ];

    public function reservations(): HasMany
    {
        return $this->hasMany(Reservation::class, 'resourceId');
    }

    public function damages(): HasMany
    {
        return $this->hasMany(Damage::class, 'resourceId');
    }

    public function isBoat(): bool
    {
        return in_array($this->type, [
            ResourceType::KAYAK,
            ResourceType::SEA_KAYAK,
            ResourceType::WW_KAYAK,
            ResourceType::CANOE,
            ResourceType::ROWING_BOAT,
            ResourceType::INFLATABLE_BOAT,
        ], true);
    }

    public function isBoathouseSpace(): bool
    {
        return $this->type === ResourceType::BOATHOUSE_SPACE;
    }
}
