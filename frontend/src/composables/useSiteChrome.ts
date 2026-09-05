import { watchEffect } from 'vue';

import { useAuthStore } from '@/stores/auth.store';
import { useSiteStore } from '@/stores/site.store';
import { applyTheme, resolveTheme, writeCachedTheme } from '@/theme/themes';

/** Bundled neutral icon used until a club uploads its own logo. */
export const DEFAULT_LOGO = '/favicon.svg';

/**
 * Keeps the browser chrome in step with the site config and the user:
 * colour theme (THEME-001: user's own → site default) and the favicon.
 * Called once from App.vue.
 */
export function useSiteChrome(): void {
  const auth = useAuthStore();
  const site = useSiteStore();

  watchEffect(() => {
    // Wait for both bootstraps so we never flash the site default at a
    // user who picked their own theme.
    if (auth.initializing || !site.loaded) return;
    writeCachedTheme(applyTheme(resolveTheme(auth.user?.theme, site.config.theme)));
  });

  watchEffect(() => {
    if (typeof document === 'undefined') return;
    const href = site.config.logoUrl ?? DEFAULT_LOGO;
    let link = document.querySelector<HTMLLinkElement>('link[rel="icon"]');
    if (!link) {
      link = document.createElement('link');
      link.rel = 'icon';
      document.head.appendChild(link);
    }
    if (link.getAttribute('href') !== href) {
      link.setAttribute('href', href);
      link.setAttribute('type', href.endsWith('.svg') ? 'image/svg+xml' : 'image/png');
    }
  });
}
