<?php

namespace App\Models;

use App\Domain\Enums\AuditAction;
use App\Domain\Enums\AuditEntityType;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class AuditLog extends Model
{
    use HasUuids;

    protected $table = 'audit_logs';

    public const CREATED_AT = 'createdAt';
    public const UPDATED_AT = null;
    public $timestamps = false;

    protected $guarded = ['id'];

    protected $casts = [
        'entityType' => AuditEntityType::class,
        'action' => AuditAction::class,
        'changes' => 'array',
        'createdAt' => 'datetime',
    ];
}
