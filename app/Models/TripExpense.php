<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class TripExpense extends Model
{
    use HasUuids;

    protected $fillable = ['label', 'category', 'amount_npr', 'paid_by'];

    protected function casts(): array
    {
        return ['amount_npr' => 'float'];
    }
}
