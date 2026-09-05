import { flushPromises, mount } from '@vue/test-utils';
import { createPinia, setActivePinia } from 'pinia';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import { ApiError } from '@/api/http';
import { DEFAULT_SITE_CONFIG, type AdminSiteConfig } from '@/api/site.api';
import { useSiteStore } from '@/stores/site.store';

import AdminSiteSettingsView from './AdminSiteSettingsView.vue';

const adminGet = vi.fn();
const update = vi.fn();

vi.mock('@/api/site.api', async (importOriginal) => ({
  ...(await importOriginal<typeof import('@/api/site.api')>()),
  siteApi: {
    adminGet: () => adminGet(),
    update: (...a: unknown[]) => update(...a),
    uploadLogo: vi.fn(),
    removeLogo: vi.fn(),
  },
}));

const serverConfig: AdminSiteConfig = {
  ...DEFAULT_SITE_CONFIG,
  siteName: 'Klub Test',
  shortName: 'KT',
  adminEmail: null,
};

async function mountView() {
  setActivePinia(createPinia());
  const w = mount(AdminSiteSettingsView);
  await flushPromises();
  return w;
}

describe('AdminSiteSettingsView', () => {
  beforeEach(() => {
    localStorage.clear();
    adminGet.mockReset().mockResolvedValue(serverConfig);
    update.mockReset();
  });

  it('loads the current config into the form', async () => {
    const w = await mountView();
    expect((w.find('#site-siteName').element as HTMLInputElement).value).toBe('Klub Test');
    expect((w.find('#site-contactEmail').element as HTMLInputElement).value).toBe('');
    expect(w.find('button[type="submit"]').attributes('disabled')).toBeDefined();
  });

  it('sends only the changed keys and updates the site store', async () => {
    update.mockImplementation(async (patch: Partial<AdminSiteConfig>) => ({ ...serverConfig, ...patch }));
    const w = await mountView();

    await w.find('#site-contactEmail').setValue('klub@example.test');
    await w.find('#site-feature-paddlingTrafficLight').setValue(true);
    await w.find('form').trigger('submit.prevent');
    await flushPromises();

    expect(update).toHaveBeenCalledTimes(1);
    expect(update).toHaveBeenCalledWith({
      contactEmail: 'klub@example.test',
      features: { paddlingTrafficLight: true },
    });
    expect(w.text()).toContain('Nastavenia uložené.');
    expect(useSiteStore().config.contactEmail).toBe('klub@example.test');
  });

  it('an emptied field is sent as null so the default applies again', async () => {
    update.mockImplementation(async (patch: Partial<AdminSiteConfig>) => ({ ...serverConfig, ...patch }));
    const w = await mountView();

    await w.find('#site-shortName').setValue('');
    await w.find('form').trigger('submit.prevent');
    await flushPromises();

    expect(update).toHaveBeenCalledWith({ shortName: null });
  });

  it('shows a validation message under the offending field', async () => {
    update.mockRejectedValue(
      new ApiError({
        statusCode: 400,
        error: 'Bad Request',
        code: 'VALIDATION_ERROR',
        message: 'Zadajte platnú e-mailovú adresu.',
        details: { contactEmail: ['Zadajte platnú e-mailovú adresu.'] },
      }),
    );
    const w = await mountView();

    await w.find('#site-contactEmail').setValue('nope');
    await w.find('form').trigger('submit.prevent');
    await flushPromises();

    expect(w.text()).toContain('Zadajte platnú e-mailovú adresu.');
  });
});
