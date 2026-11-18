<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A road report always belongs to its segment (contributions_road_report_segment). Deleting a segment
 * used to null the link and fail that rule; now the delete is refused while reports point at it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contributions', function (Blueprint $table) {
            $table->dropForeign(['route_segment_id']);
            $table->foreign('route_segment_id')->references('id')->on('route_segments')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('contributions', function (Blueprint $table) {
            $table->dropForeign(['route_segment_id']);
            $table->foreign('route_segment_id')->references('id')->on('route_segments')->nullOnDelete();
        });
    }
};
