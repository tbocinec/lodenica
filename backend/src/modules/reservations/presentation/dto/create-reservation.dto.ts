import { ApiProperty, ApiPropertyOptional } from '@nestjs/swagger';
import { IsDateString, IsOptional, IsString, IsUUID, Length } from 'class-validator';

export class CreateReservationDto {
  @ApiProperty({ description: 'UUID of the reservable resource.' })
  @IsUUID()
  resourceId!: string;

  @ApiPropertyOptional({ description: 'Optional UUID of the boathouse event this reservation belongs to.' })
  @IsOptional()
  @IsUUID()
  eventId?: string;

  @ApiProperty({ example: 'Ján Novák' })
  @IsString()
  @Length(1, 200)
  customerName!: string;

  @ApiPropertyOptional({ description: 'Email or phone — free-text contact.' })
  @IsOptional()
  @IsString()
  @Length(0, 200)
  customerContact?: string;

  @ApiProperty({
    description: 'Start instant in ISO 8601.',
    example: '2026-05-09T09:00:00.000Z',
  })
  @IsDateString()
  startsAt!: string;

  @ApiProperty({
    description: 'End instant in ISO 8601 (exclusive). Must be strictly after startsAt.',
    example: '2026-05-09T12:00:00.000Z',
  })
  @IsDateString()
  endsAt!: string;

  @ApiPropertyOptional()
  @IsOptional()
  @IsString()
  @Length(0, 1000)
  note?: string;
}
