import { InvalidDateRangeError } from '../../../common/errors/domain.errors';
import { TimeRange } from './time-range.value';

const at = (s: string) => new Date(s);

describe('TimeRange', () => {
  describe('fromInstants', () => {
    it('accepts valid range', () => {
      const r = TimeRange.fromInstants(at('2026-05-09T09:00:00Z'), at('2026-05-09T12:00:00Z'));
      expect(r.durationHours()).toBe(3);
    });

    it('rejects end equal to start (zero-length range)', () => {
      expect(() =>
        TimeRange.fromInstants(at('2026-05-09T09:00:00Z'), at('2026-05-09T09:00:00Z')),
      ).toThrow(InvalidDateRangeError);
    });

    it('rejects end before start', () => {
      expect(() =>
        TimeRange.fromInstants(at('2026-05-09T12:00:00Z'), at('2026-05-09T09:00:00Z')),
      ).toThrow(InvalidDateRangeError);
    });

    it('multi-day range works the same way', () => {
      const r = TimeRange.fromInstants(at('2026-05-09T08:00:00Z'), at('2026-05-11T18:00:00Z'));
      expect(r.durationHours()).toBe(58);
    });
  });

  describe('overlaps (half-open)', () => {
    const a = TimeRange.fromInstants(at('2026-05-09T09:00:00Z'), at('2026-05-09T12:00:00Z'));

    it('identical ranges overlap', () => {
      expect(
        a.overlaps(TimeRange.fromInstants(at('2026-05-09T09:00:00Z'), at('2026-05-09T12:00:00Z'))),
      ).toBe(true);
    });

    it('back-to-back same-instant handover does NOT overlap', () => {
      expect(
        a.overlaps(TimeRange.fromInstants(at('2026-05-09T12:00:00Z'), at('2026-05-09T15:00:00Z'))),
      ).toBe(false);
      expect(
        a.overlaps(TimeRange.fromInstants(at('2026-05-09T06:00:00Z'), at('2026-05-09T09:00:00Z'))),
      ).toBe(false);
    });

    it('partial overlap at start detected', () => {
      expect(
        a.overlaps(TimeRange.fromInstants(at('2026-05-09T08:00:00Z'), at('2026-05-09T10:00:00Z'))),
      ).toBe(true);
    });

    it('partial overlap at end detected', () => {
      expect(
        a.overlaps(TimeRange.fromInstants(at('2026-05-09T11:00:00Z'), at('2026-05-09T13:00:00Z'))),
      ).toBe(true);
    });

    it('full containment detected', () => {
      expect(
        a.overlaps(TimeRange.fromInstants(at('2026-05-09T00:00:00Z'), at('2026-05-09T23:59:59Z'))),
      ).toBe(true);
    });

    it('multi-day overlapping a one-hour booking on a covered day', () => {
      const multiDay = TimeRange.fromInstants(
        at('2026-05-09T00:00:00Z'),
        at('2026-05-12T00:00:00Z'),
      );
      const hourly = TimeRange.fromInstants(
        at('2026-05-10T15:00:00Z'),
        at('2026-05-10T17:00:00Z'),
      );
      expect(multiDay.overlaps(hourly)).toBe(true);
    });

    it('two adjacent multi-day ranges (handover) do NOT overlap', () => {
      const a1 = TimeRange.fromInstants(at('2026-05-09T00:00:00Z'), at('2026-05-12T00:00:00Z'));
      const b1 = TimeRange.fromInstants(at('2026-05-12T00:00:00Z'), at('2026-05-15T00:00:00Z'));
      expect(a1.overlaps(b1)).toBe(false);
    });
  });
});
