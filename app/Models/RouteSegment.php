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

    protected $fillable = ['route_id', 'position', 'start_name', 'end_name', 'start_lat', 'start_lng', 'end_lat', 'end_lng', 'distance_km', 'estimated_hours', 'surface', 'notes'];

    protected function casts(): array
    {
        return ['start_lat' => 'float', 'start_lng' => 'float', 'end_lat' => 'float', 'end_lng' => 'float', 'distance_km' => 'float', 'estimated_hours' => 'float', 'path' => 'array'];
    }

    /**
     * Straight pieces for distance maths: the imported track when there is one, otherwise the start-to-end chord.
     * Only `path` is real geometry and only it is ever drawn as a line.
     *
     * @return list<array{0: float, 1: float, 2: float, 3: float}>
     */
    public function lines(): array
    {
        $pts = $this->path ?: ($this->start_lat !== null && $this->end_lat !== null ? [[$this->start_lat, $this->start_lng], [$this->end_lat, $this->end_lng]] : []);
        $out = [];
        for ($i = 1; $i < count($pts); $i++) {
            $out[] = [(float) $pts[$i - 1][0], (float) $pts[$i - 1][1], (float) $pts[$i][0], (float) $pts[$i][1]];
        }

        return $out;
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
