import { ApiPropertyOptional } from '@nestjs/swagger';
import { IsDateString, IsOptional } from 'class-validator';

import { PaginationQueryDto } from '../../../../common/dto/pagination.dto';

export class ListEventsQueryDto extends PaginationQueryDto {
  @ApiPropertyOptional({
    description: 'Inclusive lower bound (ISO datetime). Filters events overlapping [from, to].',
  })
  @IsOptional()
  @IsDateString()
  from?: string;

  @ApiPropertyOptional({ description: 'Exclusive upper bound (ISO datetime).' })
  @IsOptional()
  @IsDateString()
  to?: string;
}
