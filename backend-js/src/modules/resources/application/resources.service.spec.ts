import { ResourceType } from '@prisma/client';
import { randomUUID } from 'node:crypto';

import { NotFoundDomainError } from '../../../common/errors/domain.errors';
import { Resource, ResourceProps } from '../domain/resource.entity';
import {
  ResourceCreateInput,
  ResourceListOptions,
  ResourceRepository,
  ResourceUpdateInput,
} from '../domain/resource.repository';
import { ResourcesService } from './resources.service';

class InMemoryResourceRepo extends ResourceRepository {
  private items = new Map<string, Resource>();

  private build(props: Partial<ResourceProps> & Pick<ResourceProps, 'id'>): Resource {
    const now = new Date();
    const full: ResourceProps = {
      identifier: 'X',
      type: ResourceType.KAYAK,
      name: 'Test',
      model: null,
      color: null,
      seats: null,
      lengthCm: null,
      weightKg: null,
      note: null,
      imageUrl: null,
      isActive: true,
      createdAt: now,
      updatedAt: now,
      ...props,
    };
    return Resource.fromPersistence(full);
  }

  async create(input: ResourceCreateInput): Promise<Resource> {
    const r = this.build({
      id: randomUUID(),
      identifier: input.identifier,
      type: input.type,
      name: input.name,
      isActive: input.isActive ?? true,
    });
    this.items.set(r.id, r);
    return r;
  }
  async update(id: string, input: ResourceUpdateInput): Promise<Resource> {
    const cur = this.items.get(id);
    if (!cur) throw new Error('not found');
    const updated = this.build({ ...cur.toJSON(), ...input, id });
    this.items.set(id, updated);
    return updated;
  }
  async findById(id: string): Promise<Resource | null> {
    return this.items.get(id) ?? null;
  }
  async findByIdentifier(identifier: string): Promise<Resource | null> {
    for (const r of this.items.values()) if (r.identifier === identifier) return r;
    return null;
  }
  async list(_o: ResourceListOptions) {
    return { items: [...this.items.values()], total: this.items.size };
  }
  async delete(id: string): Promise<void> {
    this.items.delete(id);
  }
  async setActive(id: string, isActive: boolean): Promise<Resource> {
    return this.update(id, { isActive });
  }
}

describe('ResourcesService', () => {
  let repo: InMemoryResourceRepo;
  let service: ResourcesService;

  beforeEach(() => {
    repo = new InMemoryResourceRepo();
    service = new ResourcesService(repo);
  });

  it('creates and reads a resource', async () => {
    const created = await service.create({
      identifier: 'K-100',
      type: ResourceType.KAYAK,
      name: 'Kayak',
    });
    const fetched = await service.findById(created.id);
    expect(fetched.identifier).toBe('K-100');
  });

  it('throws NotFoundDomainError on missing id', async () => {
    await expect(service.findById(randomUUID())).rejects.toBeInstanceOf(NotFoundDomainError);
  });

  it('deactivates a resource', async () => {
    const created = await service.create({
      identifier: 'K-101',
      type: ResourceType.KAYAK,
      name: 'Kayak',
    });
    const result = await service.setActive(created.id, false);
    expect(result.isActive).toBe(false);
  });
});
