import { ReservationStatus, ResourceType } from '@prisma/client';
import { randomUUID } from 'node:crypto';

import {
  InactiveResourceError,
  NotFoundDomainError,
  ReservationOverlapError,
} from '../../../common/errors/domain.errors';
import { Resource, ResourceProps } from '../../resources/domain/resource.entity';
import {
  ResourceCreateInput,
  ResourceListOptions,
  ResourceRepository,
  ResourceUpdateInput,
} from '../../resources/domain/resource.repository';
import { Reservation, ReservationProps } from '../domain/reservation.entity';
import {
  ReservationCreateInput,
  ReservationListOptions,
  ReservationRepository,
  ReservationUpdateInput,
} from '../domain/reservation.repository';
import { TimeRange } from '../domain/time-range.value';
import { ReservationsService } from './reservations.service';

class InMemoryResourceRepo extends ResourceRepository {
  private items = new Map<string, Resource>();

  seed(props: Partial<ResourceProps> & Pick<ResourceProps, 'id'>): Resource {
    const now = new Date();
    const full: ResourceProps = {
      identifier: 'X',
      type: ResourceType.KAYAK,
      name: 'Test',
      model: null,
      color: null,
      seats: null,
      lengthCm: null,
      weightKg: null,
      note: null,
      imageUrl: null,
      isActive: true,
      createdAt: now,
      updatedAt: now,
      ...props,
    };
    const r = Resource.fromPersistence(full);
    this.items.set(full.id, r);
    return r;
  }

  async create(input: ResourceCreateInput): Promise<Resource> {
    return this.seed({
      id: randomUUID(),
      identifier: input.identifier,
      type: input.type,
      name: input.name,
      model: input.model ?? null,
      isActive: input.isActive ?? true,
    });
  }
  async update(id: string, input: ResourceUpdateInput): Promise<Resource> {
    const cur = this.items.get(id);
    if (!cur) throw new Error('not found');
    return this.seed({ ...cur.toJSON(), ...input, id });
  }
  async findById(id: string): Promise<Resource | null> {
    return this.items.get(id) ?? null;
  }
  async findByIdentifier(identifier: string): Promise<Resource | null> {
    for (const r of this.items.values()) if (r.identifier === identifier) return r;
    return null;
  }
  async list(_o: ResourceListOptions) {
    return { items: [...this.items.values()], total: this.items.size };
  }
  async delete(id: string): Promise<void> {
    this.items.delete(id);
  }
  async setActive(id: string, isActive: boolean): Promise<Resource> {
    const cur = this.items.get(id);
    if (!cur) throw new Error('not found');
    return this.seed({ ...cur.toJSON(), isActive, id });
  }
}

class InMemoryReservationRepo extends ReservationRepository {
  private items = new Map<string, Reservation>();

  seed(
    props: Partial<ReservationProps> &
      Pick<ReservationProps, 'id' | 'resourceId' | 'startsAt' | 'endsAt'>,
  ): Reservation {
    const now = new Date();
    const full: ReservationProps = {
      customerName: 'Test',
      customerContact: null,
      note: null,
      status: ReservationStatus.CONFIRMED,
      createdAt: now,
      updatedAt: now,
      ...props,
    };
    const r = Reservation.fromPersistence(full);
    this.items.set(full.id, r);
    return r;
  }

  async create(input: ReservationCreateInput): Promise<Reservation> {
    return this.seed({
      id: randomUUID(),
      resourceId: input.resourceId,
      customerName: input.customerName,
      customerContact: input.customerContact ?? null,
      startsAt: input.startsAt,
      endsAt: input.endsAt,
      note: input.note ?? null,
      status: input.status ?? ReservationStatus.CONFIRMED,
    });
  }
  async update(id: string, input: ReservationUpdateInput): Promise<Reservation> {
    const cur = this.items.get(id);
    if (!cur) throw new Error('not found');
    return this.seed({ ...cur.toJSON(), ...input, id });
  }
  async delete(id: string): Promise<void> {
    this.items.delete(id);
  }
  async findById(id: string): Promise<Reservation | null> {
    return this.items.get(id) ?? null;
  }
  async list(_opts: ReservationListOptions) {
    return { items: [...this.items.values()], total: this.items.size };
  }
  async findOverlapping(
    resourceId: string,
    range: TimeRange,
    excludeReservationId?: string,
  ): Promise<Reservation[]> {
    return [...this.items.values()].filter(
      (r) =>
        r.resourceId === resourceId &&
        r.status === ReservationStatus.CONFIRMED &&
        r.id !== excludeReservationId &&
        r.range.overlaps(range),
    );
  }
}

const at = (s: string) => new Date(s);

describe('ReservationsService', () => {
  let resources: InMemoryResourceRepo;
  let reservations: InMemoryReservationRepo;
  let service: ReservationsService;

  let activeKayakId: string;
  let inactiveKayakId: string;

  beforeEach(() => {
    resources = new InMemoryResourceRepo();
    reservations = new InMemoryReservationRepo();
    service = new ReservationsService(reservations, resources);

    activeKayakId = randomUUID();
    inactiveKayakId = randomUUID();
    resources.seed({ id: activeKayakId, identifier: 'K-1', name: 'Kayak 1' });
    resources.seed({
      id: inactiveKayakId,
      identifier: 'K-2',
      name: 'Kayak 2',
      isActive: false,
    });
  });

  it('creates a reservation when no conflicts exist', async () => {
    const r = await service.create({
      resourceId: activeKayakId,
      customerName: 'Ján',
      startsAt: at('2026-05-10T09:00:00Z'),
      endsAt: at('2026-05-10T12:00:00Z'),
    });
    expect(r.status).toBe(ReservationStatus.CONFIRMED);
    expect(r.range.durationHours()).toBe(3);
  });

  it('rejects on inactive resource', async () => {
    await expect(
      service.create({
        resourceId: inactiveKayakId,
        customerName: 'Ján',
        startsAt: at('2026-05-10T09:00:00Z'),
        endsAt: at('2026-05-10T10:00:00Z'),
      }),
    ).rejects.toBeInstanceOf(InactiveResourceError);
  });

  it('rejects on missing resource', async () => {
    await expect(
      service.create({
        resourceId: randomUUID(),
        customerName: 'Ján',
        startsAt: at('2026-05-10T09:00:00Z'),
        endsAt: at('2026-05-10T10:00:00Z'),
      }),
    ).rejects.toBeInstanceOf(NotFoundDomainError);
  });

  it('rejects when end is before start', async () => {
    await expect(
      service.create({
        resourceId: activeKayakId,
        customerName: 'Ján',
        startsAt: at('2026-05-10T12:00:00Z'),
        endsAt: at('2026-05-10T09:00:00Z'),
      }),
    ).rejects.toThrow();
  });

  it('rejects zero-length range', async () => {
    await expect(
      service.create({
        resourceId: activeKayakId,
        customerName: 'Ján',
        startsAt: at('2026-05-10T12:00:00Z'),
        endsAt: at('2026-05-10T12:00:00Z'),
      }),
    ).rejects.toThrow();
  });

  describe('overlap matrix', () => {
    beforeEach(async () => {
      await service.create({
        resourceId: activeKayakId,
        customerName: 'Existing',
        startsAt: at('2026-05-10T09:00:00Z'),
        endsAt: at('2026-05-10T12:00:00Z'),
      });
    });

    it.each([
      ['exact match', '2026-05-10T09:00:00Z', '2026-05-10T12:00:00Z'],
      ['inside existing', '2026-05-10T10:00:00Z', '2026-05-10T11:00:00Z'],
      ['overlapping start', '2026-05-10T08:00:00Z', '2026-05-10T10:00:00Z'],
      ['overlapping end', '2026-05-10T11:00:00Z', '2026-05-10T13:00:00Z'],
      ['fully containing', '2026-05-10T00:00:00Z', '2026-05-10T23:59:59Z'],
    ])('rejects %s', async (_name, start, end) => {
      await expect(
        service.create({
          resourceId: activeKayakId,
          customerName: 'New',
          startsAt: at(start),
          endsAt: at(end),
        }),
      ).rejects.toBeInstanceOf(ReservationOverlapError);
    });

    it.each([
      ['back-to-back before (handover)', '2026-05-10T06:00:00Z', '2026-05-10T09:00:00Z'],
      ['back-to-back after (handover)', '2026-05-10T12:00:00Z', '2026-05-10T15:00:00Z'],
      ['next day', '2026-05-11T09:00:00Z', '2026-05-11T12:00:00Z'],
    ])('accepts %s', async (_name, start, end) => {
      await expect(
        service.create({
          resourceId: activeKayakId,
          customerName: 'New',
          startsAt: at(start),
          endsAt: at(end),
        }),
      ).resolves.toBeDefined();
    });

    it('allows other resources to share the time range', async () => {
      const otherId = randomUUID();
      resources.seed({ id: otherId, identifier: 'K-9', name: 'Kayak 9' });
      await expect(
        service.create({
          resourceId: otherId,
          customerName: 'New',
          startsAt: at('2026-05-10T09:00:00Z'),
          endsAt: at('2026-05-10T12:00:00Z'),
        }),
      ).resolves.toBeDefined();
    });

    it('ignores cancelled reservations when checking overlap', async () => {
      const list = await reservations.list({});
      const first = list.items[0]!;
      await service.cancel(first.id);

      await expect(
        service.create({
          resourceId: activeKayakId,
          customerName: 'New',
          startsAt: at('2026-05-10T09:00:00Z'),
          endsAt: at('2026-05-10T12:00:00Z'),
        }),
      ).resolves.toBeDefined();
    });

    it('does not consider itself when updating its own dates', async () => {
      const list = await reservations.list({});
      const existing = list.items[0]!;
      await expect(
        service.update(existing.id, {
          startsAt: at('2026-05-10T10:00:00Z'),
          endsAt: at('2026-05-10T13:00:00Z'),
        }),
      ).resolves.toBeDefined();
    });
  });

  it('cancellation marks the reservation but does not delete it', async () => {
    const created = await service.create({
      resourceId: activeKayakId,
      customerName: 'Ján',
      startsAt: at('2026-05-10T09:00:00Z'),
      endsAt: at('2026-05-10T10:00:00Z'),
    });
    const cancelled = await service.cancel(created.id);
    expect(cancelled.status).toBe(ReservationStatus.CANCELLED);
  });

  it('hard delete removes the reservation entirely', async () => {
    const created = await service.create({
      resourceId: activeKayakId,
      customerName: 'Ján',
      startsAt: at('2026-05-10T09:00:00Z'),
      endsAt: at('2026-05-10T10:00:00Z'),
    });
    await service.remove(created.id);
    await expect(service.findById(created.id)).rejects.toBeInstanceOf(NotFoundDomainError);
  });

  it('after delete, the same time slot becomes free again', async () => {
    const created = await service.create({
      resourceId: activeKayakId,
      customerName: 'First',
      startsAt: at('2026-05-10T09:00:00Z'),
      endsAt: at('2026-05-10T12:00:00Z'),
    });
    await service.remove(created.id);
    await expect(
      service.create({
        resourceId: activeKayakId,
        customerName: 'Second',
        startsAt: at('2026-05-10T09:00:00Z'),
        endsAt: at('2026-05-10T12:00:00Z'),
      }),
    ).resolves.toBeDefined();
  });
});
