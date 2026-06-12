import axios, { AxiosError, type AxiosInstance, type InternalAxiosRequestConfig } from 'axios';

import type { ApiErrorBody } from './types';

export class ApiError extends Error {
  readonly statusCode: number;
  readonly code?: string;
  readonly details?: unknown;

  constructor(body: ApiErrorBody) {
    super(Array.isArray(body.message) ? body.message.join('; ') : body.message);
    this.name = 'ApiError';
    this.statusCode = body.statusCode;
    this.code = body.code;
    this.details = body.details;
  }
}

const baseURL = (import.meta.env.VITE_API_BASE_URL ?? '/api/v1').replace(/\/+$/, '');

export const http: AxiosInstance = axios.create({
  baseURL,
  timeout: 15_000,
  withCredentials: false,
  headers: { 'Content-Type': 'application/json' },
});

/**
 * Auth token storage. Kept in sessionStorage (not localStorage) so closing
 * the browser tab clears the token — a reasonable tradeoff for an admin
 * tool on shared devices. The auth store mirrors this value into a Pinia
 * state so Vue components can react.
 */
const TOKEN_KEY = 'lodenica.auth.token';

export function readStoredToken(): string | null {
  try {
    return sessionStorage.getItem(TOKEN_KEY);
  } catch {
    return null;
  }
}

export function writeStoredToken(token: string | null): void {
  try {
    if (token) {
      sessionStorage.setItem(TOKEN_KEY, token);
    } else {
      sessionStorage.removeItem(TOKEN_KEY);
    }
  } catch {
    // Storage may be disabled (private browsing) — fail silently;
    // the Pinia store still holds the in-memory token for this session.
  }
}

/**
 * Hook the auth store provides so the http layer can clear auth state
 * and redirect on a 401, without importing pinia into this low-level
 * module (which would create a circular dependency).
 */
let on401: (() => void) | null = null;
export function registerUnauthorizedHandler(handler: () => void): void {
  on401 = handler;
}

http.interceptors.request.use((config: InternalAxiosRequestConfig) => {
  const token = readStoredToken();
  if (token) {
    config.headers.set('Authorization', `Bearer ${token}`);
  }
  return config;
});

http.interceptors.response.use(
  (res) => res,
  (err: AxiosError<ApiErrorBody>) => {
    if (err.response?.status === 401 && on401) {
      // Defer one tick so the failing call's catch handler still gets a
      // chance to see the rejected promise before navigation kicks in.
      queueMicrotask(() => on401?.());
    }
    if (err.response?.data && typeof err.response.data === 'object') {
      return Promise.reject(new ApiError(err.response.data));
    }
    return Promise.reject(
      new ApiError({
        statusCode: err.response?.status ?? 0,
        error: err.code ?? 'NETWORK_ERROR',
        message: err.message ?? 'Sieťová chyba',
      }),
    );
  },
);
