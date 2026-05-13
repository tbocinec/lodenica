import { ResourceType } from '@prisma/client';

import { Resource } from './resource.entity';

export interface ResourceFilter {
  type?: ResourceType;
  isActive?: boolean;
  search?: string;
}

export interface ResourceListOptions extends ResourceFilter {
  skip?: number;
  take?: number;
}

export interface ResourceCreateInput {
  identifier: string;
  type: ResourceType;
  name: string;
  model?: string | null;
  color?: string | null;
  seats?: number | null;
  lengthCm?: number | null;
  weightKg?: number | null;
  note?: string | null;
  imageUrl?: string | null;
  isActive?: boolean;
}

export type ResourceUpdateInput = Partial<ResourceCreateInput>;

/**
 * Port for resource persistence. Adapters live in `infrastructure/`.
 * Use cases depend on this interface, not on Prisma directly — keeps the
 * domain testable without a database.
 */
export abstract class ResourceRepository {
  abstract create(input: ResourceCreateInput): Promise<Resource>;
  abstract update(id: string, input: ResourceUpdateInput): Promise<Resource>;
  abstract findById(id: string): Promise<Resource | null>;
  abstract findByIdentifier(identifier: string): Promise<Resource | null>;
  abstract list(options: ResourceListOptions): Promise<{ items: Resource[]; total: number }>;
  abstract delete(id: string): Promise<void>;
  abstract setActive(id: string, isActive: boolean): Promise<Resource>;
}
