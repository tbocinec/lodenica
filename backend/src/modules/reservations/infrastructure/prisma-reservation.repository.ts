import { Injectable } from '@nestjs/common';
import { Prisma, ReservationStatus } from '@prisma/client';

import { PrismaService } from '../../../infrastructure/prisma/prisma.service';
import { Reservation } from '../domain/reservation.entity';
import {
  ReservationCreateInput,
  ReservationListOptions,
  ReservationRepository,
  ReservationUpdateInput,
} from '../domain/reservation.repository';
import { TimeRange } from '../domain/time-range.value';

@Injectable()
export class PrismaReservationRepository extends ReservationRepository {
  constructor(private readonly prisma: PrismaService) {
    super();
  }

  async create(input: ReservationCreateInput): Promise<Reservation> {
    const row = await this.prisma.reservation.create({ data: input });
    return Reservation.fromPersistence(row);
  }

  async update(id: string, input: ReservationUpdateInput): Promise<Reservation> {
    const row = await this.prisma.reservation.update({ where: { id }, data: input });
    return Reservation.fromPersistence(row);
  }

  async delete(id: string): Promise<void> {
    await this.prisma.reservation.delete({ where: { id } });
  }

  async findById(id: string): Promise<Reservation | null> {
    const row = await this.prisma.reservation.findUnique({ where: { id } });
    return row ? Reservation.fromPersistence(row) : null;
  }

  async list(options: ReservationListOptions): Promise<{ items: Reservation[]; total: number }> {
    const where: Prisma.ReservationWhereInput = {};
    if (options.resourceId) where.resourceId = options.resourceId;
    if (options.status) where.status = options.status;
    if (options.range) {
      // Half-open overlap: existing.startsAt < range.endsAt AND existing.endsAt > range.startsAt
      where.AND = [
        { startsAt: { lt: options.range.endsAt } },
        { endsAt: { gt: options.range.startsAt } },
      ];
    }

    const [rows, total] = await this.prisma.$transaction([
      this.prisma.reservation.findMany({
        where,
        orderBy: [{ startsAt: 'asc' }, { createdAt: 'asc' }],
        skip: options.skip,
        take: options.take,
      }),
      this.prisma.reservation.count({ where }),
    ]);

    return { items: rows.map(Reservation.fromPersistence), total };
  }

  async findOverlapping(
    resourceId: string,
    range: TimeRange,
    excludeReservationId?: string,
  ): Promise<Reservation[]> {
    const where: Prisma.ReservationWhereInput = {
      resourceId,
      status: ReservationStatus.CONFIRMED,
      AND: [{ startsAt: { lt: range.endsAt } }, { endsAt: { gt: range.startsAt } }],
    };
    if (excludeReservationId) {
      where.NOT = { id: excludeReservationId };
    }
    const rows = await this.prisma.reservation.findMany({ where });
    return rows.map(Reservation.fromPersistence);
  }
}
