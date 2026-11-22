<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** An offer to take over an event or a group trip from a rider who is deleting their account. Applies only when accepted. */
class OwnershipTransfer extends Model
{
    use HasUuids;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['responded_at' => 'datetime'];
    }

    public function from(): BelongsTo
    {
        return $this->belongsTo(User::class, 'from_user_id');
    }

    public function to(): BelongsTo
    {
        return $this->belongsTo(User::class, 'to_user_id');
    }

    public function subject(): Event|Trip|null
    {
        return $this->subject_type === 'event' ? Event::find($this->subject_id) : Trip::find($this->subject_id);
    }
}
