import { Module } from '@nestjs/common';

import { ReservationsModule } from '../reservations/reservations.module';
import { EventsService } from './application/events.service';
import { EventRepository } from './domain/event.repository';
import { PrismaEventRepository } from './infrastructure/prisma-event.repository';
import { EventsController } from './presentation/events.controller';

@Module({
  imports: [ReservationsModule],
  controllers: [EventsController],
  providers: [
    EventsService,
    { provide: EventRepository, useClass: PrismaEventRepository },
  ],
  exports: [EventsService, EventRepository],
})
export class EventsModule {}
