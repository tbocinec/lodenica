import { Injectable } from '@nestjs/common';
import { ReservationStatus } from '@prisma/client';

import {
  InactiveResourceError,
  NotFoundDomainError,
  ReservationOverlapError,
} from '../../../common/errors/domain.errors';
import { ResourceRepository } from '../../resources/domain/resource.repository';
import { Reservation } from '../domain/reservation.entity';
import {
  ReservationCreateInput,
  ReservationListOptions,
  ReservationRepository,
  ReservationUpdateInput,
} from '../domain/reservation.repository';
import { TimeRange } from '../domain/time-range.value';

export interface CreateReservationCommand {
  resourceId: string;
  eventId?: string | null;
  customerName: string;
  customerContact?: string | null;
  startsAt: string | Date;
  endsAt: string | Date;
  note?: string | null;
}

export interface UpdateReservationCommand {
  customerName?: string;
  customerContact?: string | null;
  eventId?: string | null;
  startsAt?: string | Date;
  endsAt?: string | Date;
  note?: string | null;
  status?: ReservationStatus;
}

/**
 * Reservation lifecycle. Overlap is checked at the application layer for
 * clear validation messages; the database also enforces it via a GIST
 * exclusion constraint, which acts as the safety net for race conditions.
 *
 * Every reservation is `[startsAt, endsAt)` — a single uniform shape.
 * "All-day" or multi-day reservations are just longer ranges (e.g. midnight
 * to midnight); the model does not distinguish them.
 */
@Injectable()
export class ReservationsService {
  constructor(
    private readonly reservations: ReservationRepository,
    private readonly resources: ResourceRepository,
  ) {}

  async create(cmd: CreateReservationCommand): Promise<Reservation> {
    const range = TimeRange.fromInstants(cmd.startsAt, cmd.endsAt);

    const resource = await this.resources.findById(cmd.resourceId);
    if (!resource) {
      throw new NotFoundDomainError('Resource', cmd.resourceId);
    }
    if (!resource.isActive) {
      throw new InactiveResourceError(resource.id);
    }

    await this.assertNoOverlap(cmd.resourceId, range);

    return this.reservations.create({
      resourceId: cmd.resourceId,
      eventId: cmd.eventId ?? null,
      customerName: cmd.customerName,
      customerContact: cmd.customerContact ?? null,
      startsAt: range.startsAt,
      endsAt: range.endsAt,
      note: cmd.note ?? null,
      status: ReservationStatus.CONFIRMED,
    });
  }

  async update(id: string, cmd: UpdateReservationCommand): Promise<Reservation> {
    const existing = await this.requireExisting(id);

    let range: TimeRange | undefined;
    if (cmd.startsAt !== undefined || cmd.endsAt !== undefined) {
      range = TimeRange.fromInstants(
        cmd.startsAt ?? existing.toJSON().startsAt,
        cmd.endsAt ?? existing.toJSON().endsAt,
      );
      const newStatus = cmd.status ?? existing.status;
      if (newStatus === ReservationStatus.CONFIRMED) {
        await this.assertNoOverlap(existing.resourceId, range, id);
      }
    }

    const update: ReservationUpdateInput = {
      customerName: cmd.customerName,
      customerContact: cmd.customerContact,
      eventId: cmd.eventId,
      startsAt: range?.startsAt,
      endsAt: range?.endsAt,
      note: cmd.note,
      status: cmd.status,
    };

    return this.reservations.update(id, update);
  }

  async cancel(id: string): Promise<Reservation> {
    await this.requireExisting(id);
    return this.reservations.update(id, { status: ReservationStatus.CANCELLED });
  }

  async remove(id: string): Promise<void> {
    await this.requireExisting(id);
    await this.reservations.delete(id);
  }

  async findById(id: string): Promise<Reservation> {
    return this.requireExisting(id);
  }

  list(options: ReservationListOptions): Promise<{ items: Reservation[]; total: number }> {
    return this.reservations.list(options);
  }

  private async requireExisting(id: string): Promise<Reservation> {
    const found = await this.reservations.findById(id);
    if (!found) {
      throw new NotFoundDomainError('Reservation', id);
    }
    return found;
  }

  private async assertNoOverlap(
    resourceId: string,
    range: TimeRange,
    excludeId?: string,
  ): Promise<void> {
    const conflicts = await this.reservations.findOverlapping(resourceId, range, excludeId);
    if (conflicts.length > 0) {
      throw new ReservationOverlapError(
        resourceId,
        conflicts.map((c) => c.id),
      );
    }
  }
}
