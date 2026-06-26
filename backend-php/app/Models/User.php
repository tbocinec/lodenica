<?php

namespace App\Models;

use App\Domain\Enums\UserRole;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasUuids, Notifiable;

    protected $table = 'users';

    public const CREATED_AT = 'createdAt';
    public const UPDATED_AT = 'updatedAt';

    protected $guarded = ['id', 'createdAt', 'updatedAt'];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected $casts = [
        'email_verified_at' => 'datetime',
        'password' => 'hashed',
        'role' => UserRole::class,
        'isActive' => 'boolean',
        'privacyAck' => 'boolean',
        'dataConsent' => 'boolean',
        'passwordSetAt' => 'datetime',
        'gdprConsentAt' => 'datetime',
        'createdAt' => 'datetime',
        'updatedAt' => 'datetime',
    ];

    public function isAdmin(): bool
    {
        return $this->role === UserRole::ADMIN;
    }

    /**
     * True for ADMIN and MEMBER. PENDING returns false — pending users
     * are treated like anonymous visitors for permission purposes.
     * See docs/AUTH-AND-PERMISSIONS.md.
     */
    public function isMember(): bool
    {
        return $this->role === UserRole::MEMBER || $this->role === UserRole::ADMIN;
    }

    public function isPending(): bool
    {
        return $this->role === UserRole::PENDING;
    }

    /** Social logins (Google / Facebook) linked to this account. */
    public function identities(): HasMany
    {
        return $this->hasMany(UserIdentity::class, 'userId');
    }

    /** Reservations this user created (their own bookings). */
    public function reservations(): HasMany
    {
        return $this->hasMany(Reservation::class, 'createdById');
    }
}
