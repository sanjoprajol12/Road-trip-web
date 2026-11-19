<?php

namespace App\Actions\Verification;

use App\Enums\VerificationStatus;
use App\Models\StaffAccount;
use App\Models\Verification;
use App\Notifications\VerificationDecided;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DecideVerification
{
    public function __invoke(StaffAccount $reviewer, Verification $claim, VerificationStatus $to, ?string $note): Verification
    {
        $note = $note !== null && trim($note) !== '' ? trim($note) : null;

        $claim = DB::transaction(function () use ($reviewer, $claim, $to, $note) {
            $claim = Verification::whereKey($claim->id)->lockForUpdate()->firstOrFail();
            if (! in_array($to, $claim->status->next(), true)) {
                throw ValidationException::withMessages(['status' => ["A claim cannot move from {$claim->status->value} to {$to->value}."]]);
            }
            // A negative decision needs a new reason; an old approval note does not count.
            if ($to->needsReason() && ($note === null || mb_strlen($note) < 10 || $note === $claim->review_note)) {
                throw ValidationException::withMessages(['review_note' => ['Give a new reason of at least 10 characters. The rider will see it.']]);
            }
            $old = $claim->only(['status', 'review_note']);
            $claim->forceFill([
                'status' => $to,
                'review_note' => $note ?? $claim->review_note,
                'reviewed_by_staff_id' => $reviewer->id,
                'reviewed_at' => now(),
            ])->save();
            Audit::record('verification.'.$to->value, $claim, $old, $claim->only(['status', 'review_note', 'kind', 'scope', 'user_id']));

            return $claim;
        });
        $claim->user->notify(new VerificationDecided($claim));

        return $claim;
    }
}
