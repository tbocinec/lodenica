import { flushPromises, mount } from '@vue/test-utils';
import { createPinia, setActivePinia } from 'pinia';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import type { Resource } from '@/api/types';
import ResourceOpenDamagesNotice from '@/components/ui/ResourceOpenDamagesNotice.vue';
import ResourceSelect from '@/components/ui/ResourceSelect.vue';

import DamagesView from './DamagesView.vue';

const listDamages = vi.fn();
const listResources = vi.fn();

vi.mock('@/api/damages.api', () => ({
  damagesApi: {
    list: (...a: unknown[]) => listDamages(...a),
    create: vi.fn(),
    uploadPhoto: vi.fn(),
  },
}));

vi.mock('@/api/resources.api', () => ({
  resourcesApi: { list: (...a: unknown[]) => listResources(...a) },
}));

vi.mock('vue-router', () => ({ useRouter: () => ({ push: vi.fn() }) }));

const kayak: Resource = {
  id: 'res-1',
  identifier: 'K-007',
  type: 'WW_KAYAK',
  name: 'Pyranha',
  isActive: true,
} as Resource;

function mountView() {
  return mount(DamagesView, {
    global: { stubs: { RouterLink: { template: '<a><slot /></a>' } } },
  });
}

describe('DamagesView — nové poškodenie', () => {
  beforeEach(() => {
    setActivePinia(createPinia());
    listDamages.mockReset().mockResolvedValue({ items: [], total: 0, page: 1, pageSize: 200 });
    listResources.mockReset().mockResolvedValue({ items: [kayak], total: 1 });
  });

  it('checks the picked resource for damages that are already open', async () => {
    const w = mountView();
    await flushPromises();

    await w.find('button.btn-primary').trigger('click');
    await w.findComponent(ResourceSelect).vm.$emit('update:modelValue', kayak.id);
    await flushPromises();

    expect(w.findComponent(ResourceOpenDamagesNotice).props('resourceId')).toBe(kayak.id);
  });
});
