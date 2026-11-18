<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organizer_id')->constrained('users')->cascadeOnDelete();
            $table->enum('category', ['ride', 'gathering']);
            $table->string('subtype', 40);
            $table->string('title', 140);
            $table->text('description');
            $table->timestamp('starts_at');
            $table->timestamp('ends_at');
            $table->string('meeting_point', 200);
            $table->foreignUuid('route_id')->nullable()->constrained()->nullOnDelete();
            $table->enum('visibility', ['public', 'private'])->default('public');
            $table->enum('join_method', ['instant', 'approval'])->default('instant');
            $table->unsignedInteger('capacity')->nullable();
            $table->boolean('waitlist_enabled')->default(false);
            $table->enum('status', ['draft', 'published', 'full', 'postponed', 'cancelled', 'completed'])->default('draft');
            $table->text('summary')->nullable();
            $table->timestamps();
            $table->index(['status', 'starts_at']);
        });
        DB::statement('alter table events add constraint events_time check (ends_at > starts_at)');
        DB::statement('alter table events add constraint events_capacity check (capacity is null or capacity >= 1)');

        // One row per rider per event tells the whole story: invited, pending, approved, waitlisted, ...
        Schema::create('event_registrations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('event_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->enum('status', ['invited', 'pending', 'approved', 'declined', 'waitlisted', 'cancelled', 'attended', 'did_not_attend']);
            $table->timestamps();
            $table->unique(['event_id', 'user_id']);
            $table->index(['event_id', 'status']);
        });

        Schema::create('event_updates', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('event_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('author_id')->nullable()->constrained('users')->nullOnDelete();
            $table->enum('kind', ['update', 'postponement', 'cancellation', 'completion']);
            $table->text('body');
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('event_updates');
        Schema::dropIfExists('event_registrations');
        Schema::dropIfExists('events');
    }
};
