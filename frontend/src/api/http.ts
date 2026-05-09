import axios, { AxiosError, type AxiosInstance } from 'axios';

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

http.interceptors.response.use(
  (res) => res,
  (err: AxiosError<ApiErrorBody>) => {
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
