import { flushPromises, mount } from '@vue/test-utils';
import { createPinia, setActivePinia } from 'pinia';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import type { Reservation } from '@/api/types';

import ApprovalDecisionButtons from './ApprovalDecisionButtons.vue';

const approve = vi.fn();
const reject = vi.fn();
const approvals = vi.fn();

vi.mock('@/api/reservations.api', () => ({
  reservationsApi: {
    approve: (...a: unknown[]) => approve(...a),
    reject: (...a: unknown[]) => reject(...a),
    approvals: (...a: unknown[]) => approvals(...a),
  },
}));

const pending = {
  id: 'r-1', resourceId: 'res-1', status: 'PENDING_APPROVAL', customerName: 'Peter',
  startsAt: '2027-06-01T09:00:00+00:00', endsAt: '2027-06-01T12:00:00+00:00',
} as Reservation;

describe('ApprovalDecisionButtons', () => {
  beforeEach(() => {
    setActivePinia(createPinia());
    approve.mockReset().mockResolvedValue({ ...pending, status: 'CONFIRMED' });
    reject.mockReset().mockResolvedValue({ ...pending, status: 'REJECTED' });
    approvals.mockReset().mockResolvedValue({ items: [], total: 0 });
  });

  it('approves with the trimmed note and reports the updated reservation', async () => {
    const w = mount(ApprovalDecisionButtons, { props: { reservation: pending } });
    await w.find('textarea').setValue('  Kľúče u správcu.  ');
    await w.find('[data-testid="approve"]').trigger('click');
    await flushPromises();

    expect(approve).toHaveBeenCalledWith('r-1', 'Kľúče u správcu.');
    expect(w.emitted('decided')?.[0]?.[0]).toMatchObject({ status: 'CONFIRMED' });
  });

  it('rejects without a note when the field is empty', async () => {
    const w = mount(ApprovalDecisionButtons, { props: { reservation: pending } });
    await w.find('[data-testid="reject"]').trigger('click');
    await flushPromises();

    expect(reject).toHaveBeenCalledWith('r-1', undefined);
    expect(w.emitted('decided')?.[0]?.[0]).toMatchObject({ status: 'REJECTED' });
  });

  it('shows the API error and emits nothing', async () => {
    approve.mockRejectedValue(new Error('O tejto rezervácii už bolo rozhodnuté.'));
    const w = mount(ApprovalDecisionButtons, { props: { reservation: pending } });
    await w.find('[data-testid="approve"]').trigger('click');
    await flushPromises();

    expect(w.text()).toContain('už bolo rozhodnuté');
    expect(w.emitted('decided')).toBeUndefined();
  });
});
