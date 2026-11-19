<?php

use App\Support\Permissions;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Configurable staff roles (roadmap stage 2). Additive only: `user_roles` keeps every existing assignment;
 * its fixed role list becomes a foreign key to `roles`, and the two old roles become system roles with the
 * same rights they had (admin: everything, moderator: the defaults in config/permissions.php).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('roles', function (Blueprint $table) {
            $table->string('slug', 40)->primary();
            $table->string('name', 60)->unique();
            $table->string('description', 300)->nullable();
            $table->boolean('is_system')->default(false);
            $table->timestamps();
        });
        DB::statement("alter table roles add constraint roles_slug check (slug ~ '^[a-z][a-z0-9_]{1,39}$')");

        Schema::create('permissions', function (Blueprint $table) {
            $table->string('key', 80)->primary();
            $table->string('module', 60);
            $table->string('action', 40);
            $table->string('description', 200);
        });

        Schema::create('permission_role', function (Blueprint $table) {
            $table->string('role_slug', 40);
            $table->string('permission_key', 80);
            $table->primary(['role_slug', 'permission_key']);
            $table->foreign('role_slug')->references('slug')->on('roles')->cascadeOnDelete();
            $table->foreign('permission_key')->references('key')->on('permissions')->cascadeOnDelete();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('deactivated_at')->nullable();
        });

        Schema::create('staff_invitations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('email');
            $table->string('token_hash', 64)->unique();
            $table->jsonb('roles');
            $table->foreignUuid('invited_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('expires_at');
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
            $table->index('email');
        });

        Permissions::sync();
        $now = now();
        DB::table('roles')->insert([
            ['slug' => 'admin', 'name' => 'Administrator', 'description' => 'Every permission, always. At least one active administrator must remain.', 'is_system' => true, 'created_at' => $now, 'updated_at' => $now],
            ['slug' => 'moderator', 'name' => 'Moderator', 'description' => 'Reviews claims and contributions, manages content, cancels events, suspends riders.', 'is_system' => true, 'created_at' => $now, 'updated_at' => $now],
        ]);
        DB::table('permission_role')->insert(array_map(fn ($k) => ['role_slug' => 'moderator', 'permission_key' => $k], config('permissions.defaults.moderator')));

        // The old enum becomes a reference to `roles`; existing rows already use 'admin' / 'moderator'.
        DB::statement('alter table user_roles drop constraint if exists user_roles_role_check');
        DB::statement('alter table user_roles alter column role type varchar(40)');
        DB::statement('alter table user_roles add constraint user_roles_role_foreign foreign key (role) references roles(slug) on update cascade on delete restrict');
    }

    public function down(): void
    {
        DB::statement('alter table user_roles drop constraint if exists user_roles_role_foreign');
        DB::statement("delete from user_roles where role not in ('admin', 'moderator')");
        DB::statement("alter table user_roles add constraint user_roles_role_check check (role in ('moderator', 'admin'))");
        Schema::dropIfExists('staff_invitations');
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('deactivated_at'));
        Schema::dropIfExists('permission_role');
        Schema::dropIfExists('permissions');
        Schema::dropIfExists('roles');
    }
};
