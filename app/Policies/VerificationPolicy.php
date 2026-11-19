<?php

namespace App\Policies;

use App\Models\StaffAccount;
use App\Models\User;
use App\Models\Verification;

class VerificationPolicy
{
    public function view(User $user, Verification $claim): bool
    {
        return $claim->user_id === $user->id;
    }

    /** Organizer and expert claims need verification.decide_sensitive (administrators by default). Never your own claim. */
    public function decide(StaffAccount $staff, Verification $claim): bool
    {
        if ($staff->isSamePersonAs($claim->user_id)) {
            return false;
        }

        return $staff->hasPermission($claim->kind->needsAdmin() ? 'verification.decide_sensitive' : 'verification.decide');
    }
}
