<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** A rider's request to delete their account. Kept after the deletion as the minimum record: dates, counts, choices by id. */
class AccountDeletionRequest extends Model
{
    use HasUuids;

    public const CHOICES = ['delete', 'anonymise', 'anonymise_without_photos'];

    protected $guarded = [];

    protected function casts(): array
    {
        return ['requested_at' => 'datetime', 'run_after' => 'datetime', 'cancelled_at' => 'datetime', 'completed_at' => 'datetime', 'choices' => 'array', 'counts' => 'array'];
    }
}
