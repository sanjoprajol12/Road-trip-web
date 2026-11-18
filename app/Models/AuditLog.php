<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AuditLog extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['actor_id', 'action', 'subject_type', 'subject_id', 'old_data', 'new_data', 'ip_address'];

    protected function casts(): array
    {
        return ['old_data' => 'array', 'new_data' => 'array'];
    }
}
