import { Injectable } from '@nestjs/common';

import { NotFoundDomainError } from '../../../common/errors/domain.errors';
import { Resource } from '../domain/resource.entity';
import {
  ResourceCreateInput,
  ResourceListOptions,
  ResourceRepository,
  ResourceUpdateInput,
} from '../domain/resource.repository';

/**
 * Application service for resource lifecycle. Pure orchestration over the
 * repository port; framework-free so it can be unit-tested with an in-memory
 * fake repository.
 */
@Injectable()
export class ResourcesService {
  constructor(private readonly repo: ResourceRepository) {}

  create(input: ResourceCreateInput): Promise<Resource> {
    return this.repo.create(input);
  }

  async update(id: string, input: ResourceUpdateInput): Promise<Resource> {
    await this.requireExisting(id);
    return this.repo.update(id, input);
  }

  async findById(id: string): Promise<Resource> {
    return this.requireExisting(id);
  }

  list(options: ResourceListOptions): Promise<{ items: Resource[]; total: number }> {
    return this.repo.list(options);
  }

  async delete(id: string): Promise<void> {
    await this.requireExisting(id);
    await this.repo.delete(id);
  }

  async setActive(id: string, isActive: boolean): Promise<Resource> {
    await this.requireExisting(id);
    return this.repo.setActive(id, isActive);
  }

  private async requireExisting(id: string): Promise<Resource> {
    const found = await this.repo.findById(id);
    if (!found) {
      throw new NotFoundDomainError('Resource', id);
    }
    return found;
  }
}
