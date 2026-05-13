import { OmitType, PartialType } from '@nestjs/swagger';

import { CreateResourceDto } from './create-resource.dto';

// `identifier` and `type` are immutable after creation — changing them would
// invalidate references like printed labels or QR codes on the boats.
export class UpdateResourceDto extends PartialType(
  OmitType(CreateResourceDto, ['identifier', 'type'] as const),
) {}
