import { Injectable } from '@nestjs/common';
import { Prisma } from '@prisma/client';

import { PrismaService } from '../../../infrastructure/prisma/prisma.service';
import { Resource } from '../domain/resource.entity';
import {
  ResourceCreateInput,
  ResourceListOptions,
  ResourceRepository,
  ResourceUpdateInput,
} from '../domain/resource.repository';

@Injectable()
export class PrismaResourceRepository extends ResourceRepository {
  constructor(private readonly prisma: PrismaService) {
    super();
  }

  async create(input: ResourceCreateInput): Promise<Resource> {
    const row = await this.prisma.resource.create({ data: input });
    return Resource.fromPersistence(row);
  }

  async update(id: string, input: ResourceUpdateInput): Promise<Resource> {
    const row = await this.prisma.resource.update({ where: { id }, data: input });
    return Resource.fromPersistence(row);
  }

  async findById(id: string): Promise<Resource | null> {
    const row = await this.prisma.resource.findUnique({ where: { id } });
    return row ? Resource.fromPersistence(row) : null;
  }

  async findByIdentifier(identifier: string): Promise<Resource | null> {
    const row = await this.prisma.resource.findUnique({ where: { identifier } });
    return row ? Resource.fromPersistence(row) : null;
  }

  async list(options: ResourceListOptions): Promise<{ items: Resource[]; total: number }> {
    const where: Prisma.ResourceWhereInput = {};
    if (options.type) where.type = options.type;
    if (typeof options.isActive === 'boolean') where.isActive = options.isActive;
    if (options.search) {
      where.OR = [
        { name: { contains: options.search, mode: 'insensitive' } },
        { identifier: { contains: options.search, mode: 'insensitive' } },
        { model: { contains: options.search, mode: 'insensitive' } },
      ];
    }

    const [rows, total] = await this.prisma.$transaction([
      this.prisma.resource.findMany({
        where,
        orderBy: [{ type: 'asc' }, { identifier: 'asc' }],
        skip: options.skip,
        take: options.take,
      }),
      this.prisma.resource.count({ where }),
    ]);

    return { items: rows.map(Resource.fromPersistence), total };
  }

  async delete(id: string): Promise<void> {
    await this.prisma.resource.delete({ where: { id } });
  }

  async setActive(id: string, isActive: boolean): Promise<Resource> {
    const row = await this.prisma.resource.update({ where: { id }, data: { isActive } });
    return Resource.fromPersistence(row);
  }
}
