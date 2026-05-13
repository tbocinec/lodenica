import { ApiProperty } from '@nestjs/swagger';
import { ResourceType } from '@prisma/client';

import { Resource } from '../../domain/resource.entity';

export class ResourceResponseDto {
  @ApiProperty() id!: string;
  @ApiProperty() identifier!: string;
  @ApiProperty({ enum: ResourceType }) type!: ResourceType;
  @ApiProperty() name!: string;
  @ApiProperty({ nullable: true }) model!: string | null;
  @ApiProperty({ nullable: true }) color!: string | null;
  @ApiProperty({ nullable: true }) seats!: number | null;
  @ApiProperty({ nullable: true }) lengthCm!: number | null;
  @ApiProperty({ nullable: true }) weightKg!: number | null;
  @ApiProperty({ nullable: true }) note!: string | null;
  @ApiProperty({ nullable: true }) imageUrl!: string | null;
  @ApiProperty() isActive!: boolean;
  @ApiProperty() createdAt!: Date;
  @ApiProperty() updatedAt!: Date;

  static from(resource: Resource): ResourceResponseDto {
    return resource.toJSON();
  }
}
