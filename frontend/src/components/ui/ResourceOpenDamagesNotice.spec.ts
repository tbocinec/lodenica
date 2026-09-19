import { RouterLinkStub, flushPromises, mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import { DamageSeverity, DamageStatus, type Damage } from '@/api/types';

import ResourceOpenDamagesNotice from './ResourceOpenDamagesNotice.vue';

const list = vi.fn();

vi.mock('@/api/damages.api', () => ({
  damagesApi: {
    list: (...a: unknown[]) => list(...a),
  },
}));

const damage = (over: Partial<Damage> = {}): Damage => ({
  id: 'd1',
  resourceId: 'r1',
  description: 'diera v dne pri kýle',
  severity: DamageSeverity.MODERATE,
  status: DamageStatus.REPORTED,
  reportedAt: '2026-09-12T08:00:00+00:00',
  fixedAt: null,
  note: null,
  reportedByName: 'Novák',
  assigneeName: null,
  photoUrl: null,
  createdAt: '2026-09-12T08:00:00+00:00',
  updatedAt: '2026-09-12T08:00:00+00:00',
  ...over,
});

const mountNotice = (resourceId: string) =>
  mount(ResourceOpenDamagesNotice, {
    props: { resourceId },
    global: { stubs: { RouterLink: RouterLinkStub } },
  });

describe('ResourceOpenDamagesNotice', () => {
  beforeEach(() => {
    list.mockReset().mockResolvedValue({ items: [], total: 0, page: 1, pageSize: 50 });
  });

  it('stays silent until a resource is picked', async () => {
    const w = mountNotice('');
    await flushPromises();

    expect(list).not.toHaveBeenCalled();
    expect(w.text()).toBe('');
  });

  it('stays silent when the resource has no open damage', async () => {
    const w = mountNotice('r1');
    await flushPromises();

    expect(list).toHaveBeenCalledWith({ resourceId: 'r1', pageSize: 50 });
    expect(w.text()).toBe('');
  });

  it('warns about the open damage and shows what it is', async () => {
    list.mockResolvedValue({ items: [damage()], total: 1, page: 1, pageSize: 50 });
    const w = mountNotice('r1');
    await flushPromises();

    expect(w.text()).toContain('Tento zdroj už má 1 otvorené poškodenie');
    expect(w.text()).toContain('diera v dne pri kýle');
    expect(w.text()).toContain('12.09.2026');
    expect(w.text()).toContain('Novák');
    expect(w.text()).toContain('Ak chceš poškodenie rozšíriť');
  });

  it('counts several open damages in the plural', async () => {
    list.mockResolvedValue({
      items: [damage(), damage({ id: 'd2', description: 'odretá špička' })],
      total: 2,
      page: 1,
      pageSize: 50,
    });
    const w = mountNotice('r1');
    await flushPromises();

    expect(w.text()).toContain('Tento zdroj už má 2 otvorené poškodenia');
    expect(w.text()).toContain('odretá špička');
  });

  it('ignores damages that are already fixed', async () => {
    list.mockResolvedValue({
      items: [
        damage({ id: 'd-fixed', description: 'zalepené', status: DamageStatus.FIXED }),
        damage({ id: 'd-repair', description: 'v dielni', status: DamageStatus.IN_REPAIR }),
      ],
      total: 2,
      page: 1,
      pageSize: 50,
    });
    const w = mountNotice('r1');
    await flushPromises();

    expect(w.text()).toContain('Tento zdroj už má 1 otvorené poškodenie');
    expect(w.text()).toContain('v dielni');
    expect(w.text()).not.toContain('zalepené');
  });

  it('links each damage to its detail in a new tab', async () => {
    list.mockResolvedValue({ items: [damage()], total: 1, page: 1, pageSize: 50 });
    const w = mountNotice('r1');
    await flushPromises();

    const link = w.findComponent(RouterLinkStub);
    expect(link.props().to).toBe('/damages/d1');
    expect(link.attributes('target')).toBe('_blank');
  });

  it('reloads when another resource is picked', async () => {
    list.mockResolvedValue({ items: [damage()], total: 1, page: 1, pageSize: 50 });
    const w = mountNotice('r1');
    await flushPromises();

    list.mockResolvedValue({ items: [], total: 0, page: 1, pageSize: 50 });
    await w.setProps({ resourceId: 'r2' });
    await flushPromises();

    expect(list).toHaveBeenLastCalledWith({ resourceId: 'r2', pageSize: 50 });
    expect(w.text()).toBe('');
  });

  it('ignores a slow answer for a resource that is no longer selected', async () => {
    let resolveFirst: (v: unknown) => void = () => {};
    list.mockReturnValueOnce(
      new Promise((resolve) => {
        resolveFirst = resolve;
      }),
    );
    const w = mountNotice('r1');

    list.mockResolvedValue({ items: [], total: 0, page: 1, pageSize: 50 });
    await w.setProps({ resourceId: 'r2' });
    await flushPromises();

    resolveFirst({ items: [damage()], total: 1, page: 1, pageSize: 50 });
    await flushPromises();

    expect(w.text()).toBe('');
  });

  it('keeps quiet when the check itself fails', async () => {
    list.mockRejectedValue(new Error('network down'));
    const w = mountNotice('r1');
    await flushPromises();

    expect(w.text()).toBe('');
  });
});
