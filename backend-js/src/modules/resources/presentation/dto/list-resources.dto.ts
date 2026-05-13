import { ApiPropertyOptional } from '@nestjs/swagger';
import { ResourceType } from '@prisma/client';
import { Transform } from 'class-transformer';
import { IsBoolean, IsEnum, IsOptional, IsString, Length } from 'class-validator';

import { PaginationQueryDto } from '../../../../common/dto/pagination.dto';

export class ListResourcesQueryDto extends PaginationQueryDto {
  @ApiPropertyOptional({ enum: ResourceType })
  @IsOptional()
  @IsEnum(ResourceType)
  type?: ResourceType;

  @ApiPropertyOptional()
  @IsOptional()
  @Transform(({ value }) => (value === 'true' || value === true ? true : value === 'false' || value === false ? false : undefined))
  @IsBoolean()
  isActive?: boolean;

  @ApiPropertyOptional({ description: 'Free-text search across name, identifier, model.' })
  @IsOptional()
  @IsString()
  @Length(0, 200)
  search?: string;
}
