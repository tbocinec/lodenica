<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EventParticipant extends Model
{
    use HasUuids;

    protected $table = 'event_participants';

    public const CREATED_AT = 'createdAt';
    /** No updatedAt column — participants are immutable once created. */
    public const UPDATED_AT = null;

    public $timestamps = true;

    protected $guarded = ['id', 'createdAt'];

    protected $casts = [
        'createdAt' => 'datetime',
    ];

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class, 'eventId');
    }
}
