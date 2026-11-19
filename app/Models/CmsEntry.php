<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** One CMS item (page, FAQ, post, category, tag, author, team member, popup, banner). Types: config/cms.php. */
class CmsEntry extends Model
{
    use HasUuids;

    protected $fillable = ['type', 'slug', 'title', 'summary', 'body', 'data', 'media_asset_id', 'status', 'publish_at', 'expires_at', 'position', 'seo_title', 'seo_description'];

    protected function casts(): array
    {
        return ['data' => 'array', 'publish_at' => 'datetime', 'expires_at' => 'datetime', 'published_at' => 'datetime'];
    }

    /** Published, inside its schedule window. */
    public function scopeLive(Builder $q): void
    {
        $q->where('status', 'published')
            ->where(fn ($w) => $w->whereNull('publish_at')->orWhere('publish_at', '<=', now()))
            ->where(fn ($w) => $w->whereNull('expires_at')->orWhere('expires_at', '>', now()));
    }
}
