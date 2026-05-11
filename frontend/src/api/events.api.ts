import { http } from './http';
import type { Event, EventParticipant, Paginated, Reservation } from './types';

export interface ListEventsParams {
  page?: number;
  pageSize?: number;
  /** ISO datetime — list events overlapping [from, to). */
  from?: string;
  to?: string;
}

export interface CreateEventInput {
  title: string;
  description?: string;
  location?: string;
  /** ISO 8601 datetime. */
  startsAt: string;
  /** ISO 8601 datetime, exclusive — must be strictly after startsAt. */
  endsAt: string;
}

export type UpdateEventInput = Partial<CreateEventInput>;

export interface AddParticipantInput {
  name: string;
  contact?: string;
  note?: string;
}

export const eventsApi = {
  async list(params: ListEventsParams = {}): Promise<Paginated<Event>> {
    const { data } = await http.get<Paginated<Event>>('/events', { params });
    return data;
  },
  async get(id: string): Promise<Event> {
    const { data } = await http.get<Event>(`/events/${id}`);
    return data;
  },
  async create(input: CreateEventInput): Promise<Event> {
    const { data } = await http.post<Event>('/events', input);
    return data;
  },
  async update(id: string, input: UpdateEventInput): Promise<Event> {
    const { data } = await http.patch<Event>(`/events/${id}`, input);
    return data;
  },
  async remove(id: string): Promise<void> {
    await http.delete(`/events/${id}`);
  },
  async listParticipants(eventId: string): Promise<EventParticipant[]> {
    const { data } = await http.get<EventParticipant[]>(`/events/${eventId}/participants`);
    return data;
  },
  async addParticipant(eventId: string, input: AddParticipantInput): Promise<EventParticipant> {
    const { data } = await http.post<EventParticipant>(`/events/${eventId}/participants`, input);
    return data;
  },
  async removeParticipant(eventId: string, participantId: string): Promise<void> {
    await http.delete(`/events/${eventId}/participants/${participantId}`);
  },
  async attachResources(eventId: string, resourceIds: string[]): Promise<Reservation[]> {
    const { data } = await http.post<Reservation[]>(`/events/${eventId}/reservations`, {
      resourceIds,
    });
    return data;
  },
};
