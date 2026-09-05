<?php

namespace App\Domain\Enums;

enum ReservationStatus: string
{
    case CONFIRMED = 'CONFIRMED';
    /** Booked on a resource that requires approval; waiting for an approver (REZ-052). */
    case PENDING_APPROVAL = 'PENDING_APPROVAL';
    case CANCELLED = 'CANCELLED';
    /** An approver turned the request down (REZ-055). Frees the slot like CANCELLED. */
    case REJECTED = 'REJECTED';

    /**
     * Whether a reservation in this status occupies its time slot. A request
     * awaiting approval holds the slot so approving it can never collide;
     * cancelled and rejected ones give it back. Everything that asks "is the
     * slot taken" — overlap checks, the dashboard, the DB constraint — keys
     * off this, never off CONFIRMED alone.
     */
    public function blocksSlot(): bool
    {
        return match ($this) {
            self::CONFIRMED, self::PENDING_APPROVAL => true,
            self::CANCELLED, self::REJECTED => false,
        };
    }

    /** @return list<self> */
    public static function blocking(): array
    {
        return array_values(array_filter(self::cases(), fn (self $s) => $s->blocksSlot()));
    }

    /** @return list<string> */
    public static function blockingValues(): array
    {
        return array_map(fn (self $s) => $s->value, self::blocking());
    }
}
