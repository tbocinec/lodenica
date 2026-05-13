import { Module } from '@nestjs/common';

import { ResourcesModule } from '../resources/resources.module';
import { ReservationsService } from './application/reservations.service';
import { ReservationRepository } from './domain/reservation.repository';
import { PrismaReservationRepository } from './infrastructure/prisma-reservation.repository';
import { ReservationsController } from './presentation/reservations.controller';

@Module({
  imports: [ResourcesModule],
  controllers: [ReservationsController],
  providers: [
    ReservationsService,
    { provide: ReservationRepository, useClass: PrismaReservationRepository },
  ],
  exports: [ReservationsService, ReservationRepository],
})
export class ReservationsModule {}
