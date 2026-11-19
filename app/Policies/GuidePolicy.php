<?php

namespace App\Policies;

use App\Enums\PublishStatus;
use App\Enums\VerificationKind;
use App\Models\Guide;
use App\Models\StaffAccount;
use App\Models\User;

class GuidePolicy
{
    public function view(?User $user, Guide $guide): bool
    {
        return $guide->status === PublishStatus::Published || ($user && $this->expertReview($user, $guide));
    }

    /** An expert verified in this guide's field, other than its author. */
    public function expertReview(User $user, Guide $guide): bool
    {
        $scope = $guide->category->expertScope();

        return $scope !== null && $guide->author_id !== $user->id
            && ! ($guide->author_staff_id && StaffAccount::find($guide->author_staff_id)?->isSamePersonAs($user->id))
            && $user->hasVerification(VerificationKind::ExpertReviewer, $scope->value);
    }
}
