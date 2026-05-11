import { ReservationStatus } from '@prisma/client';

import { Reservation } from './reservation.entity';
import { TimeRange } from './time-range.value';

export interface ReservationFilter {
  resourceId?: string;
  eventId?: string;
  status?: ReservationStatus;
  range?: TimeRange;
}

export interface ReservationListOptions extends ReservationFilter {
  skip?: number;
  take?: number;
}

export interface ReservationCreateInput {
  resourceId: string;
  eventId?: string | null;
  customerName: string;
  customerContact?: string | null;
  startsAt: Date;
  endsAt: Date;
  note?: string | null;
  status?: ReservationStatus;
}

export interface ReservationUpdateInput {
  customerName?: string;
  customerContact?: string | null;
  eventId?: string | null;
  startsAt?: Date;
  endsAt?: Date;
  note?: string | null;
  status?: ReservationStatus;
}

export abstract class ReservationRepository {
  abstract create(input: ReservationCreateInput): Promise<Reservation>;
  abstract update(id: string, input: ReservationUpdateInput): Promise<Reservation>;
  abstract delete(id: string): Promise<void>;
  abstract findById(id: string): Promise<Reservation | null>;
  abstract list(options: ReservationListOptions): Promise<{ items: Reservation[]; total: number }>;
  abstract findOverlapping(
    resourceId: string,
    range: TimeRange,
    excludeReservationId?: string,
  ): Promise<Reservation[]>;
}
