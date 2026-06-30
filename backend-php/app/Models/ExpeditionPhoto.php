<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One photo in an expedition's gallery. No updated timestamp — only
 * `createdAt`. The file path points inside storage/app/.
 *
 * @property string $expeditionId
 * @property string $path
 */
class ExpeditionPhoto extends Model
{
    use HasUuids;

    protected $table = 'expedition_photos';

    public $timestamps = false;

    protected $guarded = ['id'];

    protected $casts = [
        'createdAt' => 'datetime',
    ];

    public function expedition(): BelongsTo
    {
        return $this->belongsTo(Expedition::class, 'expeditionId');
    }
}
