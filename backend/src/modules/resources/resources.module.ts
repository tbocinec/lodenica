import { Module } from '@nestjs/common';

import { ResourcesService } from './application/resources.service';
import { ResourceRepository } from './domain/resource.repository';
import { PrismaResourceRepository } from './infrastructure/prisma-resource.repository';
import { ResourcesController } from './presentation/resources.controller';

@Module({
  controllers: [ResourcesController],
  providers: [
    ResourcesService,
    { provide: ResourceRepository, useClass: PrismaResourceRepository },
  ],
  exports: [ResourcesService, ResourceRepository],
})
export class ResourcesModule {}
