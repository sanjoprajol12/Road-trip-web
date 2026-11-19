<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Stage 4, trust pipeline. Additive only: new columns are nullable or defaulted, existing reports become category "other".
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contributions', function (Blueprint $t) {
            $t->date('last_confirmed_on')->nullable();
            // Staff ask the author to correct something; the author answers with an addendum, never by rewriting the body.
            $t->text('correction_request')->nullable();
            $t->timestamp('correction_requested_at')->nullable();
            $t->text('author_correction')->nullable();
            $t->timestamp('author_corrected_at')->nullable();
            // The addendum is public only after a moderator has seen it.
            $t->timestamp('author_correction_reviewed_at')->nullable();
        });

        Schema::table('contribution_reports', function (Blueprint $t) {
            $t->string('category', 20)->default('other');
            $t->string('resolution', 10)->nullable();
            $t->index(['contribution_id', 'resolved_at']);
        });
        DB::statement("alter table contribution_reports add constraint contribution_reports_category check (category in ('dangerous', 'wrong', 'outdated', 'spam', 'offensive', 'other'))");
        DB::statement("alter table contribution_reports add constraint contribution_reports_resolution check (resolution is null or resolution in ('upheld', 'dismissed'))");

        // A rider other than the author says "still accurate" on a day; one confirmation per rider per day.
        Schema::create('contribution_confirmations', function (Blueprint $t) {
            $t->id();
            $t->foreignUuid('contribution_id')->constrained()->cascadeOnDelete();
            $t->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $t->date('confirmed_on');
            $t->timestamp('created_at')->useCurrent();
            $t->unique(['contribution_id', 'user_id', 'confirmed_on']);
        });

        // Structured citations shared by editorial records and officially sourced contributions.
        Schema::create('sources', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('title', 200);
            $t->string('publisher', 200)->nullable();
            $t->string('url', 500)->nullable();
            $t->date('accessed_on')->nullable();
            $t->foreignUuid('created_by_staff_id')->nullable()->constrained('staff_accounts')->nullOnDelete();
            $t->timestamps();
        });
        DB::statement("alter table sources add constraint sources_url_https check (url is null or url ~* '^https?://')");
        Schema::create('sourceables', function (Blueprint $t) {
            $t->foreignUuid('source_id')->constrained()->cascadeOnDelete();
            $t->string('sourceable_type', 40);
            $t->uuid('sourceable_id');
            $t->unsignedSmallInteger('position')->default(0);
            $t->primary(['source_id', 'sourceable_type', 'sourceable_id']);
            $t->index(['sourceable_type', 'sourceable_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sourceables');
        Schema::dropIfExists('sources');
        Schema::dropIfExists('contribution_confirmations');
        DB::statement('alter table contribution_reports drop constraint if exists contribution_reports_category');
        DB::statement('alter table contribution_reports drop constraint if exists contribution_reports_resolution');
        Schema::table('contribution_reports', function (Blueprint $t) {
            $t->dropIndex(['contribution_id', 'resolved_at']);
            $t->dropColumn(['category', 'resolution']);
        });
        Schema::table('contributions', fn (Blueprint $t) => $t->dropColumn(['last_confirmed_on', 'correction_request', 'correction_requested_at', 'author_correction', 'author_corrected_at', 'author_correction_reviewed_at']));
    }
};
