<?php

namespace App\Policies;

use App\Enums\TripRole;
use App\Models\Trip;
use App\Models\User;

/** Trips are private workspaces: only members, by role. Staff have no access. */
class TripPolicy
{
    public function view(User $user, Trip $trip): bool
    {
        return $trip->roleOf($user) !== null;
    }

    public function edit(User $user, Trip $trip): bool
    {
        return ($trip->roleOf($user)?->rank() ?? 0) >= TripRole::Editor->rank();
    }

    public function manage(User $user, Trip $trip): bool
    {
        return $trip->roleOf($user) === TripRole::Owner;
    }
}
