<?php

namespace App\Models;

use App\Enums\PublishStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Destination extends Model
{
    use \App\Models\Concerns\HasSources, HasFactory, HasUuids, SoftDeletes;

    protected $fillable = ['slug', 'name', 'province', 'district', 'summary', 'overview', 'elevation_m', 'latitude', 'longitude', 'photo_path', 'photo_alt', 'photo_credit', 'status', 'last_confirmed_on', 'is_dev_seed'];

    protected function casts(): array
    {
        return ['status' => PublishStatus::class, 'last_confirmed_on' => 'date:Y-m-d', 'is_dev_seed' => 'boolean', 'latitude' => 'float', 'longitude' => 'float'];
    }

    public function scopePublished(Builder $q): void
    {
        $q->where('status', PublishStatus::Published->value);
    }
}
