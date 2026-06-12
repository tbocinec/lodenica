<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Setting extends Model
{
    protected $table = 'settings';

    protected $primaryKey = 'key';
    public $incrementing = false;
    protected $keyType = 'string';

    public const CREATED_AT = null;
    public const UPDATED_AT = 'updatedAt';

    protected $guarded = ['updatedAt'];

    protected $casts = [
        'updatedAt' => 'datetime',
    ];
}
