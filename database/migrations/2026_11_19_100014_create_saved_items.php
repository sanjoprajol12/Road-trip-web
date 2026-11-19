<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Saved destinations, routes, services and experiences (one row per rider and item), and trip stops linked to a service.
// Additive: a new table and one nullable column.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('saved_items', function (Blueprint $t) {
            $t->id();
            $t->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $t->string('item_type', 20);
            $t->uuid('item_id');
            $t->timestamp('created_at')->useCurrent();
            $t->unique(['user_id', 'item_type', 'item_id']);
            $t->index(['item_type', 'item_id']);
        });
        DB::statement("alter table saved_items add constraint saved_items_type check (item_type in ('destination', 'route', 'service', 'contribution'))");

        Schema::table('trip_stops', fn (Blueprint $t) => $t->foreignUuid('service_point_id')->nullable()->constrained()->nullOnDelete());
    }

    public function down(): void
    {
        Schema::table('trip_stops', fn (Blueprint $t) => $t->dropConstrainedForeignId('service_point_id'));
        Schema::dropIfExists('saved_items');
    }
};
