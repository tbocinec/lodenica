import { http } from './http';

/**
 * Anonymous usage beacon. Fire-and-forget — failures are swallowed so a
 * tracking hiccup never affects the user. Called once per SPA load from
 * main.ts; `firstInSession` marks the first load of a browser session.
 */
export const usageApi = {
  trackVisit(firstInSession: boolean): void {
    void http.post('/usage/visit', { firstInSession }).catch(() => {
      /* ignore — tracking is best-effort */
    });
  },
};
