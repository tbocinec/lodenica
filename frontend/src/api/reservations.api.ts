import { http } from './http';
import type { Paginated, Reservation, ReservationStatus } from './types';

/**
 * Absolute URL of the .ics endpoint for a reservation — used directly
 * as `<a :href=…>` so the browser handles download + hand-off to the
 * mobile calendar app (iOS Calendar / Google Calendar / Outlook).
 */
export function reservationIcsUrl(id: string): string {
  const base = (import.meta.env.VITE_API_BASE_URL ?? '/api/v1').replace(/\/+$/, '');
  return `${base}/reservations/${id}/ics`;
}

export interface ListReservationsParams {
  page?: number;
  pageSize?: number;
  resourceId?: string;
  eventId?: string;
  status?: ReservationStatus;
  /** ISO datetime — list reservations overlapping [from, to). */
  from?: string;
  to?: string;
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
};

export const reservationsApi = {
  async list(params: ListReservationsParams = {}): Promise<Paginated<Reservation>> {
    const { data } = await http.get<Paginated<Reservation>>('/reservations', { params });
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
