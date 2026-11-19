<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** "Still accurate on this day", from a rider who is not the author. */
class ContributionConfirmation extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['contribution_id', 'user_id', 'confirmed_on'];

    protected function casts(): array
    {
        return ['confirmed_on' => 'date:Y-m-d'];
    }
}
