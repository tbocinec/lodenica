<?php

namespace App\Domain\Enums;

enum ReservationStatus: string
{
    case CONFIRMED = 'CONFIRMED';
    case CANCELLED = 'CANCELLED';
}
