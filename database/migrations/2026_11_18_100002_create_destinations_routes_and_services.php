<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Staff-curated editorial records. Every factual record carries last_confirmed_on.
        Schema::create('destinations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('slug', 80)->unique();
            $table->string('name', 80);
            $table->enum('province', ['Koshi', 'Madhesh', 'Bagmati', 'Gandaki', 'Lumbini', 'Karnali', 'Sudurpashchim'])->nullable();
            $table->string('district', 60)->nullable();
            $table->string('summary', 400)->nullable();
            $table->text('overview')->nullable();
            $table->integer('elevation_m')->nullable();
            $table->decimal('latitude', 9, 6)->nullable();
            $table->decimal('longitude', 9, 6)->nullable();
            $table->string('photo_path')->nullable();
            $table->string('photo_alt')->nullable();
            $table->string('photo_credit')->nullable();
            $table->enum('status', ['draft', 'published', 'archived'])->default('draft');
            $table->date('last_confirmed_on')->nullable();
            $table->boolean('is_dev_seed')->default(false);
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });
        DB::statement("alter table destinations add constraint destinations_slug check (slug ~ '^[a-z0-9]+(-[a-z0-9]+)*$')");
        DB::statement('alter table destinations add constraint destinations_photo_credit check (photo_path is null or (photo_alt is not null and photo_credit is not null))');
        DB::statement('alter table destinations add constraint destinations_elevation check (elevation_m between 0 and 9000)');

        Schema::create('routes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('slug', 80)->unique();
            $table->string('name', 120);
            $table->string('summary', 400)->nullable();
            $table->foreignUuid('start_destination_id')->nullable()->constrained('destinations')->nullOnDelete();
            $table->foreignUuid('end_destination_id')->nullable()->constrained('destinations')->nullOnDelete();
            $table->decimal('distance_km', 7, 1)->nullable();
            $table->decimal('estimated_hours', 5, 1)->nullable();
            $table->enum('status', ['draft', 'published', 'archived'])->default('draft');
            $table->date('last_confirmed_on')->nullable();
            $table->boolean('is_dev_seed')->default(false);
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });
        DB::statement("alter table routes add constraint routes_slug check (slug ~ '^[a-z0-9]+(-[a-z0-9]+)*$')");
        DB::statement('alter table routes add constraint routes_numbers check (distance_km > 0 and estimated_hours > 0)');

        // A route is an ordered list of segments; each segment runs between two checkpoints and is reported on separately.
        Schema::create('route_segments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('route_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('position');
            $table->string('start_name', 80);
            $table->string('end_name', 80);
            $table->decimal('start_lat', 9, 6)->nullable();
            $table->decimal('start_lng', 9, 6)->nullable();
            $table->decimal('end_lat', 9, 6)->nullable();
            $table->decimal('end_lng', 9, 6)->nullable();
            $table->decimal('distance_km', 6, 1)->nullable();
            $table->enum('surface', ['paved', 'mixed', 'gravel', 'dirt', 'unknown'])->default('unknown');
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->unique(['route_id', 'position']);
        });

        // Fuel, mechanics, medical, lodging, food, ATMs, connectivity: observations, never live data.
        Schema::create('service_points', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->enum('kind', ['fuel', 'mechanic', 'medical', 'lodging', 'food', 'atm', 'connectivity']);
            $table->string('name', 120);
            $table->foreignUuid('destination_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUuid('route_segment_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('latitude', 9, 6)->nullable();
            $table->decimal('longitude', 9, 6)->nullable();
            $table->text('notes')->nullable();
            $table->enum('status', ['draft', 'published', 'archived'])->default('draft');
            $table->date('last_confirmed_on')->nullable();
            $table->timestamps();
        });
        DB::statement('alter table service_points add constraint service_points_place check (destination_id is not null or route_segment_id is not null)');
    }

    public function down(): void
    {
        Schema::dropIfExists('service_points');
        Schema::dropIfExists('route_segments');
        Schema::dropIfExists('routes');
        Schema::dropIfExists('destinations');
    }
};
