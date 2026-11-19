<?php

use App\Support\Permissions;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Places of interest (R2, R3, R9, R12, R13, A4, L4). Additive only. Coordinates are latitude/longitude (PostGIS is not
// approved, D3); the PostGIS plan adds a generated geography column from these later without changing them.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('places', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('slug', 100)->unique();
            $t->string('name', 120);
            $t->string('category', 20);
            $t->string('summary', 300)->nullable();
            $t->text('description')->nullable();
            $t->decimal('latitude', 9, 6);
            $t->decimal('longitude', 9, 6);
            $t->foreignUuid('destination_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignUuid('media_asset_id')->nullable()->constrained()->nullOnDelete(); // cover image
            $t->jsonb('gallery')->default('[]'); // up to 8 more media-library image ids
            // Lifecycle: staff drafts and rider suggestions, review, publication.
            $t->string('status', 20)->default('draft');
            $t->text('moderation_note')->nullable(); // reason for rejection or requested changes; shown to the submitter
            $t->foreignUuid('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignUuid('created_by_staff_id')->nullable()->constrained('staff_accounts')->nullOnDelete();
            $t->foreignUuid('reviewed_by_staff_id')->nullable()->constrained('staff_accounts')->nullOnDelete();
            $t->date('observed_on')->nullable();
            $t->date('last_confirmed_on')->nullable();
            $t->timestamp('published_at')->nullable();
            $t->boolean('is_dev_seed')->default(false);
            $t->timestamps();
            $t->index(['status', 'category']);
            $t->index(['latitude', 'longitude']);
            $t->index('submitted_by');
        });
        DB::statement("alter table places add constraint places_category check (category in ('viewpoint', 'lake', 'waterfall', 'temple', 'monastery', 'heritage', 'cultural', 'attraction', 'rest_stop', 'hidden_place', 'other'))");
        DB::statement("alter table places add constraint places_status check (status in ('draft', 'pending_review', 'changes_requested', 'published', 'rejected', 'archived'))");
        // Nepal with a small margin: swapped or mistyped coordinates are refused by the database too.
        DB::statement('alter table places add constraint places_in_nepal check (latitude between 26.3 and 30.5 and longitude between 80.0 and 88.3)');

        // Corrections riders suggest for a published place. Staff apply accepted ones by editing the place themselves.
        Schema::create('place_corrections', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('place_id')->constrained()->cascadeOnDelete();
            $t->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $t->text('body');
            $t->decimal('latitude', 9, 6)->nullable(); // optional proposed position
            $t->decimal('longitude', 9, 6)->nullable();
            $t->string('status', 10)->default('pending');
            $t->text('review_note')->nullable();
            $t->foreignUuid('reviewed_by_staff_id')->nullable()->constrained('staff_accounts')->nullOnDelete();
            $t->timestamp('reviewed_at')->nullable();
            $t->timestamps();
            $t->index(['status', 'created_at']);
        });
        DB::statement("alter table place_corrections add constraint place_corrections_status check (status in ('pending', 'accepted', 'rejected'))");
        DB::statement('alter table place_corrections add constraint place_corrections_position check ((latitude is null) = (longitude is null) and (latitude is null or (latitude between 26.3 and 30.5 and longitude between 80.0 and 88.3)))');

        // R12: rider experiences can be filed under a place.
        Schema::table('contributions', fn (Blueprint $t) => $t->foreignUuid('place_id')->nullable()->constrained()->nullOnDelete());

        DB::statement("alter table saved_items drop constraint saved_items_type");
        DB::statement("alter table saved_items add constraint saved_items_type check (item_type in ('destination', 'route', 'service', 'contribution', 'place'))");

        Permissions::sync();
    }

    public function down(): void
    {
        DB::statement("delete from saved_items where item_type = 'place'");
        DB::statement("alter table saved_items drop constraint saved_items_type");
        DB::statement("alter table saved_items add constraint saved_items_type check (item_type in ('destination', 'route', 'service', 'contribution'))");
        Schema::table('contributions', fn (Blueprint $t) => $t->dropConstrainedForeignId('place_id'));
        Schema::dropIfExists('place_corrections');
        Schema::dropIfExists('places');
    }
};
