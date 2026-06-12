import { defineStore } from 'pinia';
import { computed, ref } from 'vue';

import { authApi } from '@/api/auth.api';
import { readStoredToken, registerUnauthorizedHandler, writeStoredToken } from '@/api/http';
import type { User } from '@/api/types';

/**
 * Auth state for the SPA. Holds the bearer token + the logged-in user.
 * The token is mirrored to sessionStorage by the http layer so a page
 * refresh keeps the session.
 *
 * `bootstrap()` runs once on app startup: if there's a stored token we
 * call `/auth/me` to validate it and load the user record. A failed call
 * (token expired/revoked) clears the auth state silently — the router
 * guard handles redirecting to /login when needed.
 */
export const useAuthStore = defineStore('auth', () => {
  const token = ref<string | null>(readStoredToken());
  const user = ref<User | null>(null);
  const initializing = ref(true);
  const lastError = ref<string | null>(null);

  const isAuthenticated = computed(() => token.value !== null && user.value !== null);
  const isAdmin = computed(() => user.value?.role === 'ADMIN');

  async function bootstrap(): Promise<void> {
    initializing.value = true;
    if (!token.value) {
      initializing.value = false;
      return;
    }
    try {
      user.value = await authApi.me();
    } catch {
      clearAuth();
    } finally {
      initializing.value = false;
    }
  }

  async function login(email: string, password: string): Promise<void> {
    lastError.value = null;
    try {
      const res = await authApi.login(email, password);
      token.value = res.token;
      writeStoredToken(res.token);
      user.value = res.user;
    } catch (e) {
      lastError.value = (e as Error).message;
      throw e;
    }
  }

  async function logout(): Promise<void> {
    // Best-effort server-side revoke. Don't block local logout on it —
    // the user clicked logout, so the local session has to clear even
    // if the server is unreachable.
    try {
      if (token.value) await authApi.logout();
    } catch {
      // ignore
    }
    clearAuth();
  }

  function clearAuth(): void {
    token.value = null;
    user.value = null;
    writeStoredToken(null);
  }

  // Wire the http 401 handler: any future 401 (revoked token, expired
  // session) clears local auth so the router guard redirects to /login.
  registerUnauthorizedHandler(() => clearAuth());

  return {
    token,
    user,
    initializing,
    lastError,
    isAuthenticated,
    isAdmin,
    bootstrap,
    login,
    logout,
    clearAuth,
  };
});
