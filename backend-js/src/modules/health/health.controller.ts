import { Controller, Get, HttpStatus, VERSION_NEUTRAL } from '@nestjs/common';
import { ApiOkResponse, ApiTags } from '@nestjs/swagger';

import { PrismaService } from '../../infrastructure/prisma/prisma.service';

@ApiTags('health')
@Controller({ path: 'health', version: VERSION_NEUTRAL })
export class HealthController {
  constructor(private readonly prisma: PrismaService) {}

  @Get()
  @ApiOkResponse({ description: 'Service liveness and database connectivity status.' })
  async check(): Promise<{ status: string; database: string; uptimeSeconds: number }> {
    let database: string;
    try {
      await this.prisma.$queryRaw`SELECT 1`;
      database = 'up';
    } catch {
      database = 'down';
    }
    return {
      status: database === 'up' ? 'ok' : 'degraded',
      database,
      uptimeSeconds: Math.floor(process.uptime()),
    };
  }

  @Get('ready')
  ready(): { ready: true } {
    return { ready: true };
  }

  @Get('live')
  live(): { live: true; statusCode: number } {
    return { live: true, statusCode: HttpStatus.OK };
  }
}
