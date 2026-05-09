import { Module } from '@nestjs/common';
import { ConfigModule } from '@nestjs/config';
import { LoggerModule } from 'nestjs-pino';

import { appConfig } from './config/app.config';
import { validateConfig } from './config/config.validation';
import { PrismaModule } from './infrastructure/prisma/prisma.module';
import { ResourcesModule } from './modules/resources/resources.module';
import { ReservationsModule } from './modules/reservations/reservations.module';
import { DamagesModule } from './modules/damages/damages.module';
import { AvailabilityModule } from './modules/availability/availability.module';
import { HealthModule } from './modules/health/health.module';

@Module({
  imports: [
    ConfigModule.forRoot({
      isGlobal: true,
      load: [appConfig],
      validate: validateConfig,
      cache: true,
    }),
    LoggerModule.forRootAsync({
      useFactory: () => ({
        pinoHttp: {
          level: process.env.LOG_LEVEL ?? 'info',
          transport:
            process.env.NODE_ENV !== 'production'
              ? { target: 'pino-pretty', options: { singleLine: true, colorize: true } }
              : undefined,
          autoLogging: { ignore: (req) => req.url === '/health' },
          redact: {
            paths: ['req.headers.authorization', 'req.headers.cookie'],
            remove: true,
          },
        },
      }),
    }),
    PrismaModule,
    HealthModule,
    ResourcesModule,
    ReservationsModule,
    DamagesModule,
    AvailabilityModule,
  ],
})
export class AppModule {}
