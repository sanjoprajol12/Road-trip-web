<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Private planning workspaces. A trip with more than one member is a group trip, never a public event.
        Schema::create('trips', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('owner_id')->constrained('users')->cascadeOnDelete();
            $table->string('title', 120);
            $table->date('starts_on')->nullable();
            $table->date('ends_on')->nullable();
            $table->string('motorcycle', 120)->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });
        DB::statement('alter table trips add constraint trips_dates check (ends_on is null or starts_on is null or ends_on >= starts_on)');

        Schema::create('trip_members', function (Blueprint $table) {
            $table->foreignUuid('trip_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->enum('role', ['owner', 'editor', 'viewer']);
            $table->timestamps();
            $table->primary(['trip_id', 'user_id']);
            $table->index('user_id');
        });

        Schema::create('trip_stops', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('trip_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('position');
            $table->unsignedSmallInteger('day')->nullable();
            $table->string('name', 120);
            $table->foreignUuid('destination_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUuid('route_id')->nullable()->constrained()->nullOnDelete();
            $table->boolean('overnight')->default(false);
            $table->string('note', 1000)->nullable();
            $table->timestamps();
        });

        Schema::create('trip_expenses', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('trip_id')->constrained()->cascadeOnDelete();
            $table->string('label', 120);
            $table->enum('category', ['fuel', 'lodging', 'food', 'permits', 'repairs', 'other'])->default('other');
            $table->decimal('amount_npr', 10, 2);
            $table->foreignUuid('paid_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
        DB::statement('alter table trip_expenses add constraint trip_expenses_amount check (amount_npr >= 0)');

        Schema::create('trip_checklist_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('trip_id')->constrained()->cascadeOnDelete();
            $table->string('label', 200);
            $table->enum('section', ['motorcycle', 'personal', 'trip'])->default('trip');
            $table->boolean('done')->default(false);
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trip_checklist_items');
        Schema::dropIfExists('trip_expenses');
        Schema::dropIfExists('trip_stops');
        Schema::dropIfExists('trip_members');
        Schema::dropIfExists('trips');
    }
};
