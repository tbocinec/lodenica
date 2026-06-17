<?php

namespace App\Models;

use App\Domain\Enums\ReservationStatus;
use App\Domain\ValueObjects\TimeRange;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Reservation extends Model
{
    use HasUuids;

    protected $table = 'reservations';

    public const CREATED_AT = 'createdAt';
    public const UPDATED_AT = 'updatedAt';

    protected $guarded = ['id', 'createdAt', 'updatedAt'];

    protected $attributes = [
        'status' => 'CONFIRMED',
    ];

    protected $casts = [
        'startsAt' => 'datetime',
        'endsAt' => 'datetime',
        'status' => ReservationStatus::class,
        'createdAt' => 'datetime',
        'updatedAt' => 'datetime',
    ];

    public function resource(): BelongsTo
    {
        return $this->belongsTo(Resource::class, 'resourceId');
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class, 'eventId');
    }

    /** The logged-in user who created this booking (null for anonymous). */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'createdById');
    }

    public function range(): TimeRange
    {
        return TimeRange::fromInstants($this->startsAt, $this->endsAt);
    }

    public function isConfirmed(): bool
    {
        return $this->status === ReservationStatus::CONFIRMED;
    }
}
