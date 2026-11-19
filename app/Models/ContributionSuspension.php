<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** Contribution rights paused (not the whole account): reason, end date, one appeal and its review. */
class ContributionSuspension extends Model
{
    use HasUuids;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['until' => 'datetime', 'lifted_at' => 'datetime', 'appealed_at' => 'datetime'];
    }

    public static function activeFor(string $userId): ?self
    {
        return self::where('user_id', $userId)->whereNull('lifted_at')->where('until', '>', now())->latest('until')->first();
    }

    /** What the rider sees: no staff identities. */
    public function toRider(): array
    {
        return ['until' => $this->until->toIso8601String(), 'reason' => $this->reason, 'appealed_at' => $this->appealed_at?->toIso8601String(), 'appeal_decision' => $this->appeal_decision];
    }
}
