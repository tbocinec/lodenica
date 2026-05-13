import { InvalidDateRangeError } from '../../../common/errors/domain.errors';

/**
 * Half-open time range `[startsAt, endsAt)`.
 *
 * Half-open semantics mean back-to-back bookings (handover at noon, next-day
 * pickup) don't conflict — the reservation that ends at 12:00 does not
 * occupy the 12:00 instant. Mirrors the `tsrange(_, _, '[)')` GIST
 * exclusion constraint in the database.
 */
export class TimeRange {
  private constructor(
    public readonly startsAt: Date,
    public readonly endsAt: Date,
  ) {}

  static fromInstants(start: Date | string, end: Date | string): TimeRange {
    const startsAt = TimeRange.toDate(start);
    const endsAt = TimeRange.toDate(end);
    if (endsAt <= startsAt) {
      throw new InvalidDateRangeError();
    }
    return new TimeRange(startsAt, endsAt);
  }

  private static toDate(input: Date | string): Date {
    const d = typeof input === 'string' ? new Date(input) : new Date(input.getTime());
    if (Number.isNaN(d.getTime())) {
      throw new InvalidDateRangeError('Neplatný dátum/čas.');
    }
    return d;
  }

  /** Half-open overlap: `start < other.end && other.start < end`. */
  overlaps(other: TimeRange): boolean {
    return this.startsAt < other.endsAt && other.startsAt < this.endsAt;
  }

  durationMs(): number {
    return this.endsAt.getTime() - this.startsAt.getTime();
  }

  durationHours(): number {
    return this.durationMs() / (1000 * 60 * 60);
  }
}
