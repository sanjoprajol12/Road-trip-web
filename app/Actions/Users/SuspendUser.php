<?php

namespace App\Actions\Users;

use App\Models\User;
use App\Support\Audit;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SuspendUser
{
    public function suspend(User $staff, User $target, CarbonInterface $until, string $reason): void
    {
        if ($target->id === $staff->id) {
            throw ValidationException::withMessages(['user' => ['You cannot suspend yourself.']]);
        }
        if ($target->isStaff() && ! $staff->isAdmin()) {
            throw ValidationException::withMessages(['user' => ['Only administrators can suspend staff.']]);
        }
        DB::transaction(function () use ($target, $until, $reason) {
            $old = $target->only(['suspended_until', 'suspension_reason']);
            $target->forceFill(['suspended_until' => $until, 'suspension_reason' => $reason])->save();
            DB::table('sessions')->where('user_id', $target->id)->delete();
            Audit::record('user.suspended', $target, $old, ['suspended_until' => $until->toIso8601String(), 'suspension_reason' => $reason]);
        });
    }

    public function lift(User $target): void
    {
        DB::transaction(function () use ($target) {
            $old = $target->only(['suspended_until', 'suspension_reason']);
            $target->forceFill(['suspended_until' => null, 'suspension_reason' => null])->save();
            Audit::record('user.suspension_lifted', $target, $old, null);
        });
    }
}
