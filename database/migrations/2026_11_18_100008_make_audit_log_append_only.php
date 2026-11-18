<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** The app role may add audit rows but never change or remove them. */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::selectOne("select 1 as ok from pg_roles where rolname = 'mrn_app'")) {
            DB::statement('revoke update, delete, truncate on audit_logs from mrn_app');
        }
    }

    public function down(): void
    {
        if (DB::selectOne("select 1 as ok from pg_roles where rolname = 'mrn_app'")) {
            DB::statement('grant update, delete on audit_logs to mrn_app');
        }
    }
};
