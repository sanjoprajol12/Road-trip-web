<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ContributionMedia extends Model
{
    use HasUuids;

    protected $fillable = ['contribution_id', 'disk', 'path', 'width', 'height', 'size_bytes', 'alt'];

    protected $hidden = ['path'];

    public function contribution(): BelongsTo
    {
        return $this->belongsTo(Contribution::class);
    }
}
