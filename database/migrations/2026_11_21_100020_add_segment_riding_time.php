<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// B5: riding time per stretch, entered by staff from the written-up route (an estimate, never computed). Additive.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('route_segments', fn (Blueprint $t) => $t->decimal('estimated_hours', 5, 2)->nullable());
        DB::statement('alter table route_segments add constraint route_segments_estimated_hours check (estimated_hours is null or (estimated_hours > 0 and estimated_hours <= 48))');
    }

    public function down(): void
    {
        DB::statement('alter table route_segments drop constraint if exists route_segments_estimated_hours');
        Schema::table('route_segments', fn (Blueprint $t) => $t->dropColumn('estimated_hours'));
    }
};
