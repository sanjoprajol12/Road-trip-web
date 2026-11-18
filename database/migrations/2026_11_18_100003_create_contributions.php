<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One table for every kind of rider knowledge, so there is one moderation pipeline.
        Schema::create('contributions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('author_id')->constrained('users')->cascadeOnDelete();
            $table->enum('kind', ['trip', 'road_report', 'accommodation', 'fuel', 'mechanic', 'recommendation', 'unexpected', 'observation']);
            $table->string('title', 140);
            $table->text('body');
            $table->date('observed_on');
            $table->foreignUuid('destination_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUuid('route_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUuid('route_segment_id')->nullable()->constrained()->nullOnDelete();
            $table->jsonb('details')->default('{}');
            $table->enum('status', ['pending_review', 'published', 'community_corroborated', 'officially_sourced', 'outdated', 'rejected', 'flagged'])->default('pending_review');
            $table->text('moderation_note')->nullable();
            $table->string('source_url', 500)->nullable();
            $table->timestamps();
            $table->index(['status', 'kind']);
            $table->index(['route_segment_id', 'observed_on']);
        });
        DB::statement('alter table contributions add constraint contributions_body_length check (char_length(trim(body)) between 20 and 10000)');
        DB::statement("alter table contributions add constraint contributions_road_report_segment check (kind <> 'road_report' or route_segment_id is not null)");

        Schema::create('contribution_media', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('contribution_id')->constrained()->cascadeOnDelete();
            $table->string('path');
            $table->unsignedSmallInteger('width');
            $table->unsignedSmallInteger('height');
            $table->string('alt', 200);
            $table->timestamps();
        });

        Schema::create('contribution_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('contribution_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('reporter_id')->constrained('users')->cascadeOnDelete();
            $table->string('reason', 1000);
            $table->timestamp('resolved_at')->nullable();
            $table->foreignUuid('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['contribution_id', 'reporter_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contribution_reports');
        Schema::dropIfExists('contribution_media');
        Schema::dropIfExists('contributions');
    }
};
