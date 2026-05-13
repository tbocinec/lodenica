import { Injectable } from '@nestjs/common';

import { NotFoundDomainError } from '../../../common/errors/domain.errors';
import { Reservation } from '../../reservations/domain/reservation.entity';
import { ReservationsService } from '../../reservations/application/reservations.service';
import { TimeRange } from '../../reservations/domain/time-range.value';
import { Event, EventParticipant } from '../domain/event.entity';
import {
  EventListOptions,
  EventRepository,
} from '../domain/event.repository';

export interface CreateEventCommand {
  title: string;
  description?: string | null;
  location?: string | null;
  startsAt: string | Date;
  endsAt: string | Date;
}

export interface UpdateEventCommand {
  title?: string;
  description?: string | null;
  location?: string | null;
  startsAt?: string | Date;
  endsAt?: string | Date;
}

export interface AddParticipantCommand {
  name: string;
  contact?: string | null;
  note?: string | null;
}

@Injectable()
export class EventsService {
  constructor(
    private readonly events: EventRepository,
    private readonly reservations: ReservationsService,
  ) {}

  async attachResources(eventId: string, resourceIds: string[]): Promise<Reservation[]> {
    const event = await this.requireExisting(eventId);
    const { startsAt, endsAt, title } = event.toJSON();
    const created: Reservation[] = [];
    // Sequential to surface clear per-resource conflicts; the DB exclusion
    // constraint is still the ultimate safeguard against races.
    for (const resourceId of resourceIds) {
      const reservation = await this.reservations.create({
        resourceId,
        eventId,
        customerName: title,
        startsAt,
        endsAt,
      });
      created.push(reservation);
    }
    return created;
  }

  async create(cmd: CreateEventCommand): Promise<Event> {
    const range = TimeRange.fromInstants(cmd.startsAt, cmd.endsAt);
    return this.events.create({
      title: cmd.title,
      description: cmd.description ?? null,
      location: cmd.location ?? null,
      startsAt: range.startsAt,
      endsAt: range.endsAt,
    });
  }

  async update(id: string, cmd: UpdateEventCommand): Promise<Event> {
    const existing = await this.requireExisting(id);

    let startsAt: Date | undefined;
    let endsAt: Date | undefined;
    if (cmd.startsAt !== undefined || cmd.endsAt !== undefined) {
      const range = TimeRange.fromInstants(
        cmd.startsAt ?? existing.toJSON().startsAt,
        cmd.endsAt ?? existing.toJSON().endsAt,
      );
      startsAt = range.startsAt;
      endsAt = range.endsAt;
    }

    return this.events.update(id, {
      title: cmd.title,
      description: cmd.description,
      location: cmd.location,
      startsAt,
      endsAt,
    });
  }

  async remove(id: string): Promise<void> {
    await this.requireExisting(id);
    await this.events.delete(id);
  }

  async findById(id: string): Promise<Event> {
    return this.requireExisting(id);
  }

  list(options: EventListOptions): Promise<{ items: Event[]; total: number }> {
    return this.events.list(options);
  }

  async addParticipant(eventId: string, cmd: AddParticipantCommand): Promise<EventParticipant> {
    await this.requireExisting(eventId);
    return this.events.addParticipant({
      eventId,
      name: cmd.name,
      contact: cmd.contact ?? null,
      note: cmd.note ?? null,
    });
  }

  async removeParticipant(eventId: string, participantId: string): Promise<void> {
    await this.requireExisting(eventId);
    const participant = await this.events.findParticipant(participantId);
    if (!participant || participant.eventId !== eventId) {
      throw new NotFoundDomainError('EventParticipant', participantId);
    }
    await this.events.removeParticipant(participantId);
  }

  listParticipants(eventId: string): Promise<EventParticipant[]> {
    return this.events.listParticipants(eventId);
  }

  private async requireExisting(id: string): Promise<Event> {
    const found = await this.events.findById(id);
    if (!found) {
      throw new NotFoundDomainError('Event', id);
    }
    return found;
  }
}
