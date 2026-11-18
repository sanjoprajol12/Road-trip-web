<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ContributionReport extends Model
{
    protected $fillable = ['contribution_id', 'reporter_id', 'reason'];

    protected function casts(): array
    {
        return ['resolved_at' => 'datetime'];
    }
}
