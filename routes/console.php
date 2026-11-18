<?php

use App\Models\User;
use App\Support\Audit;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

// Bootstrap the first administrator from the command line (never from the web).
Artisan::command('mrn:grant-admin {email}', function (string $email) {
    $user = User::where('email', strtolower($email))->first();
    if (! $user) {
        $this->error('No account with that email. Sign up first.');

        return 1;
    }
    if (! $user->hasVerifiedEmail()) {
        $this->error('Confirm the email address first.');

        return 1;
    }
    $inserted = DB::table('user_roles')->insertOrIgnore(['user_id' => $user->id, 'role' => 'admin', 'granted_at' => now()]);
    if ($inserted) {
        Audit::record('role.granted', 'user_roles', null, ['user_id' => $user->id, 'role' => 'admin', 'via' => 'console'], $user->id);
    }
    $this->info($inserted ? "{$user->display_name} is now an administrator." : 'Already an administrator.');
})->purpose('Make an existing, confirmed account an administrator');
