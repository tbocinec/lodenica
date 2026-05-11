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
import { ReservationResponseDto } from '../../reservations/presentation/dto/reservation.response';
import { EventsService } from '../application/events.service';
import { AddParticipantDto } from './dto/add-participant.dto';
import { AttachResourcesDto } from './dto/attach-resources.dto';
import { CreateEventDto } from './dto/create-event.dto';
import { EventParticipantResponseDto, EventResponseDto } from './dto/event.response';
import { ListEventsQueryDto } from './dto/list-events.dto';
import { UpdateEventDto } from './dto/update-event.dto';

@ApiTags('events')
@Controller({ path: 'events', version: '1' })
export class EventsController {
  constructor(private readonly events: EventsService) {}

  @Post()
  @ApiOperation({ summary: 'Create a new boathouse event.' })
  @ApiCreatedResponse({ type: EventResponseDto })
  async create(@Body() dto: CreateEventDto): Promise<EventResponseDto> {
    const created = await this.events.create(dto);
    return EventResponseDto.from(created);
  }

  @Get()
  @ApiOperation({ summary: 'List events with date-range filtering and pagination.' })
  @ApiOkResponse({ type: PaginatedResponseDto<EventResponseDto> })
  async list(
    @Query() query: ListEventsQueryDto,
  ): Promise<PaginatedResponseDto<EventResponseDto>> {
    const from = query.from ? new Date(query.from) : undefined;
    const to = query.to ? new Date(query.to) : undefined;

    const { items, total } = await this.events.list({
      from,
      to,
      skip: (query.page - 1) * query.pageSize,
      take: query.pageSize,
    });

    return paginated(items.map(EventResponseDto.from), total, query.page, query.pageSize);
  }

  @Get(':id')
  @ApiOperation({ summary: 'Get an event by id.' })
  @ApiOkResponse({ type: EventResponseDto })
  async findOne(@Param('id', new ParseUUIDPipe()) id: string): Promise<EventResponseDto> {
    const event = await this.events.findById(id);
    return EventResponseDto.from(event);
  }

  @Patch(':id')
  @ApiOperation({ summary: 'Update event details.' })
  @ApiOkResponse({ type: EventResponseDto })
  async update(
    @Param('id', new ParseUUIDPipe()) id: string,
    @Body() dto: UpdateEventDto,
  ): Promise<EventResponseDto> {
    const updated = await this.events.update(id, dto);
    return EventResponseDto.from(updated);
  }

  @Delete(':id')
  @HttpCode(HttpStatus.NO_CONTENT)
  @ApiOperation({ summary: 'Delete an event. Linked reservations survive with eventId cleared.' })
  @ApiNoContentResponse()
  async remove(@Param('id', new ParseUUIDPipe()) id: string): Promise<void> {
    await this.events.remove(id);
  }

  @Get(':id/participants')
  @ApiOperation({ summary: 'List participants signed up for an event.' })
  @ApiOkResponse({ type: EventParticipantResponseDto, isArray: true })
  async listParticipants(
    @Param('id', new ParseUUIDPipe()) id: string,
  ): Promise<EventParticipantResponseDto[]> {
    const participants = await this.events.listParticipants(id);
    return participants.map(EventParticipantResponseDto.from);
  }

  @Post(':id/participants')
  @ApiOperation({ summary: 'Add a participant to an event.' })
  @ApiCreatedResponse({ type: EventParticipantResponseDto })
  async addParticipant(
    @Param('id', new ParseUUIDPipe()) id: string,
    @Body() dto: AddParticipantDto,
  ): Promise<EventParticipantResponseDto> {
    const participant = await this.events.addParticipant(id, dto);
    return EventParticipantResponseDto.from(participant);
  }

  @Post(':id/reservations')
  @ApiOperation({
    summary: 'Bulk-attach resources to an event. Creates one reservation per resource using the event time window.',
  })
  @ApiCreatedResponse({ type: ReservationResponseDto, isArray: true })
  async attachResources(
    @Param('id', new ParseUUIDPipe()) id: string,
    @Body() dto: AttachResourcesDto,
  ): Promise<ReservationResponseDto[]> {
    const created = await this.events.attachResources(id, dto.resourceIds);
    return created.map(ReservationResponseDto.from);
  }

  @Delete(':id/participants/:participantId')
  @HttpCode(HttpStatus.NO_CONTENT)
  @ApiOperation({ summary: 'Remove a participant from an event.' })
  @ApiNoContentResponse()
  async removeParticipant(
    @Param('id', new ParseUUIDPipe()) id: string,
    @Param('participantId', new ParseUUIDPipe()) participantId: string,
  ): Promise<void> {
    await this.events.removeParticipant(id, participantId);
  }
}
