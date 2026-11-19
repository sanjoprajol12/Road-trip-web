<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Trip invitations (a rider joins a trip only by accepting) and contribution-only suspensions (separate from account
// suspension, with reason, end date, appeal and review). Additive only; existing trip members stay members.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trip_invitations', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('trip_id')->constrained()->cascadeOnDelete();
            $t->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $t->foreignUuid('invited_by')->nullable()->constrained('users')->nullOnDelete();
            $t->string('role', 10);
            $t->string('status', 10)->default('pending');
            $t->timestamp('expires_at');
            $t->timestamp('responded_at')->nullable();
            $t->timestamps();
            $t->index(['user_id', 'status']);
        });
        DB::statement("alter table trip_invitations add constraint trip_invitations_role check (role in ('editor', 'viewer'))");
        DB::statement("alter table trip_invitations add constraint trip_invitations_status check (status in ('pending', 'accepted', 'declined', 'cancelled'))");
        DB::statement("create unique index trip_invitations_one_pending on trip_invitations (trip_id, user_id) where status = 'pending'");

        Schema::create('contribution_suspensions', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $t->timestamp('until');
            $t->string('reason', 500);
            $t->foreignUuid('created_by_staff_id')->nullable()->constrained('staff_accounts')->nullOnDelete();
            $t->timestamp('lifted_at')->nullable();
            $t->foreignUuid('lifted_by_staff_id')->nullable()->constrained('staff_accounts')->nullOnDelete();
            $t->string('lift_note', 500)->nullable();
            $t->text('appeal')->nullable();
            $t->timestamp('appealed_at')->nullable();
            $t->string('appeal_decision', 10)->nullable();
            $t->timestamps();
            $t->index(['user_id', 'until']);
        });
        DB::statement("alter table contribution_suspensions add constraint contribution_suspensions_decision check (appeal_decision is null or appeal_decision in ('upheld', 'lifted'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('contribution_suspensions');
        Schema::dropIfExists('trip_invitations');
    }
};
