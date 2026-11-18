<?php

namespace App\Actions\Fortify;

use App\Models\User;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Laravel\Fortify\Contracts\ResetsUserPasswords;

class ResetUserPassword implements ResetsUserPasswords
{
    use PasswordValidationRules;

    public function reset(User $user, array $input): void
    {
        Validator::make($input, ['password' => $this->passwordRules()])->validate();

        DB::transaction(function () use ($user, $input) {
            $user->forceFill([
                'password' => $input['password'],
                // The emailed link proves the address belongs to this rider.
                'email_verified_at' => $user->email_verified_at ?? now(),
            ])->save();
            // Anyone signed in with the old password is signed out everywhere.
            DB::table('sessions')->where('user_id', $user->id)->delete();
            Audit::record('auth.password_reset', $user);
        });
    }
}
