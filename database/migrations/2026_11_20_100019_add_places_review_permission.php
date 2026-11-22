<?php

use App\Support\Permissions;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// Owner-approved (session 17 follow-up): the built-in moderator role reviews rider place suggestions and corrections
// (places.review) without places.manage or places.publish. Additive and idempotent: only this one grant is added;
// other roles and assignments are untouched. Administrators already hold every registry key.
return new class extends Migration
{
    public function up(): void
    {
        Permissions::sync(); // adds the places.review key from config/permissions.php
        if (DB::table('roles')->where('slug', 'moderator')->exists()) {
            DB::table('permission_role')->insertOrIgnore(['role_slug' => 'moderator', 'permission_key' => 'places.review']);
        }
    }

    public function down(): void
    {
        DB::table('permission_role')->where(['role_slug' => 'moderator', 'permission_key' => 'places.review'])->delete();
    }
};
