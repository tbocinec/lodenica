<?php

namespace App\Domain\ValueObjects;

use App\Exceptions\InvalidDateRangeException;
use DateTimeImmutable;
use DateTimeInterface;

/**
 * Half-open time range `[startsAt, endsAt)`.
 *
 * Half-open semantics mean back-to-back bookings (handover at noon, next-day
 * pickup) don't conflict — the reservation that ends at 12:00 does not
 * occupy the 12:00 instant. Mirrors the `tsrange(_, _, '[)')` GIST
 * exclusion constraint in the database.
 */
final class TimeRange
{
    private function __construct(
        public readonly DateTimeImmutable $startsAt,
        public readonly DateTimeImmutable $endsAt,
    ) {}

    public static function fromInstants(DateTimeInterface|string $start, DateTimeInterface|string $end): self
    {
        $startsAt = self::toDateTime($start);
        $endsAt = self::toDateTime($end);

        if ($endsAt <= $startsAt) {
            throw new InvalidDateRangeException();
        }

        return new self($startsAt, $endsAt);
    }

    private static function toDateTime(DateTimeInterface|string $input): DateTimeImmutable
    {
        if ($input instanceof DateTimeImmutable) {
            return $input;
        }

        if ($input instanceof DateTimeInterface) {
            return DateTimeImmutable::createFromInterface($input);
        }

        try {
            return new DateTimeImmutable($input);
        } catch (\Throwable) {
            throw new InvalidDateRangeException('Neplatný dátum/čas.');
        }
    }

    /** Half-open overlap: `start < other.end && other.start < end`. */
    public function overlaps(TimeRange $other): bool
    {
        return $this->startsAt < $other->endsAt && $other->startsAt < $this->endsAt;
    }

    public function durationSeconds(): int
    {
        return $this->endsAt->getTimestamp() - $this->startsAt->getTimestamp();
    }

    public function durationHours(): float
    {
        return $this->durationSeconds() / 3600;
    }
}
