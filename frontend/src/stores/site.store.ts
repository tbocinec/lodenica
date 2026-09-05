import { defineStore } from 'pinia';
import { computed, ref } from 'vue';

import { DEFAULT_SITE_CONFIG, siteApi, type SiteConfig } from '@/api/site.api';

/**
 * The installation's identity for the whole SPA (SITE-004: nothing about
 * the club is hard-coded; every view reads it from here).
 *
 * `load()` runs once at startup, in parallel with the auth bootstrap. The
 * last good payload is cached in localStorage so a reload paints the right
 * name before the API answers; when the API fails the cache (then the
 * built-in defaults) keeps the app usable.
 */
const CACHE_KEY = 'app.site';

function readCache(): SiteConfig | null {
  try {
    const raw = localStorage.getItem(CACHE_KEY);
    if (!raw) return null;
    const parsed = JSON.parse(raw) as Partial<SiteConfig>;
    // Merge over the defaults so a field added later is never undefined.
    return {
      ...DEFAULT_SITE_CONFIG,
      ...parsed,
      features: { ...DEFAULT_SITE_CONFIG.features, ...(parsed.features ?? {}) },
    };
  } catch {
    return null;
  }
}

function writeCache(config: SiteConfig): void {
  try {
    // Never cache the admin-only field, even when handed an AdminSiteConfig.
    const { adminEmail: _omit, ...rest } = config as SiteConfig & { adminEmail?: string | null };
    void _omit;
    localStorage.setItem(CACHE_KEY, JSON.stringify(rest));
  } catch {
    // storage unavailable — fine, we just refetch next time
  }
}

export const useSiteStore = defineStore('site', () => {
  const config = ref<SiteConfig>(readCache() ?? DEFAULT_SITE_CONFIG);
  /** True once `load()` finished (successfully or not). */
  const loaded = ref(false);

  const features = computed(() => config.value.features);

  async function load(): Promise<void> {
    try {
      const next = await siteApi.get();
      config.value = { ...DEFAULT_SITE_CONFIG, ...next };
      writeCache(config.value);
    } catch {
      // keep the cache / defaults
    } finally {
      loaded.value = true;
    }
  }

  /** Adopt a config returned by an admin save so the shell updates at once. */
  function apply(next: SiteConfig): void {
    config.value = { ...DEFAULT_SITE_CONFIG, ...next };
    writeCache(config.value);
  }

  return { config, loaded, features, load, apply };
});
