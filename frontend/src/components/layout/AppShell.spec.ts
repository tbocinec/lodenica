import { flushPromises, mount } from '@vue/test-utils';
import { createPinia, setActivePinia } from 'pinia';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { createMemoryHistory, createRouter } from 'vue-router';

import { DEFAULT_SITE_CONFIG, type SiteConfig } from '@/api/site.api';
import { useAuthStore } from '@/stores/auth.store';
import { useSiteStore } from '@/stores/site.store';

import AppShell from './AppShell.vue';

let pendingApprovals = 0;
vi.mock('@/api/reservations.api', () => ({
  reservationsApi: {
    approvals: () =>
      Promise.resolve({
        items: Array.from({ length: pendingApprovals }, (_, i) => ({ id: `r${i}` })),
        total: pendingApprovals,
        page: 1,
        pageSize: 1,
      }),
  },
}));

type Role = 'ADMIN' | 'MEMBER' | null;

async function mountShell(role: Role, site: Partial<SiteConfig> = {}) {
  setActivePinia(createPinia());
  const auth = useAuthStore();
  if (role) {
    auth.token = 't';
    auth.user = { id: 'u1', name: 'Test', email: 't@example.test', role, theme: null } as never;
  }
  const siteStore = useSiteStore();
  siteStore.apply({ ...DEFAULT_SITE_CONFIG, shortName: 'Rezervácie Klub', ...site });

  const Stub = { template: '<div />' };
  const router = createRouter({
    history: createMemoryHistory(),
    routes: ['/', '/audit', '/admin/site', '/rules', '/vodacky-semafor', '/resources', '/approvals'].map((path) => ({
      path,
      component: Stub,
    })),
  });
  await router.push('/');
  await router.isReady();

  const wrapper = mount(AppShell, { global: { plugins: [router] }, slots: { default: '<p>obsah</p>' } });
  await flushPromises();
  return wrapper;
}

function count(haystack: string, needle: string): number {
  return haystack.split(needle).length - 1;
}

function linksTo(w: { findAll: (sel: string) => { text: () => string }[] }, path: string) {
  return w.findAll(`a[href="${path}"]`);
}

describe('AppShell navigation', () => {
  beforeEach(() => {
    localStorage.clear();
    pendingApprovals = 0;
  });

  it('shows the site short name in the header', async () => {
    const w = await mountShell(null);
    expect(w.text()).toContain('Rezervácie Klub');
  });

  it('admin gets the Administrácia group with both subgroups and História zmien only once (NAV-001)', async () => {
    const w = await mountShell('ADMIN');
    const text = w.text();
    expect(text).toContain('Administrácia');
    expect(text).toContain('Správa');
    expect(text).toContain('Systém');
    expect(text).toContain('Nastavenia stránky');
    expect(text).toContain('Používatelia');
    expect(text).toContain('Diagnostika e-mailov');
    expect(count(text, 'História zmien')).toBe(1);
  });

  it('admin finds Zdroje and Na schválenie under Správa, not in the main nav', async () => {
    pendingApprovals = 2;
    const w = await mountShell('ADMIN');

    const resources = linksTo(w, '/resources');
    expect(resources).toHaveLength(1);
    expect(resources[0].text()).toContain('Zdroje');
    const approvals = linksTo(w, '/approvals');
    expect(approvals).toHaveLength(1);
    expect(approvals[0].text()).toContain('Na schválenie');
    expect(approvals[0].text()).toContain('2');
    expect(count(w.text(), 'Na schválenie')).toBe(1);
  });

  it('member keeps Lode in the main nav and sees Na schválenie only while something waits', async () => {
    const idle = await mountShell('MEMBER');
    expect(linksTo(idle, '/resources')[0].text()).toContain('Lode');
    expect(linksTo(idle, '/approvals')).toHaveLength(0);

    pendingApprovals = 1;
    const busy = await mountShell('MEMBER');
    expect(linksTo(busy, '/approvals')).toHaveLength(1);
    expect(busy.text()).not.toContain('Zdroje');
  });

  it('anonymous visitors still reach Lode from the main nav', async () => {
    const w = await mountShell(null);
    expect(linksTo(w, '/resources')).toHaveLength(1);
  });

  it('member sees História zmien in the main nav and no Administrácia (NAV-002)', async () => {
    const w = await mountShell('MEMBER');
    const text = w.text();
    expect(text).not.toContain('Administrácia');
    expect(text).not.toContain('Nastavenia stránky');
    expect(count(text, 'História zmien')).toBe(1);
    expect(text).toContain('Expedície');
  });

  it('hides modules the site switched off', async () => {
    const off = await mountShell('MEMBER', { features: { paddlingTrafficLight: false, expeditions: false } });
    expect(off.text()).not.toContain('Vodácky semafor');
    expect(off.text()).not.toContain('Expedície');

    const on = await mountShell('MEMBER', { features: { paddlingTrafficLight: true, expeditions: true } });
    expect(on.text()).toContain('Vodácky semafor');
    expect(on.text()).toContain('Expedície');
  });

  it('renders external links from the site config and skips empty ones', async () => {
    const w = await mountShell(null, { rulesUrl: 'https://klub.example/poriadok', gdprNoticeUrl: null });
    expect(w.find('a[href="https://klub.example/poriadok"]').exists()).toBe(true);
    expect(w.text()).not.toContain('GDPR – Informačná povinnosť');
  });
});
