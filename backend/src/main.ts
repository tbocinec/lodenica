import 'reflect-metadata';

import { ValidationPipe, VersioningType } from '@nestjs/common';
import { ConfigService } from '@nestjs/config';
import { NestFactory } from '@nestjs/core';
import { DocumentBuilder, SwaggerModule } from '@nestjs/swagger';
import { Logger } from 'nestjs-pino';

import { AppModule } from './app.module';
import { GlobalExceptionFilter } from './common/filters/global-exception.filter';
import type { AppConfig } from './config/app.config';

async function bootstrap() {
  const app = await NestFactory.create(AppModule, { bufferLogs: true });
  app.useLogger(app.get(Logger));

  const config = app.get(ConfigService);
  const corsOrigins = config.get<AppConfig['cors']['origins']>('cors.origins') ?? [
    'http://localhost:5173',
  ];
  const port = config.get<number>('port') ?? 3000;

  app.setGlobalPrefix('api', { exclude: ['health', 'docs', 'docs-json'] });
  app.enableVersioning({ type: VersioningType.URI, defaultVersion: '1' });

  app.enableCors({
    origin: corsOrigins,
    credentials: true,
  });

  app.useGlobalPipes(
    new ValidationPipe({
      whitelist: true,
      forbidNonWhitelisted: true,
      transform: true,
      transformOptions: { enableImplicitConversion: true },
    }),
  );

  app.useGlobalFilters(new GlobalExceptionFilter(app.get(Logger)));

  const swaggerConfig = new DocumentBuilder()
    .setTitle('Lodenica API')
    .setDescription('Boathouse management API — resources, reservations, damages, availability.')
    .setVersion('1.0.0')
    .addServer('/api/v1')
    .build();
  const document = SwaggerModule.createDocument(app, swaggerConfig);
  SwaggerModule.setup('docs', app, document, {
    jsonDocumentUrl: 'docs-json',
    swaggerOptions: { persistAuthorization: true },
  });

  app.enableShutdownHooks();

  await app.listen(port, '0.0.0.0');

  // eslint-disable-next-line no-console
  console.log(`Lodenica API listening on http://0.0.0.0:${port} (docs at /docs)`);
}

void bootstrap();
