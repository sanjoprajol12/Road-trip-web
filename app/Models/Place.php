<?php

namespace App\Models;

use App\Models\Concerns\HasSources;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Validation\Rule;

/** A place of interest: viewpoint, lake, temple, rider-suggested hidden place... Services stay in service_points. */
class Place extends Model
{
    use HasSources, HasUuids;

    public const CATEGORIES = ['viewpoint', 'lake', 'waterfall', 'temple', 'monastery', 'heritage', 'cultural', 'attraction', 'rest_stop', 'hidden_place', 'other'];

    /** Nepal with a margin, same as the database check. */
    public const LAT = [26.3, 30.5];

    public const LNG = [80.0, 88.3];

    protected $fillable = ['slug', 'name', 'category', 'summary', 'description', 'latitude', 'longitude', 'destination_id', 'media_asset_id', 'gallery', 'observed_on', 'last_confirmed_on'];

    protected function casts(): array
    {
        return ['latitude' => 'float', 'longitude' => 'float', 'gallery' => 'array', 'observed_on' => 'date:Y-m-d', 'last_confirmed_on' => 'date:Y-m-d', 'published_at' => 'datetime', 'is_dev_seed' => 'boolean'];
    }

    public function scopePublished(Builder $q): void
    {
        $q->where('status', 'published');
    }

    /** Fields riders and staff both send. Staff rules add slug, images, sources and confirmation dates. */
    public static function rules(bool $staff, ?self $place = null): array
    {
        $rules = [
            'name' => ['required', 'string', 'min:3', 'max:120'],
            'category' => ['required', Rule::in(self::CATEGORIES)],
            'summary' => ['nullable', 'string', 'max:300'],
            'description' => [$staff ? 'nullable' : 'required', 'string', $staff ? 'max:5000' : 'min:20', 'max:5000'],
            'latitude' => ['required', 'numeric', 'between:'.implode(',', self::LAT)],
            'longitude' => ['required', 'numeric', 'between:'.implode(',', self::LNG)],
            'destination_id' => ['nullable', 'uuid', $staff ? Rule::exists('destinations', 'id') : Rule::exists('destinations', 'id')->where('status', 'published')],
            'observed_on' => ['nullable', 'date', 'before_or_equal:today', 'after:2000-01-01'],
        ];
        if ($staff) {
            $rules += [
                'slug' => ['nullable', 'string', 'max:100', 'regex:/^[a-z0-9]+(-[a-z0-9]+)*$/', Rule::unique('places', 'slug')->ignore($place?->id)],
                'media_asset_id' => ['nullable', 'uuid', Rule::exists('media_assets', 'id')->where('visibility', 'public')],
                'gallery' => ['nullable', 'array', 'max:8'],
                'gallery.*' => ['uuid', 'distinct', Rule::exists('media_assets', 'id')->where('visibility', 'public')],
                'last_confirmed_on' => ['nullable', 'date', 'before_or_equal:today'],
                'allow_duplicate' => ['sometimes', 'boolean'],
                ...Source::rules(),
            ];
        }

        return $rules;
    }

    public function destination(): BelongsTo
    {
        return $this->belongsTo(Destination::class);
    }

    public function submitter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    public function corrections(): HasMany
    {
        return $this->hasMany(PlaceCorrection::class);
    }
}
