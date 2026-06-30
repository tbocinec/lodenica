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
  /**
   * Confirmed member (MEMBER or ADMIN). PENDING + anonymous → false.
   * Permission gates across the SPA key off this getter — names,
   * contacts and reservation-edit actions are visible only when
   * `isMember` is true. See docs/AUTH-AND-PERMISSIONS.md.
   */
  const isMember = computed(
    () => user.value?.role === 'ADMIN' || user.value?.role === 'MEMBER',
  );
  const isPending = computed(() => user.value?.role === 'PENDING');

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
      setSession(res.token, res.user);
    } catch (e) {
      lastError.value = (e as Error).message;
      throw e;
    }
  }

  /** Public self-registration. The new account lands as PENDING. */
  async function register(
    name: string,
    email: string,
    password: string,
    consents: { dataConsent: boolean; rulesAck: boolean },
  ): Promise<void> {
    lastError.value = null;
    try {
      const res = await authApi.register(name, email, password, consents);
      setSession(res.token, res.user);
    } catch (e) {
      lastError.value = (e as Error).message;
      throw e;
    }
  }

  /** Consume a reset/invitation token + log in with the new password. */
  async function resetPassword(
    email: string,
    resetToken: string,
    password: string,
    consents?: { dataConsent: boolean; rulesAck: boolean },
  ): Promise<void> {
    const res = await authApi.resetPassword(email, resetToken, password, consents);
    setSession(res.token, res.user);
  }

  /** Adopt a token minted server-side (OAuth callback) and load the user. */
  async function applyToken(newToken: string): Promise<void> {
    token.value = newToken;
    writeStoredToken(newToken);
    user.value = await authApi.me();
  }

  function setSession(newToken: string, newUser: User): void {
    token.value = newToken;
    writeStoredToken(newToken);
    user.value = newUser;
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
    isMember,
    isPending,
    bootstrap,
    login,
    register,
    resetPassword,
    applyToken,
    setSession,
    logout,
    clearAuth,
  };
});
