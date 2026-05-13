import { ApiProperty, ApiPropertyOptional } from '@nestjs/swagger';
import { IsOptional, IsString, Length } from 'class-validator';

export class AddParticipantDto {
  @ApiProperty({ example: 'Ján Novák' })
  @IsString()
  @Length(1, 200)
  name!: string;

  @ApiPropertyOptional({ description: 'Email or phone — free-text contact.' })
  @IsOptional()
  @IsString()
  @Length(0, 200)
  contact?: string;

  @ApiPropertyOptional()
  @IsOptional()
  @IsString()
  @Length(0, 1000)
  note?: string;
}
