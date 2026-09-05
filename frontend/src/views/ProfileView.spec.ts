import { flushPromises, mount } from '@vue/test-utils';
import { createPinia, setActivePinia } from 'pinia';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { createMemoryHistory, createRouter } from 'vue-router';

import { useAuthStore } from '@/stores/auth.store';

import ProfileView from './ProfileView.vue';

const notifications = vi.fn();
const setNotifications = vi.fn();

vi.mock('@/api/profile.api', () => ({
  profileApi: {
    notifications: (...a: unknown[]) => notifications(...a),
    setNotifications: (...a: unknown[]) => setNotifications(...a),
    identities: vi.fn().mockResolvedValue([]),
    unlinkIdentity: vi.fn(),
    linkUrl: vi.fn(),
  },
}));
vi.mock('@/api/auth.api', () => ({
  authApi: { providers: vi.fn().mockResolvedValue([]) },
}));

const prefs = [
  { key: 'reservation_approval_requested', label: 'Žiadosť o schválenie rezervácie', description: 'Schvaľovateľom.', enabled: true },
  { key: 'reservation_decided', label: 'Výsledok schvaľovania rezervácie', description: 'Rezervujúcemu.', enabled: true },
];

async function mountView() {
  setActivePinia(createPinia());
  const auth = useAuthStore();
  auth.token = 't';
  auth.user = { id: 'u1', name: 'Janko', email: 'j@example.test', role: 'MEMBER' } as never;
  const router = createRouter({ history: createMemoryHistory(), routes: [{ path: '/profil', component: ProfileView }] });
  await router.push('/profil');
  await router.isReady();
  const w = mount(ProfileView, { global: { plugins: [router] } });
  await flushPromises();
  return w;
}

describe('ProfileView — e-mail preferences', () => {
  beforeEach(() => {
    notifications.mockReset().mockResolvedValue(prefs);
    setNotifications.mockReset().mockResolvedValue([prefs[0], { ...prefs[1], enabled: false }]);
  });

  it('lists the user-configurable notifications', async () => {
    const w = await mountView();
    expect(w.text()).toContain('E-mailové notifikácie');
    expect(w.text()).toContain('Výsledok schvaľovania rezervácie');
  });

  it('toggling a switch patches just that key', async () => {
    const w = await mountView();
    await w.find('input#pref-reservation_decided').setValue(false);
    await flushPromises();

    expect(setNotifications).toHaveBeenCalledWith({ reservation_decided: false });
    expect((w.find('input#pref-reservation_decided').element as HTMLInputElement).checked).toBe(false);
  });
});
