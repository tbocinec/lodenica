import { http } from './http';
import type { LoginResponse, User } from './types';

export const authApi = {
  async login(email: string, password: string): Promise<LoginResponse> {
    const { data } = await http.post<LoginResponse>('/auth/login', { email, password });
    return data;
  },
  async logout(): Promise<void> {
    await http.post('/auth/logout');
  },
  async me(): Promise<User> {
    const { data } = await http.get<User>('/auth/me');
    return data;
  },
};
