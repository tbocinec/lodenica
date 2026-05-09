import { http } from './http';
import type { Damage, DamageSeverity, DamageStatus, Paginated } from './types';

export interface ListDamagesParams {
  page?: number;
  pageSize?: number;
  resourceId?: string;
  status?: DamageStatus;
}

export interface CreateDamageInput {
  resourceId: string;
  description: string;
  severity: DamageSeverity;
  note?: string;
}

export type UpdateDamageInput = Partial<{
  description: string;
  severity: DamageSeverity;
  status: DamageStatus;
  note: string;
}>;

export const damagesApi = {
  async list(params: ListDamagesParams = {}): Promise<Paginated<Damage>> {
    const { data } = await http.get<Paginated<Damage>>('/damages', { params });
    return data;
  },
  async create(input: CreateDamageInput): Promise<Damage> {
    const { data } = await http.post<Damage>('/damages', input);
    return data;
  },
  async update(id: string, input: UpdateDamageInput): Promise<Damage> {
    const { data } = await http.patch<Damage>(`/damages/${id}`, input);
    return data;
  },
  async remove(id: string): Promise<void> {
    await http.delete(`/damages/${id}`);
  },
};
