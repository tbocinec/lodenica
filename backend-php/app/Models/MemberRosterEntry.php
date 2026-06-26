<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One curated member-roster ("číselník") row: an email plus the internal
 * member ID to grant when that email registers. See the create-table
 * migration for the full behaviour.
 *
 * @property string $email
 * @property string|null $memberId
 * @property string|null $name
 * @property string|null $registeredUserId
 */
class MemberRosterEntry extends Model
{
    use HasUuids;

    protected $table = 'member_roster';

    public const CREATED_AT = 'createdAt';
    public const UPDATED_AT = 'updatedAt';

    protected $guarded = ['id', 'createdAt', 'updatedAt'];

    protected $casts = [
        'registeredAt' => 'datetime',
        'createdAt' => 'datetime',
        'updatedAt' => 'datetime',
    ];

    public function registeredUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'registeredUserId');
    }
}
