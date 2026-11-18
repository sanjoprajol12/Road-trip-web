<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class EmergencyContact extends Model
{
    use HasUuids;

    protected $fillable = ['name', 'phone', 'relation'];

    protected function casts(): array
    {
        return [];
    }
}
