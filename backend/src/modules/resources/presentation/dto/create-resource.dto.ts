import { ApiProperty, ApiPropertyOptional } from '@nestjs/swagger';
import { ResourceType } from '@prisma/client';
import {
  IsBoolean,
  IsEnum,
  IsInt,
  IsOptional,
  IsString,
  IsUrl,
  Length,
  Matches,
  Max,
  Min,
} from 'class-validator';

export class CreateResourceDto {
  @ApiProperty({ example: 'K-001', description: 'Unique identifier (e.g. boat number).' })
  @IsString()
  @Length(1, 50)
  @Matches(/^[A-Z0-9-_.]+$/i, { message: 'identifier must be alphanumeric (with -, _, .)' })
  identifier!: string;

  @ApiProperty({ enum: ResourceType })
  @IsEnum(ResourceType)
  type!: ResourceType;

  @ApiProperty({ example: 'Kajak Pyranha #1' })
  @IsString()
  @Length(1, 200)
  name!: string;

  @ApiPropertyOptional({ example: 'Pyranha Burn' })
  @IsOptional()
  @IsString()
  @Length(1, 200)
  model?: string;

  @ApiPropertyOptional({ example: 'red' })
  @IsOptional()
  @IsString()
  @Length(1, 50)
  color?: string;

  @ApiPropertyOptional({ minimum: 1, maximum: 20 })
  @IsOptional()
  @IsInt()
  @Min(1)
  @Max(20)
  seats?: number;

  @ApiPropertyOptional({ minimum: 1, maximum: 2000, description: 'Length in centimeters.' })
  @IsOptional()
  @IsInt()
  @Min(1)
  @Max(2000)
  lengthCm?: number;

  @ApiPropertyOptional({ minimum: 1, maximum: 5000, description: 'Weight in kilograms.' })
  @IsOptional()
  @IsInt()
  @Min(1)
  @Max(5000)
  weightKg?: number;

  @ApiPropertyOptional()
  @IsOptional()
  @IsString()
  @Length(0, 1000)
  note?: string;

  @ApiPropertyOptional()
  @IsOptional()
  @IsUrl({ require_tld: false })
  imageUrl?: string;

  @ApiPropertyOptional({ default: true })
  @IsOptional()
  @IsBoolean()
  isActive?: boolean;
}
