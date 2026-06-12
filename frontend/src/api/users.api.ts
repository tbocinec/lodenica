import { http } from './http';
import type { Paginated, User, UserRole } from './types';

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
}

export type UpdateUserInput = Partial<Omit<CreateUserInput, 'password'>> & {
  password?: string;
};

export const usersApi = {
  async list(params: ListUsersParams = {}): Promise<Paginated<User>> {
    const { data } = await http.get<Paginated<User>>('/users', { params });
    return data;
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
};
