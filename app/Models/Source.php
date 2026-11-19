<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** A citation: title, publisher, link, date accessed. Attached to records through `sourceables`. */
class Source extends Model
{
    use HasUuids;

    protected $fillable = ['title', 'publisher', 'url', 'accessed_on'];

    protected function casts(): array
    {
        return ['accessed_on' => 'date:Y-m-d'];
    }

    /** Validation for a `sources` array in a staff request. */
    public static function rules(): array
    {
        return [
            'sources' => ['sometimes', 'array', 'max:10'],
            'sources.*.id' => ['nullable', 'uuid'],
            'sources.*.title' => ['required', 'string', 'min:3', 'max:200'],
            'sources.*.publisher' => ['nullable', 'string', 'max:200'],
            'sources.*.url' => ['nullable', 'url:https,http', 'max:500'],
            'sources.*.accessed_on' => ['nullable', 'date', 'before_or_equal:today'],
        ];
    }

    public function toPublic(): array
    {
        return ['id' => $this->id, 'title' => $this->title, 'publisher' => $this->publisher, 'url' => $this->url, 'accessed_on' => $this->accessed_on?->toDateString()];
    }
}
