import { http } from './http';
import type { BulkImportResult, Paginated, User, UserRole } from './types';

export interface ListUsersParams {
  role?: UserRole;
  isActive?: boolean;
  page?: number;
  pageSize?: number;
}

export interface CreateUserInput {
  name: string;
  email: string;
  password: string;
  role: UserRole;
  isActive?: boolean;
  /** Internal club member ID (admin-only), unique across users. */
  memberId?: string | null;
}

export type UpdateUserInput = Partial<Omit<CreateUserInput, 'password'>> & {
  password?: string;
  memberId?: string | null;
};

export const usersApi = {
  async list(params: ListUsersParams = {}): Promise<Paginated<User>> {
    const { data } = await http.get<Paginated<User>>('/users', { params });
    return data;
  },
  /** Admin-only: full user detail incl. linked identities + lifecycle dates. */
  async get(id: string): Promise<User> {
    const { data } = await http.get<User>(`/users/${id}`);
    return data;
  },
  /** Admin-only: unlink a social login (Google/Facebook) from a member. */
  async unlinkIdentity(id: string, provider: string): Promise<void> {
    await http.delete(`/users/${id}/identities/${provider}`);
  },
  async create(input: CreateUserInput): Promise<User> {
    const { data } = await http.post<User>('/users', input);
    return data;
  },
  async update(id: string, input: UpdateUserInput): Promise<User> {
    const { data } = await http.patch<User>(`/users/${id}`, input);
    return data;
  },
  async remove(id: string): Promise<void> {
    await http.delete(`/users/${id}`);
  },
  /**
   * Admin-only: promote a PENDING account to MEMBER. Idempotent for
   * accounts that are already MEMBER; 409 when the target is ADMIN.
   */
  async confirm(id: string, memberId?: string | null): Promise<User> {
    const { data } = await http.post<User>(`/users/${id}/confirm`, { memberId: memberId ?? null });
    return data;
  },
  /**
   * Admin-only: bulk-create confirmed (MEMBER) accounts from CSV text
   * ("name,email" rows). Each new account is emailed a set-your-password
   * invitation. Admin-invited accounts are auto-confirmed.
   */
  async bulkImport(csv: string): Promise<BulkImportResult> {
    const { data } = await http.post<BulkImportResult>('/users/import', { csv });
    return data;
  },
  /**
   * Admin-only: invite a single member by name + email (no password — they
   * set it via the emailed link). Auto-confirmed as MEMBER.
   */
  async invite(name: string, email: string, memberId?: string | null): Promise<User> {
    const { data } = await http.post<User>('/users/invite', {
      name,
      email,
      memberId: memberId || null,
    });
    return data;
  },
};
