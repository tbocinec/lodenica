import { createPinia } from 'pinia';
import { createApp } from 'vue';

import { usageApi } from './api/usage.api';
import App from './App.vue';
import { router } from './router';
import { useAuthStore } from './stores/auth.store';
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
    first = !sessionStorage.getItem('kvs_visit');
    if (first) sessionStorage.setItem('kvs_visit', '1');
  } catch {
    /* sessionStorage unavailable (private mode) — count as a non-first load */
  }
  // Still beacon even if sessionStorage threw, so pageviews aren't lost.
  usageApi.trackVisit(first);
}

// Validate any stored bearer token before mounting so the first render
// already knows whether we're authenticated. Either way mount the app —
// /auth/me failure simply clears the local session.
const auth = useAuthStore(pinia);
auth.bootstrap().finally(() => {
  app.mount('#app');
  trackVisit();
});
