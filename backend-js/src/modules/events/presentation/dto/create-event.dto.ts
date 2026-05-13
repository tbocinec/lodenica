import { ApiProperty, ApiPropertyOptional } from '@nestjs/swagger';
import { IsDateString, IsOptional, IsString, Length } from 'class-validator';

export class CreateEventDto {
  @ApiProperty({ example: 'Splav Dunaja' })
  @IsString()
  @Length(1, 200)
  title!: string;

  @ApiPropertyOptional()
  @IsOptional()
  @IsString()
  @Length(0, 2000)
  description?: string;

  @ApiPropertyOptional({ example: 'Devín' })
  @IsOptional()
  @IsString()
  @Length(0, 200)
  location?: string;

  @ApiProperty({
    description: 'Start instant in ISO 8601.',
    example: '2026-06-15T08:00:00.000Z',
  })
  @IsDateString()
  startsAt!: string;

  @ApiProperty({
    description: 'End instant in ISO 8601 (exclusive). Must be strictly after startsAt.',
    example: '2026-06-15T18:00:00.000Z',
  })
  @IsDateString()
  endsAt!: string;
}
