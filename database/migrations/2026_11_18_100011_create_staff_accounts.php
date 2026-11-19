<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Separate staff accounts (owner direction 2026-11-18). Riders stay in `users`; staff sign in with their own
 * accounts, guard, session table and password broker. Additive only:
 * - every person who held a staff role gets a staff account with the same email, name, roles and deactivation,
 *   **without a password** (they set one through a staff reset link or `mrn:staff:create-admin`), so a rider
 *   password never becomes a staff credential; accounts are not linked automatically;
 * - `user_roles` and every old "*_by" column stay as history; new staff actions write the new `*_staff_id` columns.
 */
return new class extends Migration
{
    /** Tables where staff record who acted: [table, old column, new column]. */
    private const ACTOR_COLUMNS = [
        ['user_verifications', 'reviewed_by', 'reviewed_by_staff_id'],
        ['destinations', 'created_by', 'created_by_staff_id'],
        ['routes', 'created_by', 'created_by_staff_id'],
        ['contribution_reports', 'resolved_by', 'resolved_by_staff_id'],
        ['guides', 'author_id', 'author_staff_id'],
        ['event_updates', 'author_id', 'author_staff_id'],
        ['staff_invitations', 'invited_by', 'invited_by_staff_id'],
        ['settings', 'updated_by', 'updated_by_staff_id'],
        ['media_assets', 'uploaded_by', 'uploaded_by_staff_id'],
    ];

    public function up(): void
    {
        Schema::create('staff_accounts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('email')->unique();
            $table->string('display_name', 60);
            $table->string('password')->nullable();
            $table->rememberToken();
            // Set only by the explicit linking flow (the staff member proves the rider password). Used for the
            // "nobody reviews their own work" rule; it never grants a rider anything.
            $table->foreignUuid('rider_user_id')->nullable()->unique()->constrained('users')->nullOnDelete();
            $table->timestamp('deactivated_at')->nullable();
            $table->string('deactivation_reason', 500)->nullable();
            $table->timestamp('last_login_at')->nullable();
            $table->timestamps();
        });
        DB::statement("alter table staff_accounts add constraint staff_accounts_email_lower check (email = lower(email))");

        Schema::create('staff_account_roles', function (Blueprint $table) {
            $table->foreignUuid('staff_account_id')->constrained('staff_accounts')->cascadeOnDelete();
            $table->string('role', 40);
            $table->foreignUuid('granted_by')->nullable()->constrained('staff_accounts')->nullOnDelete();
            $table->timestamp('granted_at')->useCurrent();
            $table->primary(['staff_account_id', 'role']);
            $table->foreign('role')->references('slug')->on('roles')->cascadeOnUpdate()->restrictOnDelete();
        });

        Schema::create('staff_password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });

        // Staff sessions live in their own table under their own cookie (App\Http\Middleware\SelectSessionAudience).
        Schema::create('staff_sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignUuid('user_id')->nullable()->index()->constrained('staff_accounts')->cascadeOnDelete();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });

        foreach (self::ACTOR_COLUMNS as [$table, , $new]) {
            Schema::table($table, fn (Blueprint $t) => $t->foreignUuid($new)->nullable()->constrained('staff_accounts')->nullOnDelete());
        }
        Schema::table('staff_invitations', fn (Blueprint $t) => $t->foreignUuid('staff_account_id')->nullable()->constrained('staff_accounts')->nullOnDelete());

        Schema::table('audit_logs', fn (Blueprint $t) => $t->string('actor_type', 10)->default('user'));
        DB::statement("alter table audit_logs add constraint audit_logs_actor_type check (actor_type in ('user', 'staff'))");

        foreach (['staff_accounts', 'staff_account_roles', 'staff_password_reset_tokens', 'staff_sessions'] as $t) {
            DB::statement("grant select, insert, update, delete on {$t} to mrn_app");
        }

        // Carry over every existing staff member (same roles, same deactivation), without a password.
        $now = now();
        $people = DB::table('users')->whereIn('id', DB::table('user_roles')->select('user_id'))->get(['id', 'email', 'display_name', 'deactivated_at']);
        foreach ($people as $u) {
            $id = (string) Str::uuid();
            DB::table('staff_accounts')->insert(['id' => $id, 'email' => Str::lower($u->email), 'display_name' => $u->display_name,
                'deactivated_at' => $u->deactivated_at, 'created_at' => $now, 'updated_at' => $now]);
            foreach (DB::table('user_roles')->where('user_id', $u->id)->pluck('role') as $role) {
                DB::table('staff_account_roles')->insert(['staff_account_id' => $id, 'role' => $role, 'granted_at' => $now]);
            }
        }
    }

    public function down(): void
    {
        DB::statement('alter table audit_logs drop constraint if exists audit_logs_actor_type');
        Schema::table('audit_logs', fn (Blueprint $t) => $t->dropColumn('actor_type'));
        Schema::table('staff_invitations', fn (Blueprint $t) => $t->dropConstrainedForeignId('staff_account_id'));
        foreach (self::ACTOR_COLUMNS as [$table, , $new]) {
            Schema::table($table, fn (Blueprint $t) => $t->dropConstrainedForeignId($new));
        }
        Schema::dropIfExists('staff_sessions');
        Schema::dropIfExists('staff_password_reset_tokens');
        Schema::dropIfExists('staff_account_roles');
        Schema::dropIfExists('staff_accounts');
    }
};
