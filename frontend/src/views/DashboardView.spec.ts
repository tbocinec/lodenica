import { flushPromises, mount } from '@vue/test-utils';
import { createPinia, setActivePinia } from 'pinia';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import type { Reservation, Resource } from '@/api/types';
import { useAuthStore } from '@/stores/auth.store';

import DashboardView from './DashboardView.vue';

const listReservations = vi.fn();
const mineReservations = vi.fn();
const listResources = vi.fn();

vi.mock('@/api/reservations.api', () => ({
  reservationsApi: {
    list: (...a: unknown[]) => listReservations(...a),
    mine: (...a: unknown[]) => mineReservations(...a),
  },
}));

vi.mock('@/api/availability.api', () => ({
  availabilityApi: {
    dashboard: () =>
      Promise.resolve({
        generatedAt: '2026-09-05T14:30:00+00:00',
        today: '2026-09-05',
        occupiedToday: [],
        occupiedTomorrow: [],
        upcoming: [],
        spaceReservations: [],
        available: [],
        damaged: [],
        totals: { activeResources: 0, upcomingReservations: 0, openDamages: 0 },
      }),
  },
}));

vi.mock('@/api/resources.api', () => ({
  resourcesApi: { list: (...a: unknown[]) => listResources(...a) },
}));

const kayak: Resource = {
  id: 'res-1',
  identifier: 'K-007',
  type: 'WW_KAYAK',
  name: 'Pyranha',
  isActive: true,
} as Resource;

const reservation = (over: Partial<Reservation> = {}): Reservation =>
  ({
    id: 'r1',
    resourceId: kayak.id,
    eventId: null,
    createdById: 'u1',
    memberId: null,
    customerName: 'Janko Member',
    customerContact: null,
    startsAt: '2026-09-06T09:00:00+00:00',
    endsAt: '2026-09-06T12:00:00+00:00',
    note: null,
    status: 'CONFIRMED',
    createdAt: '2026-09-01T09:00:00+00:00',
    updatedAt: '2026-09-01T09:00:00+00:00',
    ...over,
  }) as Reservation;

function mountAsMember() {
  const auth = useAuthStore();
  auth.token = 'test-token';
  auth.user = { id: 'u1', name: 'Janko', email: 'j@example.test', role: 'MEMBER' } as never;

  return mount(DashboardView, {
    global: {
      stubs: {
        RouterLink: { template: '<a><slot /></a>' },
        PaddlingTrafficLightWidget: true,
        ReservationEditDialog: true,
      },
    },
  });
}

describe('DashboardView — Moje rezervácie', () => {
  beforeEach(() => {
    setActivePinia(createPinia());
    vi.useFakeTimers();
    // 2026-09-05, mid-afternoon UTC.
    vi.setSystemTime(new Date('2026-09-05T14:30:00Z'));
    listReservations.mockReset().mockResolvedValue({ items: [], total: 0 });
    mineReservations.mockReset().mockResolvedValue({ items: [], total: 0 });
    listResources.mockReset().mockResolvedValue({ items: [kayak], total: 1 });
  });

  afterEach(() => {
    vi.useRealTimers();
  });

  it('asks only for bookings that are still running or yet to start', async () => {
    mountAsMember();
    await flushPromises();

    expect(listReservations).toHaveBeenCalledWith(
      expect.objectContaining({ mine: true, from: '2026-09-05T00:00:00.000Z' }),
    );
  });

  it('renders the bookings it gets back', async () => {
    listReservations.mockResolvedValue({ items: [reservation()], total: 1 });

    const w = mountAsMember();
    await flushPromises();

    expect(w.text()).toContain('K-007');
    expect(w.text()).not.toContain('Nemáš žiadne nadchádzajúce rezervácie');
  });

  it('says nothing is coming up rather than claiming there are no bookings at all', async () => {
    const w = mountAsMember();
    await flushPromises();

    expect(w.text()).toContain('Nemáš žiadne nadchádzajúce rezervácie');
  });
});
