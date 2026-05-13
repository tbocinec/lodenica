import { ApiPropertyOptional, OmitType, PartialType } from '@nestjs/swagger';
import { ReservationStatus } from '@prisma/client';
import { IsEnum, IsOptional } from 'class-validator';

import { CreateReservationDto } from './create-reservation.dto';

export class UpdateReservationDto extends PartialType(
  OmitType(CreateReservationDto, ['resourceId'] as const),
) {
  @ApiPropertyOptional({ enum: ReservationStatus })
  @IsOptional()
  @IsEnum(ReservationStatus)
  status?: ReservationStatus;
}
