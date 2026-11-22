<?php

use App\Support\Permissions;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Account deletion with a 30-day cooling-off, per-contribution choices, ownership transfers and restricted
// moderation evidence (owner decisions Q9 D, D-01..D-04, 2026-11-22; design ACCOUNT_DELETION_AND_EXPORT.md).
// Additive, except two foreign keys that change from cascade to set null so an anonymised contribution and a
// cancelled event can outlive their rider. Existing rows are not changed.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', fn (Blueprint $t) => $t->timestamp('deletion_scheduled_for')->nullable());

        Schema::create('account_deletion_requests', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('user_id'); // no foreign key: the row is the proof that the deletion ran, after the user is gone
            $t->string('status', 12)->default('scheduled');
            $t->timestamp('requested_at');
            $t->timestamp('run_after');
            $t->timestamp('cancelled_at')->nullable();
            $t->timestamp('completed_at')->nullable();
            $t->jsonb('choices'); // contribution id => delete | anonymise | anonymise_without_photos (ids only, no content)
            $t->jsonb('counts')->nullable();
            $t->unsignedInteger('attempts')->default(0);
            $t->string('last_error', 200)->nullable(); // exception class only, never data
            $t->timestamps();
            $t->index(['status', 'run_after']);
        });
        DB::statement("alter table account_deletion_requests add constraint adr_status check (status in ('scheduled', 'cancelled', 'done'))");
        DB::statement("create unique index adr_one_scheduled on account_deletion_requests (user_id) where status = 'scheduled'");

        Schema::create('ownership_transfers', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('subject_type', 5);
            $t->uuid('subject_id');
            $t->foreignUuid('from_user_id')->constrained('users')->cascadeOnDelete();
            $t->foreignUuid('to_user_id')->constrained('users')->cascadeOnDelete();
            $t->string('status', 10)->default('offered');
            $t->timestamp('responded_at')->nullable();
            $t->timestamps();
            $t->index(['to_user_id', 'status']);
        });
        DB::statement("alter table ownership_transfers add constraint ot_subject check (subject_type in ('event', 'trip'))");
        DB::statement("alter table ownership_transfers add constraint ot_status check (status in ('offered', 'accepted', 'declined', 'withdrawn', 'expired'))");
        DB::statement("create unique index ot_one_open on ownership_transfers (subject_type, subject_id) where status = 'offered'");

        Schema::create('moderation_evidence', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('contribution_id'); // no foreign key: the contribution may be deleted
            $t->string('reason', 40);
            $t->jsonb('snapshot')->nullable(); // null once disposed
            $t->jsonb('files')->nullable();
            $t->timestamp('held_until');
            $t->string('disposition', 10)->nullable();
            $t->timestamp('disposed_at')->nullable();
            $t->timestamps();
            $t->index(['disposed_at', 'held_until']);
        });
        DB::statement("alter table moderation_evidence add constraint me_disposition check (disposition is null or disposition in ('expired', 'released'))");
        DB::statement("alter table moderation_evidence add constraint me_hold check (held_until <= created_at + interval '30 days')");

        // Files to remove after a committed database change; failed removals stay here and are retried.
        Schema::create('pending_file_deletions', function (Blueprint $t) {
            $t->id();
            $t->string('disk', 40);
            $t->string('path', 500);
            $t->unsignedInteger('attempts')->default(0);
            $t->string('last_error', 200)->nullable();
            $t->timestamps();
            $t->unique(['disk', 'path']);
        });

        Schema::table('contributions', fn (Blueprint $t) => $t->timestamp('author_deleted_at')->nullable());
        DB::statement('alter table contributions alter column author_id drop not null');
        DB::statement('alter table contributions drop constraint contributions_author_id_foreign');
        DB::statement('alter table contributions add constraint contributions_author_id_foreign foreign key (author_id) references users (id) on delete set null');
        // A contribution has an author unless the author deleted their account and chose to keep it anonymously.
        DB::statement('alter table contributions add constraint contributions_author_or_deleted check (author_id is not null or author_deleted_at is not null)');

        DB::statement('alter table events alter column organizer_id drop not null');
        DB::statement('alter table events drop constraint events_organizer_id_foreign');
        DB::statement('alter table events add constraint events_organizer_id_foreign foreign key (organizer_id) references users (id) on delete set null');
        // Only a cancelled event may be left without an organizer (the minimum record for its participants).
        DB::statement("alter table events add constraint events_organizer_or_cancelled check (organizer_id is not null or status = 'cancelled')");

        Permissions::sync(); // users.deletions.view, moderation.evidence.view (administrators only by default)
    }

    public function down(): void
    {
        // Reversible only while no anonymised contribution or organizer-less event exists.
        DB::statement('alter table events drop constraint if exists events_organizer_or_cancelled');
        DB::statement('alter table events drop constraint events_organizer_id_foreign');
        DB::statement('alter table events add constraint events_organizer_id_foreign foreign key (organizer_id) references users (id) on delete cascade');
        DB::statement('alter table events alter column organizer_id set not null');
        DB::statement('alter table contributions drop constraint if exists contributions_author_or_deleted');
        DB::statement('alter table contributions drop constraint contributions_author_id_foreign');
        DB::statement('alter table contributions add constraint contributions_author_id_foreign foreign key (author_id) references users (id) on delete cascade');
        DB::statement('alter table contributions alter column author_id set not null');
        Schema::table('contributions', fn (Blueprint $t) => $t->dropColumn('author_deleted_at'));
        Schema::dropIfExists('pending_file_deletions');
        Schema::dropIfExists('moderation_evidence');
        Schema::dropIfExists('ownership_transfers');
        Schema::dropIfExists('account_deletion_requests');
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn('deletion_scheduled_for'));
        DB::table('permission_role')->whereIn('permission_key', ['users.deletions.view', 'moderation.evidence.view'])->delete();
        DB::table('permissions')->whereIn('key', ['users.deletions.view', 'moderation.evidence.view'])->delete();
    }
};
