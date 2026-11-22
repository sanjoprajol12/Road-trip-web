<?php

namespace App\Actions\Accounts;

use App\Models\AccountDeletionRequest;
use App\Models\OwnershipTransfer;
use App\Models\User;
use App\Notifications\AccountDeletionMail;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Request and cancellation (D-01). Requesting destroys nothing: it records the choices, sets the date (request + 30
 * days), ends the rider's other sessions and stops participation. Only the explicit cancel undoes it; signing in does not.
 */
final class ScheduleAccountDeletion
{
    public const COOLING_OFF_DAYS = 30;

    /** @param array<string, string> $choices contribution id => choice */
    public function request(User $user, array $choices, ?string $currentSessionId): AccountDeletionRequest
    {
        $eligible = DeletionPlan::eligibleIds($user);
        $missing = array_diff($eligible, array_keys($choices));
        $foreign = array_diff(array_keys($choices), $eligible);
        if ($missing) {
            throw ValidationException::withMessages(['choices' => ['Choose what happens to each of your public contributions ('.count($missing).' without a choice).']]);
        }
        if ($foreign) {
            throw ValidationException::withMessages(['choices' => ['Some choices are for contributions that are not yours or are not public.']]);
        }
        foreach ($choices as $choice) {
            if (! in_array($choice, AccountDeletionRequest::CHOICES, true)) {
                throw ValidationException::withMessages(['choices' => ['Choose delete, keep anonymously, or keep anonymously without photos.']]);
            }
        }

        $req = DB::transaction(function () use ($user, $choices, $currentSessionId) {
            User::whereKey($user->id)->lockForUpdate()->first();
            if (AccountDeletionRequest::where(['user_id' => $user->id, 'status' => 'scheduled'])->exists()) {
                throw ValidationException::withMessages(['choices' => ['Your account is already scheduled for deletion.']]);
            }
            $now = now();
            $req = AccountDeletionRequest::create(['user_id' => $user->id, 'status' => 'scheduled', 'requested_at' => $now,
                'run_after' => $now->copy()->addDays(self::COOLING_OFF_DAYS), 'choices' => (object) $choices]);
            $user->forceFill(['deletion_scheduled_for' => $req->run_after])->save();
            // Other devices are signed out; this one stays so the rider can export or cancel.
            DB::table('sessions')->where('user_id', $user->id)->when($currentSessionId, fn ($q, $id) => $q->where('id', '!=', $id))->delete();
            Audit::record('account.deletion_requested', $req, null, ['run_after' => $req->run_after->toIso8601String(), 'contributions' => count($choices)]);

            return $req;
        });
        $user->notify(new AccountDeletionMail('scheduled', $req->run_after));

        return $req;
    }

    public function cancel(User $user): void
    {
        DB::transaction(function () use ($user) {
            $req = AccountDeletionRequest::where(['user_id' => $user->id, 'status' => 'scheduled'])->lockForUpdate()->first();
            if (! $req) {
                throw ValidationException::withMessages(['deletion' => ['There is no deletion to cancel.']]);
            }
            $req->update(['status' => 'cancelled', 'cancelled_at' => now(), 'choices' => (object) array_count_values($req->choices ?? [])]); // D-10: categories only
            $user->forceFill(['deletion_scheduled_for' => null])->save();
            // Open offers are withdrawn; transfers someone already accepted stay with them.
            $withdrawn = OwnershipTransfer::where(['from_user_id' => $user->id, 'status' => 'offered'])->update(['status' => 'withdrawn', 'responded_at' => now()]);
            Audit::record('account.deletion_cancelled', $req, null, ['offers_withdrawn' => $withdrawn]);
        });
        $user->notify(new AccountDeletionMail('cancelled'));
    }
}
