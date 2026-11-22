<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Session 17, additive: places as trip stops (once per trip), and real route geometry per segment.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('trip_stops', fn (Blueprint $t) => $t->foreignUuid('place_id')->nullable()->constrained()->nullOnDelete());
        DB::statement('create unique index trip_stops_one_place on trip_stops (trip_id, place_id) where place_id is not null');

        // [[lat, lng], ...] imported by staff from a GPX/GeoJSON track. Null = no real geometry: maps draw no line.
        Schema::table('route_segments', fn (Blueprint $t) => $t->jsonb('path')->nullable());
    }

    public function down(): void
    {
        Schema::table('route_segments', fn (Blueprint $t) => $t->dropColumn('path'));
        DB::statement('drop index if exists trip_stops_one_place');
        Schema::table('trip_stops', fn (Blueprint $t) => $t->dropConstrainedForeignId('place_id'));
    }
};
