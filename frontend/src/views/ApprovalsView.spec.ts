import { flushPromises, mount } from '@vue/test-utils';
import { createPinia, setActivePinia } from 'pinia';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { createMemoryHistory, createRouter } from 'vue-router';

import type { Reservation, Resource } from '@/api/types';
import { useAuthStore } from '@/stores/auth.store';

import ApprovalsView from './ApprovalsView.vue';

const approvals = vi.fn();
const approve = vi.fn();
const reject = vi.fn();
const listResources = vi.fn();

vi.mock('@/api/reservations.api', () => ({
  reservationsApi: {
    approvals: (...a: unknown[]) => approvals(...a),
    approve: (...a: unknown[]) => approve(...a),
    reject: (...a: unknown[]) => reject(...a),
  },
}));
vi.mock('@/api/resources.api', () => ({
  resourcesApi: { list: (...a: unknown[]) => listResources(...a) },
}));

const space = { id: 'res-1', identifier: 'S-1', type: 'BOATHOUSE_SPACE', name: 'Klubovňa', isActive: true, requiresApproval: true, approvers: [] as Resource['approvers'] } as Resource;
const request = {
  id: 'r-1', resourceId: 'res-1', status: 'PENDING_APPROVAL', customerName: 'Peter', customerContact: 'peter@example.test',
  note: 'Oslava', startsAt: '2027-06-01T09:00:00+00:00', endsAt: '2027-06-01T12:00:00+00:00', createdAt: '2027-05-01T10:00:00+00:00',
} as Reservation;

async function mountView() {
  setActivePinia(createPinia());
  const auth = useAuthStore();
  auth.token = 't';
  auth.user = { id: 'u1', name: 'Approver', email: 'a@example.test', role: 'MEMBER' } as never;
  const router = createRouter({ history: createMemoryHistory(), routes: [{ path: '/approvals', component: ApprovalsView }] });
  await router.push('/approvals');
  await router.isReady();
  const w = mount(ApprovalsView, { global: { plugins: [router] } });
  await flushPromises();
  return w;
}

describe('ApprovalsView', () => {
  beforeEach(() => {
    approvals.mockReset().mockResolvedValue({ items: [request], total: 1 });
    approve.mockReset().mockResolvedValue({ ...request, status: 'CONFIRMED' });
    reject.mockReset();
    listResources.mockReset().mockResolvedValue({ items: [space], total: 1 });
  });

  it('lists the waiting request with resource, booker and note', async () => {
    const w = await mountView();
    expect(w.text()).toContain('S-1');
    expect(w.text()).toContain('Klubovňa');
    expect(w.text()).toContain('Peter');
    expect(w.text()).toContain('Oslava');
  });

  it('approving refreshes the list and empties it', async () => {
    const w = await mountView();
    approvals.mockResolvedValue({ items: [], total: 0 });

    await w.find('[data-testid="approve"]').trigger('click');
    await flushPromises();

    expect(approve).toHaveBeenCalledWith('r-1', undefined);
    expect(w.text()).toContain('Nič nečaká na tvoje schválenie');
  });

  it('shows the empty state when there is nothing to decide', async () => {
    approvals.mockResolvedValue({ items: [], total: 0 });
    const w = await mountView();
    expect(w.text()).toContain('Nič nečaká na tvoje schválenie');
  });
});
