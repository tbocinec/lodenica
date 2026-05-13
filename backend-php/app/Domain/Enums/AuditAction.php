<?php

namespace App\Domain\Enums;

enum AuditAction: string
{
    case CREATE = 'CREATE';
    case UPDATE = 'UPDATE';
    case DELETE = 'DELETE';
    case CANCEL = 'CANCEL';
    case ACTIVATE = 'ACTIVATE';
    case DEACTIVATE = 'DEACTIVATE';
    case ATTACH_RESOURCES = 'ATTACH_RESOURCES';
    case ADD_PARTICIPANT = 'ADD_PARTICIPANT';
    case REMOVE_PARTICIPANT = 'REMOVE_PARTICIPANT';
}
