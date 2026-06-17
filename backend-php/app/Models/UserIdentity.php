<?php

namespace App\Models;

use App\Domain\Enums\OAuthProvider;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A social login linked to a local user. One row per (provider,
 * providerUserId). See docs/AUTH-AND-PERMISSIONS.md.
 *
 * @property string $userId
 * @property OAuthProvider $provider
 * @property string $providerUserId
 */
class UserIdentity extends Model
{
    use HasUuids;

    protected $table = 'user_identities';

    public const CREATED_AT = 'createdAt';
    public const UPDATED_AT = 'updatedAt';

    protected $guarded = ['id', 'createdAt', 'updatedAt'];

    protected $casts = [
        'provider' => OAuthProvider::class,
        'createdAt' => 'datetime',
        'updatedAt' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'userId');
    }
}
