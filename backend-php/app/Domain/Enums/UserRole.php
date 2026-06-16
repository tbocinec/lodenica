<?php

namespace App\Domain\Enums;

/**
 * Three-tier role hierarchy.
 *
 *   ADMIN   — full access, can manage users + inventory, see everything
 *   MEMBER  — confirmed club member: can see customer names/contacts
 *             across the schedule and edit existing reservations
 *   PENDING — registered but not yet confirmed by an admin. May log in
 *             but is treated like an anonymous visitor for everything
 *             except seeing their own "awaiting approval" dashboard
 *
 * See `docs/AUTH-AND-PERMISSIONS.md` for the full permission matrix
 * and rationale.
 */
enum UserRole: string
{
    case ADMIN = 'ADMIN';
    case MEMBER = 'MEMBER';
    case PENDING = 'PENDING';
}
