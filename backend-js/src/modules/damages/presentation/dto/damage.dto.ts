import { ApiProperty, ApiPropertyOptional, OmitType, PartialType } from '@nestjs/swagger';
import { Damage, DamageSeverity, DamageStatus } from '@prisma/client';
import { IsEnum, IsOptional, IsString, IsUUID, Length } from 'class-validator';

import { PaginationQueryDto } from '../../../../common/dto/pagination.dto';

export class CreateDamageDto {
  @ApiProperty()
  @IsUUID()
  resourceId!: string;

  @ApiProperty()
  @IsString()
  @Length(1, 1000)
  description!: string;

  @ApiProperty({ enum: DamageSeverity })
  @IsEnum(DamageSeverity)
  severity!: DamageSeverity;

  @ApiPropertyOptional()
  @IsOptional()
  @IsString()
  @Length(0, 1000)
  note?: string;
}

export class UpdateDamageDto extends PartialType(
  OmitType(CreateDamageDto, ['resourceId'] as const),
) {
  @ApiPropertyOptional({ enum: DamageStatus })
  @IsOptional()
  @IsEnum(DamageStatus)
  status?: DamageStatus;
}

export class ListDamagesQueryDto extends PaginationQueryDto {
  @ApiPropertyOptional()
  @IsOptional()
  @IsUUID()
  resourceId?: string;

  @ApiPropertyOptional({ enum: DamageStatus })
  @IsOptional()
  @IsEnum(DamageStatus)
  status?: DamageStatus;
}

export class DamageResponseDto {
  @ApiProperty() id!: string;
  @ApiProperty() resourceId!: string;
  @ApiProperty() description!: string;
  @ApiProperty({ enum: DamageSeverity }) severity!: DamageSeverity;
  @ApiProperty({ enum: DamageStatus }) status!: DamageStatus;
  @ApiProperty() reportedAt!: Date;
  @ApiProperty({ nullable: true }) fixedAt!: Date | null;
  @ApiProperty({ nullable: true }) note!: string | null;
  @ApiProperty() createdAt!: Date;
  @ApiProperty() updatedAt!: Date;

  static from(d: Damage): DamageResponseDto {
    return d as DamageResponseDto;
  }
}
