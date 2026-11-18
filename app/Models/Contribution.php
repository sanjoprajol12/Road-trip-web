<?php

namespace App\Models;

use App\Enums\ContributionKind;
use App\Enums\ContributionStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Contribution extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = ['kind', 'title', 'body', 'observed_on', 'destination_id', 'route_id', 'route_segment_id', 'details'];

    protected function casts(): array
    {
        return ['kind' => ContributionKind::class, 'status' => ContributionStatus::class, 'observed_on' => 'date:Y-m-d', 'details' => 'array'];
    }

    public function scopeVisible(Builder $q): void
    {
        $q->whereIn('status', ContributionStatus::visible());
    }

    public function isVisible(): bool
    {
        return in_array($this->status->value, ContributionStatus::visible(), true);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    public function media(): HasMany
    {
        return $this->hasMany(ContributionMedia::class);
    }

    public function destination(): BelongsTo
    {
        return $this->belongsTo(Destination::class);
    }

    public function route(): BelongsTo
    {
        return $this->belongsTo(RideRoute::class, 'route_id');
    }

    public function segment(): BelongsTo
    {
        return $this->belongsTo(RouteSegment::class, 'route_segment_id');
    }

    public function reports(): HasMany
    {
        return $this->hasMany(ContributionReport::class);
    }
}
