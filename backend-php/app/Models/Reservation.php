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
        'decidedAt' => 'datetime',
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

    /** The approver who confirmed or rejected the request (null while waiting). */
    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decidedById');
    }

    public function range(): TimeRange
    {
        return TimeRange::fromInstants($this->startsAt, $this->endsAt);
    }

    public function isPendingApproval(): bool
    {
        return $this->status === ReservationStatus::PENDING_APPROVAL;
    }

    public function blocksSlot(): bool
    {
        return $this->status->blocksSlot();
    }

    /**
     * "2026-09-10 09:00 – 2026-09-10 12:00" in the wall-clock UTC convention:
     * what the user typed is what we display. Shared by audit summaries and
     * e-mails so they never drift apart.
     */
    public function rangeLabel(): string
    {
        $start = $this->startsAt instanceof \DateTimeInterface ? $this->startsAt : new \DateTimeImmutable((string) $this->startsAt);
        $end = $this->endsAt instanceof \DateTimeInterface ? $this->endsAt : new \DateTimeImmutable((string) $this->endsAt);

        return $start->format('Y-m-d H:i').' – '.$end->format('Y-m-d H:i');
    }
}
