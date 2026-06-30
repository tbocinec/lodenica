<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A place a member has paddled — pinned on the world map. See the
 * create_expeditions_table migration.
 *
 * @property string $title
 * @property string $place
 * @property float $latitude
 * @property float $longitude
 * @property string|null $createdById
 */
class Expedition extends Model
{
    use HasUuids;

    protected $table = 'expeditions';

    public const CREATED_AT = 'createdAt';
    public const UPDATED_AT = 'updatedAt';

    protected $guarded = ['id', 'createdAt', 'updatedAt'];

    protected $casts = [
        'latitude' => 'float',
        'longitude' => 'float',
        'year' => 'integer',
        'distanceKm' => 'float',
        'route' => 'array',
        'publishConsent' => 'boolean',
        'createdAt' => 'datetime',
        'updatedAt' => 'datetime',
    ];

    public function photos(): HasMany
    {
        return $this->hasMany(ExpeditionPhoto::class, 'expeditionId');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'createdById');
    }
}
