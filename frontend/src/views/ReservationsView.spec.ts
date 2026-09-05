import { flushPromises, mount } from '@vue/test-utils';
import { createPinia, setActivePinia } from 'pinia';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { createMemoryHistory, createRouter } from 'vue-router';

import type { Resource } from '@/api/types';
import { useAuthStore } from '@/stores/auth.store';

import ReservationsView from './ReservationsView.vue';

const listReservations = vi.fn();
const listResources = vi.fn();

vi.mock('@/api/reservations.api', () => ({
  reservationsApi: { list: (...a: unknown[]) => listReservations(...a) },
}));

vi.mock('@/api/resources.api', () => ({
  resourcesApi: { list: (...a: unknown[]) => listResources(...a) },
}));

const kayak = {
  id: 'res-1',
  identifier: 'K-007',
  type: 'WW_KAYAK',
  name: 'Pyranha',
  isActive: true,
} as Resource;

/** Query params of the most recent GET /reservations. */
function lastQuery(): Record<string, unknown> {
  return listReservations.mock.calls.at(-1)?.[0] as Record<string, unknown>;
}

function mineCheckbox(w: ReturnType<typeof mount>) {
  return w.find('input#r-mine');
}

async function mountView(opts: { loggedIn?: boolean; query?: string } = {}) {
  // Fresh store per mount — one test mounts twice with different auth.
  setActivePinia(createPinia());
  const auth = useAuthStore();
  if (opts.loggedIn ?? true) {
    auth.token = 'test-token';
    auth.user = { id: 'u1', name: 'Janko', email: 'j@example.test', role: 'MEMBER' } as never;
  }

  const router = createRouter({
    history: createMemoryHistory(),
    routes: [{ path: '/reservations', component: ReservationsView }],
  });
  await router.push(`/reservations${opts.query ?? ''}`);
  await router.isReady();

  const w = mount(ReservationsView, {
    global: {
      plugins: [router],
      stubs: { ReservationEditDialog: true },
    },
  });
  await flushPromises();
  return { w, router };
}

describe('ReservationsView — "iba moje" filter', () => {
  beforeEach(() => {
    setActivePinia(createPinia());
    vi.useFakeTimers();
    vi.setSystemTime(new Date('2026-09-05T14:30:00Z'));
    listReservations.mockReset().mockResolvedValue({ items: [], total: 0 });
    listResources.mockReset().mockResolvedValue({ items: [kayak], total: 1 });
  });

  afterEach(() => {
    vi.useRealTimers();
  });

  it('offers the checkbox to logged-in users only', async () => {
    expect(mineCheckbox((await mountView({ loggedIn: true })).w).exists()).toBe(true);
    expect(mineCheckbox((await mountView({ loggedIn: false })).w).exists()).toBe(false);
  });

  it('does not filter by owner until the box is ticked', async () => {
    await mountView();

    expect(lastQuery().mine).toBeUndefined();
  });

  it('asks for my bookings when ticked', async () => {
    const { w } = await mountView();

    await mineCheckbox(w).setValue(true);
    await flushPromises();

    expect(lastQuery().mine).toBe(true);
  });

  it('keeps the past cut-off independent of the owner filter', async () => {
    const { w } = await mountView();

    await mineCheckbox(w).setValue(true);
    await flushPromises();
    // Still upcoming-only — "Zobraziť minulé" is its own switch.
    expect(lastQuery().from).toBe('2026-09-05T00:00:00.000Z');

    await w.find('input#r-past').setValue(true);
    await flushPromises();
    expect(lastQuery().from).toBeUndefined();
    expect(lastQuery().mine).toBe(true);
  });

  it('opens with both filters applied when the URL asks for my history', async () => {
    const { w } = await mountView({ query: '?mine=1&past=1' });

    expect((mineCheckbox(w).element as HTMLInputElement).checked).toBe(true);
    expect((w.find('input#r-past').element as HTMLInputElement).checked).toBe(true);
    expect(lastQuery().mine).toBe(true);
    expect(lastQuery().from).toBeUndefined();
  });

  it('drops the filters again when the plain list URL is opened', async () => {
    const { w, router } = await mountView({ query: '?mine=1&past=1' });

    await router.push('/reservations');
    await flushPromises();

    expect((mineCheckbox(w).element as HTMLInputElement).checked).toBe(false);
    expect(lastQuery().mine).toBeUndefined();
    expect(lastQuery().from).toBe('2026-09-05T00:00:00.000Z');
  });

  it('ignores a mine=1 URL for anonymous visitors', async () => {
    await mountView({ loggedIn: false, query: '?mine=1' });

    expect(lastQuery().mine).toBeUndefined();
  });

  it('counts the owner filter as an active filter', async () => {
    const { w } = await mountView({ query: '?mine=1' });

    expect(w.text()).toContain('Zrušiť filtre');
  });

  it('asks for confirmed AND waiting reservations by default, everything once "zrušené" is on', async () => {
    const { w } = await mountView();
    expect(lastQuery().status).toEqual(['CONFIRMED', 'PENDING_APPROVAL']);

    await w.find('input#r-cancelled').setValue(true);
    await flushPromises();
    expect(lastQuery().status).toBeUndefined();
  });

  it('labels a waiting request in the table', async () => {
    listReservations.mockResolvedValue({
      items: [{
        id: 'r-1', resourceId: 'res-1', eventId: null, customerName: 'Peter', customerContact: null,
        createdById: 'u1', startsAt: '2027-06-01T09:00:00+00:00', endsAt: '2027-06-01T12:00:00+00:00',
        note: null, status: 'PENDING_APPROVAL', createdAt: '2027-05-01T00:00:00+00:00', updatedAt: '2027-05-01T00:00:00+00:00',
      }],
      total: 1,
    });
    const { w } = await mountView();
    expect(w.text()).toContain('Čaká na schválenie');
  });
});
