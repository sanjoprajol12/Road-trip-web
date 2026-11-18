<?php

namespace App\Actions\Verification;

use App\Enums\VerificationKind;
use App\Enums\VerificationStatus;
use App\Models\User;
use App\Models\Verification;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ApplyForVerification
{
    public function __invoke(User $user, VerificationKind $kind, string $scope, string $statement): Verification
    {
        return DB::transaction(function () use ($user, $kind, $scope, $statement) {
            $open = $user->verifications()->where('kind', $kind->value)->where('scope', $scope)
                ->whereIn('status', VerificationStatus::open())->lockForUpdate()->exists();
            if ($open) {
                throw ValidationException::withMessages(['kind' => ['You already have an open application or approval for this.']]);
            }
            $claim = $user->verifications()->create(['kind' => $kind->value, 'scope' => $scope, 'statement' => trim($statement)]);
            Audit::record('verification.applied', $claim, null, $claim->only(['kind', 'scope', 'status', 'user_id']));

            return $claim->refresh();
        });
    }
}
