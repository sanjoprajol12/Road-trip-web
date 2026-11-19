<?php

namespace App\Actions\Users;

use App\Models\StaffAccount;
use App\Models\User;
use App\Support\Audit;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SuspendUser
{
    /** Riders only. Staff cannot act on their own rider account (linked, or with their email). */
    public function suspend(StaffAccount $staff, User $target, CarbonInterface $until, string $reason): void
    {
        if ($staff->isSamePersonAs($target->id)) {
            throw ValidationException::withMessages(['user' => ['You cannot suspend your own rider account.']]);
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
