<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Session 19, additive: a trip stop can be a whole route, one stretch, or one checkpoint (docs/architecture/TRIP_ROUTE_PARTS.md).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('trip_stops', function (Blueprint $t) {
            $t->foreignUuid('route_segment_id')->nullable()->constrained()->nullOnDelete();
            $t->string('route_part', 20)->nullable();
        });
        DB::statement("alter table trip_stops add constraint trip_stops_route_part check (route_part in ('route', 'stretch', 'checkpoint_start', 'checkpoint_end'))");
        // Stops made through the generic API with only a route link were whole-route stops all along.
        DB::statement("update trip_stops set route_part = 'route' where route_id is not null and destination_id is null and service_point_id is null and place_id is null");
        DB::statement("create unique index trip_stops_one_route on trip_stops (trip_id, route_id) where route_part = 'route' and route_id is not null");
        DB::statement('create unique index trip_stops_one_route_part on trip_stops (trip_id, route_segment_id, route_part) where route_segment_id is not null');
    }

    public function down(): void
    {
        DB::statement('drop index if exists trip_stops_one_route_part');
        DB::statement('drop index if exists trip_stops_one_route');
        DB::statement('alter table trip_stops drop constraint if exists trip_stops_route_part');
        Schema::table('trip_stops', function (Blueprint $t) {
            $t->dropColumn('route_part');
            $t->dropConstrainedForeignId('route_segment_id');
        });
    }
};
