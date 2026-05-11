import { Injectable } from '@nestjs/common';
import { Prisma } from '@prisma/client';

import { PrismaService } from '../../../infrastructure/prisma/prisma.service';
import { Event, EventParticipant } from '../domain/event.entity';
import {
  EventCreateInput,
  EventListOptions,
  EventParticipantCreateInput,
  EventRepository,
  EventUpdateInput,
} from '../domain/event.repository';

@Injectable()
export class PrismaEventRepository extends EventRepository {
  constructor(private readonly prisma: PrismaService) {
    super();
  }

  async create(input: EventCreateInput): Promise<Event> {
    const row = await this.prisma.event.create({ data: input });
    return Event.fromPersistence(row);
  }

  async update(id: string, input: EventUpdateInput): Promise<Event> {
    const row = await this.prisma.event.update({ where: { id }, data: input });
    return Event.fromPersistence(row);
  }

  async delete(id: string): Promise<void> {
    await this.prisma.event.delete({ where: { id } });
  }

  async findById(id: string): Promise<Event | null> {
    const row = await this.prisma.event.findUnique({ where: { id } });
    return row ? Event.fromPersistence(row) : null;
  }

  async list(options: EventListOptions): Promise<{ items: Event[]; total: number }> {
    const where: Prisma.EventWhereInput = {};
    if (options.from !== undefined || options.to !== undefined) {
      where.AND = [];
      if (options.to !== undefined) where.AND.push({ startsAt: { lt: options.to } });
      if (options.from !== undefined) where.AND.push({ endsAt: { gt: options.from } });
    }

    const [rows, total] = await this.prisma.$transaction([
      this.prisma.event.findMany({
        where,
        orderBy: [{ startsAt: 'asc' }, { createdAt: 'asc' }],
        skip: options.skip,
        take: options.take,
      }),
      this.prisma.event.count({ where }),
    ]);

    return { items: rows.map(Event.fromPersistence), total };
  }

  async addParticipant(input: EventParticipantCreateInput): Promise<EventParticipant> {
    const row = await this.prisma.eventParticipant.create({ data: input });
    return EventParticipant.fromPersistence(row);
  }

  async removeParticipant(id: string): Promise<void> {
    await this.prisma.eventParticipant.delete({ where: { id } });
  }

  async findParticipant(id: string): Promise<EventParticipant | null> {
    const row = await this.prisma.eventParticipant.findUnique({ where: { id } });
    return row ? EventParticipant.fromPersistence(row) : null;
  }

  async listParticipants(eventId: string): Promise<EventParticipant[]> {
    const rows = await this.prisma.eventParticipant.findMany({
      where: { eventId },
      orderBy: [{ createdAt: 'asc' }],
    });
    return rows.map(EventParticipant.fromPersistence);
  }
}
