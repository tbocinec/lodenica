import { http } from './http';
import type { Damage, DamageComment, DamageSeverity, DamageStatus, Paginated } from './types';

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
  /** Defaults server-side to the signed-in member's name when omitted. */
  reportedByName?: string | null;
  assigneeName?: string | null;
}

export type UpdateDamageInput = Partial<{
  description: string;
  severity: DamageSeverity;
  status: DamageStatus;
  note: string;
  reportedByName: string | null;
  assigneeName: string | null;
}>;

export const damagesApi = {
  async list(params: ListDamagesParams = {}): Promise<Paginated<Damage>> {
    const { data } = await http.get<Paginated<Damage>>('/damages', { params });
    return data;
  },
  async get(id: string): Promise<Damage> {
    const { data } = await http.get<Damage>(`/damages/${id}`);
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
  async uploadPhoto(id: string, file: File): Promise<Damage> {
    const form = new FormData();
    form.append('photo', file);
    const { data } = await http.post<Damage>(`/damages/${id}/photo`, form, {
      headers: { 'Content-Type': 'multipart/form-data' },
    });
    return data;
  },
  async removePhoto(id: string): Promise<void> {
    await http.delete(`/damages/${id}/photo`);
  },

  /* ── Comments (confirmed members only, reading included) ───────────── */

  async listComments(damageId: string): Promise<DamageComment[]> {
    const { data } = await http.get<{ items: DamageComment[] }>(
      `/damages/${damageId}/comments`,
    );
    return data.items;
  },
  async addComment(damageId: string, body: string): Promise<DamageComment> {
    const { data } = await http.post<DamageComment>(`/damages/${damageId}/comments`, { body });
    return data;
  },
  async removeComment(damageId: string, commentId: string): Promise<void> {
    await http.delete(`/damages/${damageId}/comments/${commentId}`);
  },
};
