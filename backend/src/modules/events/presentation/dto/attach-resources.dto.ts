import { ApiProperty } from '@nestjs/swagger';
import { ArrayMinSize, ArrayNotEmpty, IsArray, IsUUID } from 'class-validator';

export class AttachResourcesDto {
  @ApiProperty({
    description: 'UUIDs of resources to reserve for this event. Each becomes one reservation using the event time window.',
    isArray: true,
    type: String,
  })
  @IsArray()
  @ArrayNotEmpty()
  @ArrayMinSize(1)
  @IsUUID('all', { each: true })
  resourceIds!: string[];
}
