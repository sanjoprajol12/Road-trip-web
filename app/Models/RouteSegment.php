<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RouteSegment extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = ['route_id', 'position', 'start_name', 'end_name', 'start_lat', 'start_lng', 'end_lat', 'end_lng', 'distance_km', 'surface', 'notes'];

    protected function casts(): array
    {
        return ['start_lat' => 'float', 'start_lng' => 'float', 'end_lat' => 'float', 'end_lng' => 'float', 'distance_km' => 'float'];
    }

    public function route(): BelongsTo
    {
        return $this->belongsTo(RideRoute::class, 'route_id');
    }

    public function servicePoints(): HasMany
    {
        return $this->hasMany(ServicePoint::class);
    }
}
