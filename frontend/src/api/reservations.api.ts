import { http } from './http';
import type { Paginated, Reservation, ReservationStatus } from './types';

/**
 * Absolute URL of the .ics endpoint for a reservation — used directly
 * as `<a :href=…>` so the browser handles download + hand-off to the
 * native calendar app (iOS Calendar opens the .ics inline; Outlook /
 * Thunderbird import on click).
 */
export function reservationIcsUrl(id: string): string {
  const base = (import.meta.env.VITE_API_BASE_URL ?? '/api/v1').replace(/\/+$/, '');
  return `${base}/reservations/${id}/ics`;
}

/**
 * Builds a Google Calendar "render template" URL that pre-fills a new
 * event in the user's Google Calendar (web app on desktop, opens the
 * Google Calendar app on Android if installed via Universal Links).
 *
 * Format: https://www.google.com/calendar/render?action=TEMPLATE
 *         &text=<title>&dates=YYYYMMDDTHHMMSSZ/YYYYMMDDTHHMMSSZ
 *         &details=<description>&location=<location>
 *
 * `dates` requires the compact ICS-style UTC format with no separators.
 */
const KVS_LOCATION =
  'Klub vodných športov Karlova Ves, Botanická 20/59, 841 04 Bratislava-Karlova Ves, Slovakia';
const KVS_MAPS_URL = 'https://maps.app.goo.gl/zZwKA168QCeugSxA8';

export function reservationGoogleCalendarUrl(opts: {
  title: string;
  /** ISO-8601 datetime, UTC. */
  startsAt: string;
  /** ISO-8601 datetime, UTC. */
  endsAt: string;
  customerName: string;
  resourceLabel?: string;
  note?: string | null;
}): string {
  // ISO 8601 (2099-09-15T08:00:00.000Z) → 20990915T080000Z
  const toCompactUtc = (iso: string): string =>
    new Date(iso).toISOString().replace(/[-:]/g, '').replace(/\.\d{3}/, '');

  // Description: Maps URL first (tappable in Google Calendar), then the
  // reservation details. \n becomes a soft line break in the GCal UI.
  const description = [
    KVS_MAPS_URL,
    `Rezervácia pre: ${opts.customerName}`,
    opts.resourceLabel ? `Zdroj: ${opts.resourceLabel}` : null,
    opts.note ? `Poznámka: ${opts.note}` : null,
  ]
    .filter(Boolean)
    .join('\n');

  const params = new URLSearchParams({
    action: 'TEMPLATE',
    text: opts.title,
    dates: `${toCompactUtc(opts.startsAt)}/${toCompactUtc(opts.endsAt)}`,
    details: description,
    location: KVS_LOCATION,
  });
  return `https://www.google.com/calendar/render?${params.toString()}`;
}

export interface ListReservationsParams {
  page?: number;
  pageSize?: number;
  resourceId?: string;
  eventId?: string;
  status?: ReservationStatus;
  /**
   * ISO datetime. When BOTH from + to are set, the backend treats the
   * pair as an overlap window (existing behaviour). When only one is
   * set, it's a half-open bound — handy for "future only" (`from=now`,
   * no upper) or "older than" (just `to`).
   */
  from?: string;
  to?: string;
  /** Free-text match against customerName / customerContact / note. */
  search?: string;
  /**
   * Narrow the list to the caller's own bookings (created by them, or
   * tagged with their internal member ID). Needs a bearer token —
   * without one the backend returns an empty page, not everybody's.
   */
  mine?: boolean;
}

export interface CreateReservationInput {
  resourceId: string;
  eventId?: string;
  customerName: string;
  customerContact?: string;
  /** ISO 8601 datetime. */
  startsAt: string;
  /** ISO 8601 datetime, exclusive — must be strictly after startsAt. */
  endsAt: string;
  note?: string;
}

export type UpdateReservationInput = Partial<Omit<CreateReservationInput, 'resourceId'>> & {
  status?: ReservationStatus;
  /** Admin-only: reassign the reservation to a member (internal member ID). */
  memberId?: string | null;
};

export const reservationsApi = {
  async list(params: ListReservationsParams = {}): Promise<Paginated<Reservation>> {
    const { data } = await http.get<Paginated<Reservation>>('/reservations', { params });
    return data;
  },
  /** The logged-in user's own bookings, newest first. */
  async mine(params: { page?: number; pageSize?: number } = {}): Promise<Paginated<Reservation>> {
    const { data } = await http.get<Paginated<Reservation>>('/reservations/mine', { params });
    return data;
  },
  async get(id: string): Promise<Reservation> {
    const { data } = await http.get<Reservation>(`/reservations/${id}`);
    return data;
  },
  async create(input: CreateReservationInput): Promise<Reservation> {
    const { data } = await http.post<Reservation>('/reservations', input);
    return data;
  },
  async update(id: string, input: UpdateReservationInput): Promise<Reservation> {
    const { data } = await http.patch<Reservation>(`/reservations/${id}`, input);
    return data;
  },
  async cancel(id: string): Promise<Reservation> {
    const { data } = await http.patch<Reservation>(`/reservations/${id}/cancel`);
    return data;
  },
  async remove(id: string): Promise<void> {
    await http.delete(`/reservations/${id}`);
  },
};
