<?php

namespace App\Domain\Enums;

enum DamageSeverity: string
{
    case MINOR = 'MINOR';
    case MODERATE = 'MODERATE';
    case CRITICAL = 'CRITICAL';
}
