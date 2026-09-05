<?php

namespace App\Models;

use App\Domain\Enums\DamageSeverity;
use App\Domain\Enums\DamageStatus;
use App\Domain\Enums\ResourceType;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
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
        'requiresApproval' => false,
    ];

    protected $casts = [
        'type' => ResourceType::class,
        'seats' => 'integer',
        'lengthCm' => 'integer',
        'weightKg' => 'integer',
        'isActive' => 'boolean',
        'requiresApproval' => 'boolean',
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

    /**
     * Damages that still affect the boat — reported or being repaired.
     * Kept as its own relation so callers can eager-load just these and
     * skip the (potentially long) history of fixed ones.
     */
    public function openDamages(): HasMany
    {
        return $this->damages()->whereIn('status', [
            DamageStatus::REPORTED->value,
            DamageStatus::IN_REPAIR->value,
        ]);
    }

    /**
     * The open damage a member most needs to know about before taking the
     * boat out. Severity is an enum, so "worst" can't come from an ORDER BY
     * — rank it here and fall back to the most recently reported.
     */
    public function worstOpenDamage(): ?Damage
    {
        $rank = [
            DamageSeverity::CRITICAL->value => 3,
            DamageSeverity::MODERATE->value => 2,
            DamageSeverity::MINOR->value => 1,
        ];

        return $this->openDamages
            ->sortByDesc(fn (Damage $d) => [
                $rank[$d->severity->value] ?? 0,
                $d->reportedAt?->getTimestamp() ?? 0,
            ])
            ->first();
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

    /**
     * Members who may approve a booking of this resource (REZ-050). Admins
     * may always decide and are not listed here.
     */
    public function approvers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'resource_approvers', 'resourceId', 'userId')
            ->withPivot('createdAt');
    }

    public function isApprover(User $user): bool
    {
        return $this->approvers()->whereKey($user->id)->exists();
    }

    /** "K-1 – Kayak 1" — the form used in audit summaries and e-mails. */
    public function label(): string
    {
        return "{$this->identifier} – {$this->name}";
    }
}
