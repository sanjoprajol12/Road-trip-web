<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Session 20, additive: an expense is planned (amount may be unknown yet) or spent (amount required).
// Existing rows were entered as estimates ("Estimates you enter"), so they become planned.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('trip_expenses', function (Blueprint $t) {
            $t->string('status', 10)->default('planned');
            $t->decimal('amount_npr', 10, 2)->nullable()->change();
        });
        DB::statement("alter table trip_expenses add constraint trip_expenses_status check (status in ('planned', 'spent'))");
        DB::statement("alter table trip_expenses add constraint trip_expenses_spent_amount check (status = 'planned' or amount_npr is not null)");
    }

    public function down(): void
    {
        // Never invent amounts to restore NOT NULL: rows without one must be filled in or removed by a person first.
        if (DB::table('trip_expenses')->whereNull('amount_npr')->exists()) {
            throw new RuntimeException('Some planned expenses have no amount. Fill them in or remove them before rolling back.');
        }
        DB::statement('alter table trip_expenses drop constraint if exists trip_expenses_spent_amount');
        DB::statement('alter table trip_expenses drop constraint if exists trip_expenses_status');
        Schema::table('trip_expenses', function (Blueprint $t) {
            $t->decimal('amount_npr', 10, 2)->nullable(false)->change();
            $t->dropColumn('status');
        });
    }
};
