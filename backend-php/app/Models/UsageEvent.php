<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * A single usage event (login / visit / pageview) for the admin usage
 * dashboard. See the create_usage_events_table migration. Has no
 * created/updated timestamps — only `occurredAt`.
 *
 * @property string $type
 * @property string|null $userId
 */
class UsageEvent extends Model
{
    use HasUuids;

    protected $table = 'usage_events';

    public $timestamps = false;

    protected $guarded = ['id'];

    protected $casts = [
        'occurredAt' => 'datetime',
    ];
}
