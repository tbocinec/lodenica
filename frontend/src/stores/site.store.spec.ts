import { createPinia, setActivePinia } from 'pinia';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import { DEFAULT_SITE_CONFIG } from '@/api/site.api';

import { useSiteStore } from './site.store';

const get = vi.fn();

vi.mock('@/api/site.api', async (importOriginal) => ({
  ...(await importOriginal<typeof import('@/api/site.api')>()),
  siteApi: { get: () => get() },
}));

describe('site store', () => {
  beforeEach(() => {
    setActivePinia(createPinia());
    localStorage.clear();
    get.mockReset();
  });

  it('starts with the built-in defaults', () => {
    const s = useSiteStore();
    expect(s.config.shortName).toBe(DEFAULT_SITE_CONFIG.shortName);
    expect(s.config.features.expeditions).toBe(true);
    expect(s.loaded).toBe(false);
  });

  it('loads the config and caches it', async () => {
    get.mockResolvedValue({ ...DEFAULT_SITE_CONFIG, siteName: 'Klub Test', shortName: 'KT' });
    const s = useSiteStore();

    await s.load();

    expect(s.config.shortName).toBe('KT');
    expect(s.loaded).toBe(true);
    expect(JSON.parse(localStorage.getItem('app.site') ?? '{}').siteName).toBe('Klub Test');
  });

  it('falls back to the cache, then the defaults, when the API fails', async () => {
    localStorage.setItem('app.site', JSON.stringify({ shortName: 'Cached' }));
    get.mockRejectedValue(new Error('offline'));
    const s = useSiteStore();

    expect(s.config.shortName).toBe('Cached');
    expect(s.config.features.paddlingTrafficLight).toBe(false); // merged over defaults

    await s.load();

    expect(s.config.shortName).toBe('Cached');
    expect(s.loaded).toBe(true);
  });

  it('apply() adopts an admin save without caching adminEmail', () => {
    const s = useSiteStore();

    s.apply({ ...DEFAULT_SITE_CONFIG, shortName: 'Saved', adminEmail: 'a@b.test' } as never);

    expect(s.config.shortName).toBe('Saved');
    expect(localStorage.getItem('app.site')).not.toContain('a@b.test');
  });
});
