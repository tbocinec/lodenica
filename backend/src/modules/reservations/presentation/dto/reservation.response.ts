import { ApiProperty } from '@nestjs/swagger';
import { ReservationStatus } from '@prisma/client';

import { Reservation } from '../../domain/reservation.entity';

export class ReservationResponseDto {
  @ApiProperty() id!: string;
  @ApiProperty() resourceId!: string;
  @ApiProperty() customerName!: string;
  @ApiProperty({ nullable: true }) customerContact!: string | null;
  @ApiProperty({ type: String, format: 'date-time' }) startsAt!: Date;
  @ApiProperty({ type: String, format: 'date-time' }) endsAt!: Date;
  @ApiProperty({ nullable: true }) note!: string | null;
  @ApiProperty({ enum: ReservationStatus }) status!: ReservationStatus;
  @ApiProperty() createdAt!: Date;
  @ApiProperty() updatedAt!: Date;

  static from(r: Reservation): ReservationResponseDto {
    return r.toJSON();
  }
}
