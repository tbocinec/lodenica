import {
  Body,
  Controller,
  Delete,
  Get,
  HttpCode,
  HttpStatus,
  Param,
  ParseUUIDPipe,
  Patch,
  Post,
  Query,
} from '@nestjs/common';
import {
  ApiCreatedResponse,
  ApiNoContentResponse,
  ApiOkResponse,
  ApiOperation,
  ApiTags,
} from '@nestjs/swagger';

import { paginated, PaginatedResponseDto } from '../../../common/dto/pagination.dto';
import { DamagesService } from '../application/damages.service';
import {
  CreateDamageDto,
  DamageResponseDto,
  ListDamagesQueryDto,
  UpdateDamageDto,
} from './dto/damage.dto';

@ApiTags('damages')
@Controller({ path: 'damages', version: '1' })
export class DamagesController {
  constructor(private readonly damages: DamagesService) {}

  @Post()
  @ApiOperation({ summary: 'Report a new damage on a resource.' })
  @ApiCreatedResponse({ type: DamageResponseDto })
  async create(@Body() dto: CreateDamageDto): Promise<DamageResponseDto> {
    return DamageResponseDto.from(await this.damages.create(dto));
  }

  @Get()
  @ApiOperation({ summary: 'List damages, optionally filtered by resource or status.' })
  @ApiOkResponse({ type: PaginatedResponseDto<DamageResponseDto> })
  async list(
    @Query() query: ListDamagesQueryDto,
  ): Promise<PaginatedResponseDto<DamageResponseDto>> {
    const { items, total } = await this.damages.list({
      resourceId: query.resourceId,
      status: query.status,
      skip: (query.page - 1) * query.pageSize,
      take: query.pageSize,
    });
    return paginated(items.map(DamageResponseDto.from), total, query.page, query.pageSize);
  }

  @Get(':id')
  @ApiOperation({ summary: 'Get a damage record.' })
  @ApiOkResponse({ type: DamageResponseDto })
  async findOne(@Param('id', new ParseUUIDPipe()) id: string): Promise<DamageResponseDto> {
    return DamageResponseDto.from(await this.damages.findById(id));
  }

  @Patch(':id')
  @ApiOperation({ summary: 'Update damage description, severity, status or note.' })
  @ApiOkResponse({ type: DamageResponseDto })
  async update(
    @Param('id', new ParseUUIDPipe()) id: string,
    @Body() dto: UpdateDamageDto,
  ): Promise<DamageResponseDto> {
    return DamageResponseDto.from(await this.damages.update(id, dto));
  }

  @Delete(':id')
  @HttpCode(HttpStatus.NO_CONTENT)
  @ApiOperation({ summary: 'Delete a damage record.' })
  @ApiNoContentResponse()
  async remove(@Param('id', new ParseUUIDPipe()) id: string): Promise<void> {
    await this.damages.remove(id);
  }
}
