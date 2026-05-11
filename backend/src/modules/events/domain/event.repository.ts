import { Event, EventParticipant } from './event.entity';

export interface EventListOptions {
  from?: Date;
  to?: Date;
  skip?: number;
  take?: number;
}

export interface EventCreateInput {
  title: string;
  description?: string | null;
  location?: string | null;
  startsAt: Date;
  endsAt: Date;
}

export interface EventUpdateInput {
  title?: string;
  description?: string | null;
  location?: string | null;
  startsAt?: Date;
  endsAt?: Date;
}

export interface EventParticipantCreateInput {
  eventId: string;
  name: string;
  contact?: string | null;
  note?: string | null;
}

export abstract class EventRepository {
  abstract create(input: EventCreateInput): Promise<Event>;
  abstract update(id: string, input: EventUpdateInput): Promise<Event>;
  abstract delete(id: string): Promise<void>;
  abstract findById(id: string): Promise<Event | null>;
  abstract list(options: EventListOptions): Promise<{ items: Event[]; total: number }>;

  abstract addParticipant(input: EventParticipantCreateInput): Promise<EventParticipant>;
  abstract removeParticipant(id: string): Promise<void>;
  abstract findParticipant(id: string): Promise<EventParticipant | null>;
  abstract listParticipants(eventId: string): Promise<EventParticipant[]>;
}
