<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DamageComment extends Model
{
    use HasUuids;

    protected $table = 'damage_comments';

    public const CREATED_AT = 'createdAt';
    public const UPDATED_AT = 'updatedAt';

    protected $guarded = ['id', 'createdAt', 'updatedAt'];

    protected $casts = [
        'createdAt' => 'datetime',
        'updatedAt' => 'datetime',
    ];

    public function damage(): BelongsTo
    {
        return $this->belongsTo(Damage::class, 'damageId');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'authorId');
    }
}
