import { http } from './http';
import type { ResourceType } from './types';

export interface UsageStats {
  generatedAt: string;
  window: { fromIso: string; toIso: string; days: number };
  totals: {
    reservationsAllTime: number;
    activeResources: number;
    openDamages: number;
  };
  topResources: Array<{
    resourceId: string;
    identifier: string;
    name: string;
    type: ResourceType;
    count: number;
    totalHours: number;
  }>;
  coldResources: Array<{
    resourceId: string;
    identifier: string;
    name: string;
    type: ResourceType;
    count: number;
  }>;
  monthlyTrend: Array<{ monthIso: string; count: number }>;
  peakHours: { counts: number[][]; max: number };
  damagesByType: Array<{ type: ResourceType; count: number }>;
}

export const usageStatsApi = {
  async get(): Promise<UsageStats> {
    const { data } = await http.get<UsageStats>('/admin/usage-stats');
    return data;
  },
};
