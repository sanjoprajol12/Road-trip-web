<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * A copy of content under an open moderation case, taken when its author's account was deleted (D-04). Restricted to
 * moderation.evidence.view, every view audited, held at most 30 days after the deletion (also a database check).
 */
class ModerationEvidence extends Model
{
    use HasUuids;

    protected $table = 'moderation_evidence';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['snapshot' => 'array', 'files' => 'array', 'held_until' => 'datetime', 'disposed_at' => 'datetime'];
    }
}
