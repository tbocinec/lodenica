import { Controller, Get } from '@nestjs/common';
import { ApiOkResponse, ApiOperation, ApiTags } from '@nestjs/swagger';

import { AvailabilityService, DashboardSnapshot } from '../application/availability.service';

@ApiTags('availability')
@Controller({ path: 'availability', version: '1' })
export class AvailabilityController {
  constructor(private readonly availability: AvailabilityService) {}

  @Get('dashboard')
  @ApiOperation({
    summary: 'Aggregated dashboard view: today/tomorrow occupancy, upcoming, available, damaged.',
  })
  @ApiOkResponse({ description: 'Dashboard snapshot.' })
  dashboard(): Promise<DashboardSnapshot> {
    return this.availability.snapshot();
  }
}
