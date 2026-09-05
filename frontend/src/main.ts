import { createPinia } from 'pinia';
import { createApp } from 'vue';

import { usageApi } from './api/usage.api';
import App from './App.vue';
import { router } from './router';
import { useAuthStore } from './stores/auth.store';
import { useSiteStore } from './stores/site.store';
import { applyTheme, readCachedTheme } from './theme/themes';
import './styles/main.css';

const app = createApp(App);
const pinia = createPinia();
app.use(pinia);
app.use(router);

/** Anonymous usage beacon: one pageview per load, one "visit" per session
 *  (sessionStorage, no persistent identity). Best-effort. */
function trackVisit(): void {
  let first = false;
  try {
    first = !sessionStorage.getItem('app.visit');
    if (first) sessionStorage.setItem('app.visit', '1');
  } catch {
    /* sessionStorage unavailable (private mode) — count as a non-first load */
  }
  // Still beacon even if sessionStorage threw, so pageviews aren't lost.
  usageApi.trackVisit(first);
}

// Validate any stored bearer token and load the site identity before
// mounting, so the first render already knows who we are and whose club
// this is. Either failure is tolerated — /auth/me clears the local session,
// /site falls back to the cached / default config.
const auth = useAuthStore(pinia);
const site = useSiteStore(pinia);
// Paint the last known theme immediately; useSiteChrome() refines it once
// the user and site config are known.
applyTheme(readCachedTheme() ?? site.config.theme);
Promise.all([auth.bootstrap(), site.load()]).finally(() => {
  app.mount('#app');
  trackVisit();
});
