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
import { ResourcesService } from '../application/resources.service';
import { CreateResourceDto } from './dto/create-resource.dto';
import { ListResourcesQueryDto } from './dto/list-resources.dto';
import { ResourceResponseDto } from './dto/resource.response';
import { UpdateResourceDto } from './dto/update-resource.dto';

@ApiTags('resources')
@Controller({ path: 'resources', version: '1' })
export class ResourcesController {
  constructor(private readonly resources: ResourcesService) {}

  @Post()
  @ApiOperation({ summary: 'Create a reservable resource (boat, trailer, space).' })
  @ApiCreatedResponse({ type: ResourceResponseDto })
  async create(@Body() dto: CreateResourceDto): Promise<ResourceResponseDto> {
    const created = await this.resources.create(dto);
    return ResourceResponseDto.from(created);
  }

  @Get()
  @ApiOperation({ summary: 'List resources with filtering, search, and pagination.' })
  @ApiOkResponse({ type: PaginatedResponseDto<ResourceResponseDto> })
  async list(
    @Query() query: ListResourcesQueryDto,
  ): Promise<PaginatedResponseDto<ResourceResponseDto>> {
    const { items, total } = await this.resources.list({
      type: query.type,
      isActive: query.isActive,
      search: query.search,
      skip: (query.page - 1) * query.pageSize,
      take: query.pageSize,
    });
    return paginated(items.map(ResourceResponseDto.from), total, query.page, query.pageSize);
  }

  @Get(':id')
  @ApiOperation({ summary: 'Get a resource by id.' })
  @ApiOkResponse({ type: ResourceResponseDto })
  async findOne(@Param('id', new ParseUUIDPipe()) id: string): Promise<ResourceResponseDto> {
    const resource = await this.resources.findById(id);
    return ResourceResponseDto.from(resource);
  }

  @Patch(':id')
  @ApiOperation({ summary: 'Update mutable fields of a resource.' })
  @ApiOkResponse({ type: ResourceResponseDto })
  async update(
    @Param('id', new ParseUUIDPipe()) id: string,
    @Body() dto: UpdateResourceDto,
  ): Promise<ResourceResponseDto> {
    const updated = await this.resources.update(id, dto);
    return ResourceResponseDto.from(updated);
  }

  @Patch(':id/deactivate')
  @ApiOperation({ summary: 'Mark resource as inactive (soft retire).' })
  @ApiOkResponse({ type: ResourceResponseDto })
  async deactivate(
    @Param('id', new ParseUUIDPipe()) id: string,
  ): Promise<ResourceResponseDto> {
    const updated = await this.resources.setActive(id, false);
    return ResourceResponseDto.from(updated);
  }

  @Patch(':id/activate')
  @ApiOperation({ summary: 'Reactivate a previously retired resource.' })
  @ApiOkResponse({ type: ResourceResponseDto })
  async activate(
    @Param('id', new ParseUUIDPipe()) id: string,
  ): Promise<ResourceResponseDto> {
    const updated = await this.resources.setActive(id, true);
    return ResourceResponseDto.from(updated);
  }

  @Delete(':id')
  @HttpCode(HttpStatus.NO_CONTENT)
  @ApiOperation({ summary: 'Permanently delete a resource (only if no reservations exist).' })
  @ApiNoContentResponse()
  async remove(@Param('id', new ParseUUIDPipe()) id: string): Promise<void> {
    await this.resources.delete(id);
  }
}
