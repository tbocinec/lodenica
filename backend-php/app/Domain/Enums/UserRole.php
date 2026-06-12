<?php

namespace App\Domain\Enums;

enum UserRole: string
{
    case ADMIN = 'ADMIN';
    case MEMBER = 'MEMBER';
}
