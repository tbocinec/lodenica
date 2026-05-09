import { Module } from '@nestjs/common';

import { ResourcesModule } from '../resources/resources.module';
import { DamagesService } from './application/damages.service';
import { DamagesController } from './presentation/damages.controller';

@Module({
  imports: [ResourcesModule],
  controllers: [DamagesController],
  providers: [DamagesService],
  exports: [DamagesService],
})
export class DamagesModule {}
