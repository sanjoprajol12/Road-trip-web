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

/*
 * Account deletion (owner decisions D-01..D-04, D-10; session 28 switches). Three daily commands, each safe to repeat:
 * - process-deletions: due deletions. Off unless ACCOUNT_DELETION_PROCESSING_ENABLED=true.
 * - purge-deletion-records: finished deletion records older than one year. Off unless ACCOUNT_DELETION_RECORDS_PURGE_ENABLED=true.
 *   The two switches are independent: enabling one never enables the other.
 * - expire-evidence: ends moderation evidence holds at 30 days and retries failed file removals. No switch: it only
 *   removes data whose retention already ended (D-04 allows no longer hold), and does nothing before any deletion ran.
 * R5: a failure is logged with its exception class and step only (never the message, which can hold SQL values).
 */
$safely = function (string $step, callable $work, $command): int {
    try {
        return $work();
    } catch (\Throwable $e) {
        \Illuminate\Support\Facades\Log::error('Account deletion maintenance step failed.', ['step' => $step, 'exception' => $e::class]);
        $command->error($step." failed (".$e::class."); nothing was lost and the next run retries.");

        return 1;
    }
};

Artisan::command('mrn:accounts:process-deletions', function () use ($safely) {
    if (! config('mrn.account_deletion.processing_enabled')) {
        $this->info('Account deletion processing is switched off (ACCOUNT_DELETION_PROCESSING_ENABLED). Nothing was changed.');

        return 0;
    }

    return $safely('process-deletions', function () {
        $runner = app(\App\Actions\Accounts\ProcessAccountDeletion::class);
        $tally = ['done' => 0, 'skipped' => 0, 'failed' => 0];
        \App\Models\AccountDeletionRequest::where('status', 'scheduled')->where('run_after', '<=', now())->orderBy('run_after')
            ->each(function ($req) use ($runner, &$tally) {
                $tally[$runner->run($req)]++;
            });
        $files = \App\Support\PendingFiles::flush();
        $this->info("Deletions done {$tally['done']}, skipped {$tally['skipped']}, failed {$tally['failed']}; files removed {$files['removed']}, still failing {$files['failed']}.");

        return $tally['failed'] > 0 || $files['failed'] > 0 ? 1 : 0;
    }, $this);
})->purpose('Run due account deletions (only when ACCOUNT_DELETION_PROCESSING_ENABLED=true)');

Artisan::command('mrn:accounts:purge-deletion-records', function () use ($safely) {
    if (! config('mrn.account_deletion.records_purge_enabled')) {
        $this->info('Deletion record purging is switched off (ACCOUNT_DELETION_RECORDS_PURGE_ENABLED). Nothing was changed.');

        return 0;
    }

    return $safely('purge-deletion-records', function () {
        // D-10: finished records (done or cancelled) are kept one year, then deleted. Scheduled ones never are. The
        // application role has no DELETE on these records (session 31): the database function keeps the boundary by the
        // database clock and writes its own audit entry.
        $expired = (int) \Illuminate\Support\Facades\DB::selectOne('select mrn_purge_deletion_records(?) as n', [now()->toDateTimeString()])->n;
        $this->info("Deletion records purged {$expired}.");

        return 0;
    }, $this);
})->purpose('Delete finished account deletion records older than one year (only when ACCOUNT_DELETION_RECORDS_PURGE_ENABLED=true)');

Artisan::command('mrn:accounts:expire-evidence', function () use ($safely) {
    return $safely('expire-evidence', function () {
        $evidence = app(\App\Actions\Accounts\DisposeModerationEvidence::class)->purgeExpired();
        $files = \App\Support\PendingFiles::flush();
        $this->info("Evidence expired {$evidence}; files removed {$files['removed']}, still failing {$files['failed']}.");

        return $files['failed'] > 0 ? 1 : 0;
    }, $this);
})->purpose('End moderation evidence holds that reached 30 days and retry failed file removals');

foreach (['mrn:accounts:process-deletions' => '02:30', 'mrn:accounts:expire-evidence' => '02:50', 'mrn:accounts:purge-deletion-records' => '03:10'] as $cmd => $at) {
    \Illuminate\Support\Facades\Schedule::command($cmd)->dailyAt($at)->withoutOverlapping()->onOneServer();
}

// F1 and Option B: the production checklist's database part, checked as the application role. Prints no secret.
Artisan::command('mrn:security:check-database', function () {
    $failed = 0;
    foreach (\App\Support\DatabaseSecurity::checks() as [$check, $ok, $detail]) {
        $this->line(($ok ? 'PASS ' : 'FAIL ').$check.($detail !== '' ? " ({$detail})" : ''));
        $failed += $ok ? 0 : 1;
    }
    $failed ? $this->error("{$failed} check(s) failed.") : $this->info('All database security checks passed.');

    return $failed ? 1 : 0;
})->purpose('Check the application database role\'s rights, the guard triggers and the definer functions (no secrets printed)');
