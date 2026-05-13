<?php

namespace App\Models;

use App\Domain\Enums\DamageSeverity;
use App\Domain\Enums\DamageStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Damage extends Model
{
    use HasUuids;

    protected $table = 'damages';

    public const CREATED_AT = 'createdAt';
    public const UPDATED_AT = 'updatedAt';

    protected $guarded = ['id', 'createdAt', 'updatedAt'];

    protected $attributes = [
        'status' => 'REPORTED',
    ];

    protected $casts = [
        'severity' => DamageSeverity::class,
        'status' => DamageStatus::class,
        'reportedAt' => 'datetime',
        'fixedAt' => 'datetime',
        'createdAt' => 'datetime',
        'updatedAt' => 'datetime',
    ];

    public function resource(): BelongsTo
    {
        return $this->belongsTo(Resource::class, 'resourceId');
    }
}
