<?php

namespace App\Domain\Enums;

enum DamageStatus: string
{
    case REPORTED = 'REPORTED';
    case IN_REPAIR = 'IN_REPAIR';
    case FIXED = 'FIXED';
}
