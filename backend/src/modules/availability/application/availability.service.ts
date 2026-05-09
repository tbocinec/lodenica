import { Injectable } from '@nestjs/common';
import {
  DamageStatus,
  Reservation,
  ReservationStatus,
  Resource,
  ResourceType,
} from '@prisma/client';
import { addDays, startOfDay } from 'date-fns';

import { PrismaService } from '../../../infrastructure/prisma/prisma.service';

export interface DashboardSnapshot {
  generatedAt: Date;
  today: Date;
  occupiedToday: ReservationWithResource[];
  occupiedTomorrow: ReservationWithResource[];
  upcoming: ReservationWithResource[];
  spaceReservations: ReservationWithResource[];
  available: Resource[];
  damaged: DamagedResource[];
  totals: {
    activeResources: number;
    upcomingReservations: number;
    openDamages: number;
  };
}

export interface ReservationWithResource extends Reservation {
  resource: Resource;
}

export interface DamagedResource {
  resourceId: string;
  resource: Resource;
  damageId: string;
  description: string;
  severity: string;
  status: DamageStatus;
  reportedAt: Date;
}

@Injectable()
export class AvailabilityService {
  constructor(private readonly prisma: PrismaService) {}

  async snapshot(now: Date = new Date()): Promise<DashboardSnapshot> {
    const today = startOfDay(now);
    const tomorrow = addDays(today, 1);
    const dayAfterTomorrow = addDays(today, 2);
    const horizonEnd = addDays(today, 30);

    const [todays, tomorrows, upcoming, spaces, openDamageRows, allResources] = await Promise.all([
      this.reservationsActiveDuring(today, tomorrow),
      this.reservationsActiveDuring(tomorrow, dayAfterTomorrow),
      this.reservationsActiveDuring(today, horizonEnd),
      this.spaceReservationsBetween(today, horizonEnd),
      this.openDamages(),
      this.prisma.resource.findMany({ where: { isActive: true } }),
    ]);

    const damagedResourceIds = new Set(openDamageRows.map((d) => d.resourceId));
    const occupiedTodayIds = new Set(todays.map((r) => r.resourceId));

    const available = allResources.filter(
      (r) =>
        r.type !== ResourceType.BOATHOUSE_SPACE &&
        !damagedResourceIds.has(r.id) &&
        !occupiedTodayIds.has(r.id),
    );

    const damaged: DamagedResource[] = openDamageRows.map((d) => ({
      resourceId: d.resourceId,
      resource: d.resource,
      damageId: d.id,
      description: d.description,
      severity: d.severity,
      status: d.status,
      reportedAt: d.reportedAt,
    }));

    return {
      generatedAt: new Date(),
      today,
      occupiedToday: todays,
      occupiedTomorrow: tomorrows,
      upcoming,
      spaceReservations: spaces,
      available,
      damaged,
      totals: {
        activeResources: allResources.length,
        upcomingReservations: upcoming.length,
        openDamages: openDamageRows.length,
      },
    };
  }

  /** Reservations whose [startsAt, endsAt) overlaps the half-open window [from, to). */
  private reservationsActiveDuring(from: Date, to: Date): Promise<ReservationWithResource[]> {
    return this.prisma.reservation.findMany({
      where: {
        status: ReservationStatus.CONFIRMED,
        startsAt: { lt: to },
        endsAt: { gt: from },
      },
      include: { resource: true },
      orderBy: [{ startsAt: 'asc' }],
    });
  }

  private spaceReservationsBetween(from: Date, to: Date): Promise<ReservationWithResource[]> {
    return this.prisma.reservation.findMany({
      where: {
        status: ReservationStatus.CONFIRMED,
        resource: { type: ResourceType.BOATHOUSE_SPACE },
        startsAt: { lt: to },
        endsAt: { gt: from },
      },
      include: { resource: true },
      orderBy: { startsAt: 'asc' },
    });
  }

  private openDamages() {
    return this.prisma.damage.findMany({
      where: { status: { in: [DamageStatus.REPORTED, DamageStatus.IN_REPAIR] } },
      include: { resource: true },
      orderBy: [{ status: 'asc' }, { reportedAt: 'desc' }],
    });
  }
}
