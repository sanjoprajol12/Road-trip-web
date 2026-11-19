<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class TripStop extends Model
{
    use HasUuids;

    protected $fillable = ['position', 'day', 'name', 'destination_id', 'route_id', 'overnight', 'note', 'service_point_id'];

    protected function casts(): array
    {
        return ['overnight' => 'boolean'];
    }
}
