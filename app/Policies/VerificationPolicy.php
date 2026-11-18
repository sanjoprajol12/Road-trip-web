<?php

namespace App\Policies;

use App\Models\User;
use App\Models\Verification;

class VerificationPolicy
{
    public function view(User $user, Verification $claim): bool
    {
        return $claim->user_id === $user->id || $user->isStaff();
    }

    /** Organizer and expert claims: administrators. Others: any staff. Never your own claim. */
    public function decide(User $user, Verification $claim): bool
    {
        if ($claim->user_id === $user->id) {
            return false;
        }

        return $claim->kind->needsAdmin() ? $user->isAdmin() : $user->isStaff();
    }
}
