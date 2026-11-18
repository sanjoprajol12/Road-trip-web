<?php

namespace App\Policies;

use App\Enums\ContributionStatus;
use App\Models\Contribution;
use App\Models\User;

class ContributionPolicy
{
    public function view(?User $user, Contribution $c): bool
    {
        return $c->isVisible() || ($user && ($c->author_id === $user->id || $user->isStaff()));
    }

    /** Authors may edit or add photos only while the report waits for review: moderators never see a moving target. */
    public function update(User $user, Contribution $c): bool
    {
        return $c->author_id === $user->id && $c->status === ContributionStatus::PendingReview;
    }

    public function report(User $user, Contribution $c): bool
    {
        return $c->author_id !== $user->id && $c->isVisible();
    }

    public function moderate(User $user, Contribution $c): bool
    {
        return $user->isStaff() && $c->author_id !== $user->id;
    }
}
