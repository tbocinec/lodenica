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
import { ReservationsService } from '../application/reservations.service';
import { TimeRange } from '../domain/time-range.value';
import { CreateReservationDto } from './dto/create-reservation.dto';
import { ListReservationsQueryDto } from './dto/list-reservations.dto';
import { ReservationResponseDto } from './dto/reservation.response';
import { UpdateReservationDto } from './dto/update-reservation.dto';

@ApiTags('reservations')
@Controller({ path: 'reservations', version: '1' })
export class ReservationsController {
  constructor(private readonly reservations: ReservationsService) {}

  @Post()
  @ApiOperation({ summary: 'Create a new reservation. Validates conflicts.' })
  @ApiCreatedResponse({ type: ReservationResponseDto })
  async create(@Body() dto: CreateReservationDto): Promise<ReservationResponseDto> {
    const created = await this.reservations.create(dto);
    return ReservationResponseDto.from(created);
  }

  @Get()
  @ApiOperation({ summary: 'List reservations with filtering and pagination.' })
  @ApiOkResponse({ type: PaginatedResponseDto<ReservationResponseDto> })
  async list(
    @Query() query: ListReservationsQueryDto,
  ): Promise<PaginatedResponseDto<ReservationResponseDto>> {
    const range = query.from && query.to ? TimeRange.fromInstants(query.from, query.to) : undefined;

    const { items, total } = await this.reservations.list({
      resourceId: query.resourceId,
      status: query.status,
      range,
      skip: (query.page - 1) * query.pageSize,
      take: query.pageSize,
    });

    return paginated(
      items.map(ReservationResponseDto.from),
      total,
      query.page,
      query.pageSize,
    );
  }

  @Get(':id')
  @ApiOperation({ summary: 'Get a reservation by id.' })
  @ApiOkResponse({ type: ReservationResponseDto })
  async findOne(@Param('id', new ParseUUIDPipe()) id: string): Promise<ReservationResponseDto> {
    const reservation = await this.reservations.findById(id);
    return ReservationResponseDto.from(reservation);
  }

  @Patch(':id')
  @ApiOperation({ summary: 'Update reservation details. Re-checks conflicts on date change.' })
  @ApiOkResponse({ type: ReservationResponseDto })
  async update(
    @Param('id', new ParseUUIDPipe()) id: string,
    @Body() dto: UpdateReservationDto,
  ): Promise<ReservationResponseDto> {
    const updated = await this.reservations.update(id, dto);
    return ReservationResponseDto.from(updated);
  }

  @Patch(':id/cancel')
  @ApiOperation({ summary: 'Cancel a reservation (kept for audit; not deleted).' })
  @ApiOkResponse({ type: ReservationResponseDto })
  async cancel(@Param('id', new ParseUUIDPipe()) id: string): Promise<ReservationResponseDto> {
    const cancelled = await this.reservations.cancel(id);
    return ReservationResponseDto.from(cancelled);
  }

  @Delete(':id')
  @HttpCode(HttpStatus.NO_CONTENT)
  @ApiOperation({ summary: 'Permanently delete a reservation.' })
  @ApiNoContentResponse()
  async remove(@Param('id', new ParseUUIDPipe()) id: string): Promise<void> {
    await this.reservations.remove(id);
  }
}
