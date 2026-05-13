<?php

namespace App\Domain\Enums;

/**
 * Resource type. KAYAK is deprecated — kept for historical rows.
 * Removing a Postgres enum value requires recreating the type, so legacy
 * members stay in place.
 */
enum ResourceType: string
{
    case KAYAK = 'KAYAK';
    case SEA_KAYAK = 'SEA_KAYAK';
    case WW_KAYAK = 'WW_KAYAK';
    case CANOE = 'CANOE';
    case ROWING_BOAT = 'ROWING_BOAT';
    case INFLATABLE_BOAT = 'INFLATABLE_BOAT';
    case TRAILER = 'TRAILER';
    case BOATHOUSE_SPACE = 'BOATHOUSE_SPACE';
}
