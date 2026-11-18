<?php

namespace App\Models;

use App\Enums\PublishStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/** A riding route ("Route" would clash with Laravel's router). */
class RideRoute extends Model
{
    use HasFactory, HasUuids, SoftDeletes;

    protected $table = 'routes';

    protected $fillable = ['slug', 'name', 'summary', 'start_destination_id', 'end_destination_id', 'distance_km', 'estimated_hours', 'status', 'last_confirmed_on', 'is_dev_seed'];

    protected function casts(): array
    {
        return ['status' => PublishStatus::class, 'last_confirmed_on' => 'date:Y-m-d', 'is_dev_seed' => 'boolean', 'distance_km' => 'float', 'estimated_hours' => 'float'];
    }

    public function scopePublished(Builder $q): void
    {
        $q->where('status', PublishStatus::Published->value);
    }

    public function segments(): HasMany
    {
        return $this->hasMany(RouteSegment::class, 'route_id')->orderBy('position');
    }

    public function startDestination(): BelongsTo
    {
        return $this->belongsTo(Destination::class, 'start_destination_id');
    }

    public function endDestination(): BelongsTo
    {
        return $this->belongsTo(Destination::class, 'end_destination_id');
    }
}
