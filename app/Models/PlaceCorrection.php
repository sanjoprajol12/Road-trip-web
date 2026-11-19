<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A rider's suggested correction to a published place. Staff decide; the place is never changed automatically. */
class PlaceCorrection extends Model
{
    use HasUuids;

    protected $fillable = ['place_id', 'user_id', 'body', 'latitude', 'longitude'];

    protected function casts(): array
    {
        return ['latitude' => 'float', 'longitude' => 'float', 'reviewed_at' => 'datetime'];
    }

    public function place(): BelongsTo
    {
        return $this->belongsTo(Place::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
