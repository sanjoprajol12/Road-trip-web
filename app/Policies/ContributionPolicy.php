<?php

namespace App\Policies;

use App\Enums\ContributionStatus;
use App\Models\Contribution;
use App\Models\StaffAccount;
use App\Models\User;

class ContributionPolicy
{
    public function view(?User $user, Contribution $c): bool
    {
        return $c->isVisible() || ($user && $c->author_id === $user->id);
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

    /** "Still accurate": another rider, on something anyone can see. */
    public function confirm(User $user, Contribution $c): bool
    {
        return $this->report($user, $c);
    }

    /** After review the author cannot rewrite the report, only add a dated correction underneath it. */
    public function correct(User $user, Contribution $c): bool
    {
        return $c->author_id === $user->id && ($c->isVisible() || $c->status === ContributionStatus::Flagged);
    }

    /** Staff with the permission, never on content of their own rider account (linked, or with their email). */
    public function moderate(StaffAccount $staff, Contribution $c): bool
    {
        return $staff->hasPermission('contributions.moderate') && ! $staff->isSamePersonAs($c->author_id);
    }
}
