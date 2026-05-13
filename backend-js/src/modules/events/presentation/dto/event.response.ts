import { ApiProperty } from '@nestjs/swagger';

import { Event, EventParticipant } from '../../domain/event.entity';

export class EventResponseDto {
  @ApiProperty() id!: string;
  @ApiProperty() title!: string;
  @ApiProperty({ nullable: true }) description!: string | null;
  @ApiProperty({ nullable: true }) location!: string | null;
  @ApiProperty({ type: String, format: 'date-time' }) startsAt!: Date;
  @ApiProperty({ type: String, format: 'date-time' }) endsAt!: Date;
  @ApiProperty() createdAt!: Date;
  @ApiProperty() updatedAt!: Date;

  static from(e: Event): EventResponseDto {
    return e.toJSON();
  }
}

export class EventParticipantResponseDto {
  @ApiProperty() id!: string;
  @ApiProperty() eventId!: string;
  @ApiProperty() name!: string;
  @ApiProperty({ nullable: true }) contact!: string | null;
  @ApiProperty({ nullable: true }) note!: string | null;
  @ApiProperty() createdAt!: Date;

  static from(p: EventParticipant): EventParticipantResponseDto {
    return p.toJSON();
  }
}
