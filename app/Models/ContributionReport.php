<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ContributionReport extends Model
{
    public const CATEGORIES = ['dangerous', 'wrong', 'outdated', 'spam', 'offensive', 'other'];

    protected $fillable = ['contribution_id', 'reporter_id', 'reason', 'category'];

    protected function casts(): array
    {
        return ['resolved_at' => 'datetime'];
    }
}
