import { http } from './http';
import type { DashboardSnapshot } from './types';

export const availabilityApi = {
  async dashboard(): Promise<DashboardSnapshot> {
    const { data } = await http.get<DashboardSnapshot>('/availability/dashboard');
    return data;
  },
};
