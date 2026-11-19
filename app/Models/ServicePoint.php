<?php

namespace App\Models;

use App\Enums\PublishStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class ServicePoint extends Model
{
    use HasUuids;

    protected $fillable = ['kind', 'name', 'destination_id', 'route_segment_id', 'latitude', 'longitude', 'notes', 'status', 'last_confirmed_on'];

    protected function casts(): array
    {
        return ['status' => PublishStatus::class, 'last_confirmed_on' => 'date:Y-m-d', 'latitude' => 'float', 'longitude' => 'float'];
    }

    public function destination(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Destination::class);
    }

    public function segment(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(RouteSegment::class, 'route_segment_id');
    }
}
