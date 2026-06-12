<?php

namespace App\Domain\Enums;

enum AuditEntityType: string
{
    case RESOURCE = 'RESOURCE';
    case RESERVATION = 'RESERVATION';
    case EVENT = 'EVENT';
    case EVENT_PARTICIPANT = 'EVENT_PARTICIPANT';
    case DAMAGE = 'DAMAGE';
    case USER = 'USER';
    case SETTING = 'SETTING';
}
