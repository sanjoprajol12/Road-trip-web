<?php

use App\Enums\StaffRole;
use App\Models\StaffAccount;
use App\Support\Audit;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 * The only way to create a staff administrator outside the staff portal (server access required; never on the web).
 * Allowed when no active administrator can sign in yet, or to give a password to an administrator account that has
 * none (accounts carried over from the old rider-role model). Everyone else joins by invitation from the portal.
 */
Artisan::command('mrn:staff:create-admin {email} {--name=}', function (string $email) {
    $email = Str::lower(trim($email));
    if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $this->error('That is not an email address.');

        return 1;
    }
    $staff = StaffAccount::where('email', $email)->first();
    $completing = $staff && $staff->password === null && $staff->isAdmin();
    $adminCanSignIn = DB::table('staff_account_roles')->join('staff_accounts', 'staff_accounts.id', '=', 'staff_account_roles.staff_account_id')
        ->where('role', StaffRole::Admin->value)->whereNull('staff_accounts.deactivated_at')->whereNotNull('staff_accounts.password')->exists();
    if (! $completing && ($adminCanSignIn || $staff)) {
        $this->error($staff ? 'That staff account already has a password or is not an administrator. Use the staff portal.'
            : 'An administrator can already sign in: invite new staff from the staff portal.');

        return 1;
    }
    $name = $staff?->display_name ?? trim((string) ($this->option('name') ?: $this->ask('Name other staff will see')));
    $password = (string) $this->secret('Staff password (at least 8 characters)');
    if (mb_strlen($password) < 8 || mb_strlen($password) > 200 || $password !== $this->secret('Repeat the password')) {
        $this->error('The passwords differ or are not 8 to 200 characters long.');

        return 1;
    }
    if (mb_strlen($name) < 2 || mb_strlen($name) > 60) {
        $this->error('The name must be 2 to 60 characters.');

        return 1;
    }
    DB::transaction(function () use (&$staff, $email, $name, $password) {
        $staff ??= new StaffAccount(['email' => $email]);
        $staff->forceFill(['display_name' => $name, 'password' => $password])->save();
        DB::table('staff_account_roles')->insertOrIgnore(['staff_account_id' => $staff->id, 'role' => StaffRole::Admin->value, 'granted_at' => now()]);
        Audit::record('staff.admin_created_by_console', $staff, null, ['staff_account_id' => $staff->id, 'email' => $email]);
    });
    $this->info("{$name} can now sign in to the staff portal as an administrator.");
})->purpose('Create the first staff administrator, or set the password of a carried-over administrator');

Artisan::command('mrn:permissions:sync', function () {
    \App\Support\Permissions::sync();
    $this->info(count(\App\Support\Permissions::keys()).' permissions in the registry are now in the database.');
})->purpose('Copy config/permissions.php into the permissions table (run after adding or removing a permission)');
