import { Module } from '@nestjs/common';

import { AvailabilityService } from './application/availability.service';
import { AvailabilityController } from './presentation/availability.controller';

@Module({
  controllers: [AvailabilityController],
  providers: [AvailabilityService],
})
export class AvailabilityModule {}
