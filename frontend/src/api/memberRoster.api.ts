import { http } from './http';
import type { MemberRosterEntry, Paginated } from './types';

export interface ListRosterParams {
  search?: string;
  registered?: boolean;
  page?: number;
  pageSize?: number;
}

export interface RosterImportResult {
  createdCount: number;
  skippedCount: number;
  invalidCount: number;
  skipped: string[];
  invalid: string[];
}

/** Admin-only member roster ("číselník") API. */
export const memberRosterApi = {
  async list(params: ListRosterParams = {}): Promise<Paginated<MemberRosterEntry>> {
    const { data } = await http.get<Paginated<MemberRosterEntry>>('/member-roster', { params });
    return data;
  },
  async create(input: { email: string; memberId?: string | null; name?: string | null }): Promise<MemberRosterEntry> {
    const { data } = await http.post<MemberRosterEntry>('/member-roster', input);
    return data;
  },
  async update(
    id: string,
    input: Partial<{ email: string; memberId: string | null; name: string | null }>,
  ): Promise<MemberRosterEntry> {
    const { data } = await http.patch<MemberRosterEntry>(`/member-roster/${id}`, input);
    return data;
  },
  async remove(id: string): Promise<void> {
    await http.delete(`/member-roster/${id}`);
  },
  async import(csv: string): Promise<RosterImportResult> {
    const { data } = await http.post<RosterImportResult>('/member-roster/import', { csv });
    return data;
  },
};
