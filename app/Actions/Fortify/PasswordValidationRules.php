<?php

namespace App\Actions\Fortify;

use Illuminate\Validation\Rules\Password;

trait PasswordValidationRules
{
    /** One field, at least 8 characters (the frontend shows it once, with a hint). */
    protected function passwordRules(): array
    {
        return ['required', 'string', Password::min(8)->max(200)];
    }
}
