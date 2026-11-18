<?php

namespace App\Actions\Fortify;

use App\Models\User;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Laravel\Fortify\Contracts\UpdatesUserPasswords;

class UpdateUserPassword implements UpdatesUserPasswords
{
    use PasswordValidationRules;

    public function update(User $user, array $input): void
    {
        Validator::make($input, [
            'current_password' => ['required', 'string', 'current_password:web'],
            'password' => $this->passwordRules(),
        ], ['current_password.current_password' => 'Your current password is not correct.'])->validateWithBag('updatePassword');

        DB::transaction(function () use ($user, $input) {
            $user->forceFill(['password' => $input['password']])->save();
            // Other devices are signed out; this session stays.
            DB::table('sessions')->where('user_id', $user->id)->where('id', '!=', session()->getId())->delete();
            Audit::record('auth.password_changed', $user);
        });
    }
}
