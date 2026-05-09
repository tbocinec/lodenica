import { addDays, format, isValid, parseISO } from 'date-fns';
import { sk } from 'date-fns/locale';

/**
 * The backend stores wall-clock times in TIMESTAMP (no timezone). We send/
 * receive them as ISO strings with `Z` for valid syntax, but interpret the
 * UTC components as the literal wall-clock value (so 09:00 picked in the
 * form stays 09:00 forever, regardless of browser timezone).
 *
 * All formatters here therefore read UTC components, never local.
 */

function toDate(input: string | Date | null | undefined): Date | null {
  if (!input) return null;
  const d = typeof input === 'string' ? parseISO(input) : input;
  return isValid(d) ? d : null;
}

function pad(n: number): string {
  return String(n).padStart(2, '0');
}

const DAY_LABELS = ['ne', 'po', 'ut', 'st', 'št', 'pi', 'so'];
const MONTH_LABELS = [
  'jan', 'feb', 'mar', 'apr', 'máj', 'jún',
  'júl', 'aug', 'sep', 'okt', 'nov', 'dec',
];

export function formatDate(input: string | Date | null | undefined): string {
  const d = toDate(input);
  if (!d) return '—';
  return `${pad(d.getUTCDate())}.${pad(d.getUTCMonth() + 1)}.${d.getUTCFullYear()}`;
}

export function formatTime(input: string | Date | null | undefined): string {
  const d = toDate(input);
  if (!d) return '—';
  return `${pad(d.getUTCHours())}:${pad(d.getUTCMinutes())}`;
}

export function formatDateTime(input: string | Date | null | undefined): string {
  const d = toDate(input);
  if (!d) return '—';
  return `${formatDate(d)} ${formatTime(d)}`;
}

/**
 * Format a reservation range for display. Detects "whole-day" patterns
 * (start & end on midnight) and renders them without redundant 00:00.
 *
 * - Same-day hourly:           "09.05.2026 09:00–12:00"
 * - Same-day full (00–24:00):  "09.05.2026 (celý deň)"
 * - Multi-day full days:       "09.05.2026 – 11.05.2026"
 * - Multi-day with times:      "09.05.2026 18:00 – 11.05.2026 12:00"
 */
export function formatReservationRange(
  startsAt: string | Date,
  endsAt: string | Date,
): string {
  const start = toDate(startsAt);
  const end = toDate(endsAt);
  if (!start || !end) return '—';

  const startsAtMidnight = isUtcMidnight(start);
  const endsAtMidnight = isUtcMidnight(end);

  if (startsAtMidnight && endsAtMidnight) {
    // Whole-day(s) booking. endsAt is exclusive.
    const inclusiveEnd = new Date(end.getTime() - 24 * 60 * 60 * 1000);
    if (sameUtcDay(start, inclusiveEnd)) {
      return `${formatDate(start)} (celý deň)`;
    }
    return `${formatDate(start)} – ${formatDate(inclusiveEnd)}`;
  }

  if (sameUtcDay(start, end) || sameUtcDay(start, new Date(end.getTime() - 1))) {
    return `${formatDate(start)} ${formatTime(start)}–${formatTime(end)}`;
  }
  return `${formatDate(start)} ${formatTime(start)} – ${formatDate(end)} ${formatTime(end)}`;
}

function isUtcMidnight(d: Date): boolean {
  return d.getUTCHours() === 0 && d.getUTCMinutes() === 0 && d.getUTCSeconds() === 0;
}

export function sameUtcDay(a: Date, b: Date): boolean {
  return (
    a.getUTCFullYear() === b.getUTCFullYear() &&
    a.getUTCMonth() === b.getUTCMonth() &&
    a.getUTCDate() === b.getUTCDate()
  );
}

/** Build an ISO datetime string in wall-clock UTC from yyyy-MM-dd + HH:mm. */
export function isoFromDateTime(date: string, time: string): string {
  return `${date}T${time}:00.000Z`;
}

/** yyyy-MM-dd in UTC. */
export function toIsoDate(date: Date): string {
  return `${date.getUTCFullYear()}-${pad(date.getUTCMonth() + 1)}-${pad(date.getUTCDate())}`;
}

/** Wall-clock UTC midnight for a yyyy-MM-dd string. */
export function utcMidnight(dateIso: string): Date {
  return new Date(`${dateIso}T00:00:00.000Z`);
}

/** Day-of-week label (po, ut, …) — Slovak short. */
export function dayLabel(date: Date): string {
  return DAY_LABELS[date.getUTCDay()] ?? '';
}

/** Short date "9. máj" for headers. */
export function shortDate(date: Date): string {
  return `${date.getUTCDate()}. ${MONTH_LABELS[date.getUTCMonth()]}`;
}

/** Today (UTC midnight) — single source of truth for views. */
export function todayUtc(): Date {
  const now = new Date();
  return new Date(Date.UTC(now.getUTCFullYear(), now.getUTCMonth(), now.getUTCDate()));
}

/** Add days to a UTC date (returns new Date). */
export function addDaysUtc(date: Date, days: number): Date {
  return addDays(date, days);
}

/** Re-export date-fns format with sk locale for callers that need patterns. */
export function localFormat(date: Date, pattern: string): string {
  return format(date, pattern, { locale: sk });
}
