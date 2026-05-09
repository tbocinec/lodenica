import { http } from './http';
import type { Paginated, Resource, ResourceType } from './types';

export interface ListResourcesParams {
  page?: number;
  pageSize?: number;
  type?: ResourceType;
  isActive?: boolean;
  search?: string;
}

export interface CreateResourceInput {
  identifier: string;
  type: ResourceType;
  name: string;
  model?: string;
  color?: string;
  seats?: number;
  lengthCm?: number;
  weightKg?: number;
  note?: string;
  imageUrl?: string;
  isActive?: boolean;
}

export type UpdateResourceInput = Partial<Omit<CreateResourceInput, 'identifier' | 'type'>>;

export const resourcesApi = {
  async list(params: ListResourcesParams = {}): Promise<Paginated<Resource>> {
    const { data } = await http.get<Paginated<Resource>>('/resources', { params });
    return data;
  },
  async get(id: string): Promise<Resource> {
    const { data } = await http.get<Resource>(`/resources/${id}`);
    return data;
  },
  async create(input: CreateResourceInput): Promise<Resource> {
    const { data } = await http.post<Resource>('/resources', input);
    return data;
  },
  async update(id: string, input: UpdateResourceInput): Promise<Resource> {
    const { data } = await http.patch<Resource>(`/resources/${id}`, input);
    return data;
  },
  async deactivate(id: string): Promise<Resource> {
    const { data } = await http.patch<Resource>(`/resources/${id}/deactivate`);
    return data;
  },
  async activate(id: string): Promise<Resource> {
    const { data } = await http.patch<Resource>(`/resources/${id}/activate`);
    return data;
  },
  async remove(id: string): Promise<void> {
    await http.delete(`/resources/${id}`);
  },
};
