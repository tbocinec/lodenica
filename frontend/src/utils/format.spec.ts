import { describe, expect, it } from 'vitest';

import {
  formatDate,
  formatReservationRange,
  formatTime,
  isoFromDateTime,
  toIsoDate,
} from './format';

describe('format utils', () => {
  it('formatDate handles iso strings as wall-clock UTC', () => {
    expect(formatDate('2026-05-09T00:00:00Z')).toBe('09.05.2026');
  });

  it('formatDate returns dash on null', () => {
    expect(formatDate(null)).toBe('—');
  });

  it('formatTime returns HH:mm in UTC', () => {
    expect(formatTime('2026-05-09T09:30:00Z')).toBe('09:30');
  });

  it('formatReservationRange — single-day midnight to next midnight', () => {
    expect(formatReservationRange('2026-05-09T00:00:00Z', '2026-05-10T00:00:00Z')).toBe(
      '09.05.2026 (celý deň)',
    );
  });

  it('formatReservationRange — multi-day midnights strip exclusive end day', () => {
    expect(formatReservationRange('2026-05-09T00:00:00Z', '2026-05-12T00:00:00Z')).toBe(
      '09.05.2026 – 11.05.2026',
    );
  });

  it('formatReservationRange — same-day hourly window', () => {
    expect(formatReservationRange('2026-05-09T09:00:00Z', '2026-05-09T12:00:00Z')).toBe(
      '09.05.2026 09:00–12:00',
    );
  });

  it('formatReservationRange — multi-day with explicit times', () => {
    expect(formatReservationRange('2026-05-09T18:00:00Z', '2026-05-11T08:00:00Z')).toBe(
      '09.05.2026 18:00 – 11.05.2026 08:00',
    );
  });

  it('toIsoDate produces yyyy-MM-dd in UTC', () => {
    expect(toIsoDate(new Date('2026-05-09T00:00:00Z'))).toBe('2026-05-09');
  });

  it('isoFromDateTime composes a wall-clock UTC ISO', () => {
    expect(isoFromDateTime('2026-05-09', '09:00')).toBe('2026-05-09T09:00:00.000Z');
  });
});
