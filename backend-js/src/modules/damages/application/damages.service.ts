import { Injectable } from '@nestjs/common';
import { Damage, DamageSeverity, DamageStatus, Prisma } from '@prisma/client';

import { NotFoundDomainError } from '../../../common/errors/domain.errors';
import { PrismaService } from '../../../infrastructure/prisma/prisma.service';
import { ResourceRepository } from '../../resources/domain/resource.repository';

export interface CreateDamageCommand {
  resourceId: string;
  description: string;
  severity: DamageSeverity;
  note?: string | null;
}

export interface UpdateDamageCommand {
  description?: string;
  severity?: DamageSeverity;
  status?: DamageStatus;
  note?: string | null;
}

export interface ListDamagesOptions {
  resourceId?: string;
  status?: DamageStatus;
  skip?: number;
  take?: number;
}

/**
 * Damages stay close to Prisma intentionally — there is no rich domain logic
 * here beyond status transitions, so a thin service over the ORM is the right
 * level of abstraction. If transitions grow complex, extract a domain entity.
 */
@Injectable()
export class DamagesService {
  constructor(
    private readonly prisma: PrismaService,
    private readonly resources: ResourceRepository,
  ) {}

  async create(cmd: CreateDamageCommand): Promise<Damage> {
    const resource = await this.resources.findById(cmd.resourceId);
    if (!resource) {
      throw new NotFoundDomainError('Resource', cmd.resourceId);
    }
    return this.prisma.damage.create({
      data: {
        resourceId: cmd.resourceId,
        description: cmd.description,
        severity: cmd.severity,
        note: cmd.note ?? null,
        status: DamageStatus.REPORTED,
      },
    });
  }

  async update(id: string, cmd: UpdateDamageCommand): Promise<Damage> {
    await this.requireExisting(id);
    const data: Prisma.DamageUpdateInput = {
      description: cmd.description,
      severity: cmd.severity,
      status: cmd.status,
      note: cmd.note,
    };
    if (cmd.status === DamageStatus.FIXED) {
      data.fixedAt = new Date();
    }
    return this.prisma.damage.update({ where: { id }, data });
  }

  async findById(id: string): Promise<Damage> {
    return this.requireExisting(id);
  }

  async list(opts: ListDamagesOptions): Promise<{ items: Damage[]; total: number }> {
    const where: Prisma.DamageWhereInput = {};
    if (opts.resourceId) where.resourceId = opts.resourceId;
    if (opts.status) where.status = opts.status;

    const [items, total] = await this.prisma.$transaction([
      this.prisma.damage.findMany({
        where,
        orderBy: [{ status: 'asc' }, { reportedAt: 'desc' }],
        skip: opts.skip,
        take: opts.take,
      }),
      this.prisma.damage.count({ where }),
    ]);
    return { items, total };
  }

  async remove(id: string): Promise<void> {
    await this.requireExisting(id);
    await this.prisma.damage.delete({ where: { id } });
  }

  private async requireExisting(id: string): Promise<Damage> {
    const damage = await this.prisma.damage.findUnique({ where: { id } });
    if (!damage) {
      throw new NotFoundDomainError('Damage', id);
    }
    return damage;
  }
}
