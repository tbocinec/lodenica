import { http } from './http';
import type { CaptchaChallenge, LoginResponse, OAuthProviderInfo, User } from './types';

export const authApi = {
  async login(email: string, password: string): Promise<LoginResponse> {
    const { data } = await http.post<LoginResponse>('/auth/login', { email, password });
    return data;
  },
  async register(name: string, email: string, password: string): Promise<LoginResponse> {
    const { data } = await http.post<LoginResponse>('/auth/register', { name, email, password });
    return data;
  },
  async logout(): Promise<void> {
    await http.post('/auth/logout');
  },
  async me(): Promise<User> {
    const { data } = await http.get<User>('/auth/me');
    return data;
  },
  async captcha(): Promise<CaptchaChallenge> {
    const { data } = await http.get<CaptchaChallenge>('/auth/captcha');
    return data;
  },
  async forgotPassword(
    email: string,
    captchaAnswer: string,
    captchaToken: string,
  ): Promise<{ message: string }> {
    const { data } = await http.post<{ message: string }>('/auth/forgot-password', {
      email,
      captchaAnswer,
      captchaToken,
    });
    return data;
  },
  async resetPassword(email: string, token: string, password: string): Promise<LoginResponse> {
    const { data } = await http.post<LoginResponse>('/auth/reset-password', {
      email,
      token,
      password,
    });
    return data;
  },
  /** Live social-login providers. Empty while OAuth is dormant. */
  async providers(): Promise<OAuthProviderInfo[]> {
    const { data } = await http.get<{ providers: OAuthProviderInfo[] }>('/auth/providers');
    return data.providers;
  },
};
