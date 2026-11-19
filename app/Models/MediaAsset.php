<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A library image (branding, CMS, destinations). Only CC0, public domain or owner-supplied, with a credit. */
class MediaAsset extends Model
{
    use HasUuids;

    protected $fillable = ['alt', 'credit', 'license', 'source_url', 'place_note'];

    protected $hidden = ['path'];

    public function usages(): HasMany
    {
        return $this->hasMany(MediaUsage::class);
    }

    public function url(): string
    {
        return route('api.files.show', $this->id, false);
    }

    public function toApi(): array
    {
        return [
            'id' => $this->id, 'url' => $this->url(), 'visibility' => $this->visibility, 'mime' => $this->mime, 'size_bytes' => $this->size_bytes,
            'width' => $this->width, 'height' => $this->height, 'alt' => $this->alt, 'credit' => $this->credit, 'license' => $this->license,
            'source_url' => $this->source_url, 'place_note' => $this->place_note, 'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
