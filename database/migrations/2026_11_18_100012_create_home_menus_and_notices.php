<?php

use App\Support\Permissions;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Dynamic homepage, menus and notices (migration stage A8). Additive. The homepage and header menu that were written
 * in the public app's Vue files are inserted here as published content (the owner's existing copy, not invented),
 * so the website looks the same until staff change it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('home_sections', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('type', 40);
            // Draft state (what staff edit) and published state (what the public API serves).
            $table->unsignedSmallInteger('position');
            $table->boolean('is_visible')->default(true);
            $table->jsonb('draft_data');
            $table->unsignedSmallInteger('published_position')->nullable();
            $table->boolean('published_visible')->nullable();
            $table->jsonb('published_data')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamp('publish_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->foreignUuid('updated_by_staff_id')->nullable()->constrained('staff_accounts')->nullOnDelete();
            $table->timestamps();
        });
        DB::statement("alter table home_sections add constraint home_sections_type check (type in ('".implode("','", array_keys(config('home.types')))."'))");
        DB::statement('alter table home_sections add constraint home_sections_schedule check (expires_at is null or publish_at is null or expires_at > publish_at)');

        Schema::create('menu_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('location', 20);
            $table->string('label', 40);
            $table->string('target', 300);
            $table->unsignedSmallInteger('position');
            $table->boolean('signed_in_only')->default(false);
            $table->boolean('is_visible')->default(true);
            $table->timestamps();
            $table->index(['location', 'position']);
        });
        DB::statement("alter table menu_items add constraint menu_items_location check (location in ('header', 'footer'))");

        Schema::create('notices', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('body', 300);
            $table->string('level', 10)->default('info');
            $table->string('link_label', 30)->nullable();
            $table->string('link_target', 300)->nullable();
            $table->string('status', 10)->default('draft');
            $table->timestamp('publish_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->foreignUuid('updated_by_staff_id')->nullable()->constrained('staff_accounts')->nullOnDelete();
            $table->timestamps();
        });
        DB::statement("alter table notices add constraint notices_level check (level in ('info', 'warning'))");
        DB::statement("alter table notices add constraint notices_status check (status in ('draft', 'published', 'archived'))");
        DB::statement('alter table notices add constraint notices_schedule check (expires_at is null or publish_at is null or expires_at > publish_at)');

        Permissions::sync();

        $now = now();
        $sections = [
            ['hero', [
                'eyebrow' => 'Motorcycle travel in Nepal',
                'lines' => ['Plan better.', 'Ride prepared.', 'Learn from real journeys.'],
                'lead' => 'Destinations, routes and road reports from riders who were there, each one dated, sourced and kept apart from what is merely old news.',
                'primary_cta' => ['label' => 'Explore destinations', 'to' => '/explore'],
                'secondary_cta' => ['label' => 'Create an account', 'to' => '/sign-up'],
                'background' => ['kind' => 'bundled', 'ref' => '/images/photos/kathmandu-motorcycle'],
                'photo_credit' => 'Kathmandu. Photo: Igor Ovsyannykov, CC0',
            ]],
            ['layers', [
                'heading' => 'The road, broken into things you can check',
                'intro' => 'Not a feed. Every piece of rider knowledge is filed under the place and the stretch of road it describes, so you can see exactly what was reported, where, and when.',
                'items' => [
                    ['name' => 'Destination', 'text' => 'Where you are going: permits, seasons, fuel, repairs, connectivity and places to sleep.'],
                    ['name' => 'Route', 'text' => 'How riders get there, with distance, riding time and the stops they actually used.'],
                    ['name' => 'Segment', 'text' => 'Each stretch between checkpoints, reported on separately, so trouble on one stretch is not mistaken for the whole road.'],
                    ['name' => 'Observation', 'text' => 'One rider’s report from one stretch, with the day they rode it. Reports age in plain sight. None of it is live.'],
                ],
            ]],
            ['featured_destinations', ['heading' => 'Destinations', 'link_label' => 'All destinations', 'ids' => []]],
            ['freshness', [
                'heading' => 'Every observation carries its date',
                'text' => 'A road report from last monsoon is history, not a forecast. My Ride Nepal shows when something was seen, by whom, and how it was checked, then lets it age visibly.',
                'legend' => [
                    ['term' => 'Observed', 'detail' => 'The day the rider rode that stretch'],
                    ['term' => 'Submitted', 'detail' => 'When the report reached us'],
                    ['term' => 'Status', 'detail' => 'Pending, published, corroborated, officially sourced or outdated'],
                    ['term' => 'Contributor', 'detail' => 'Who reported it, and which claims about them are verified'],
                ],
                'caption' => 'What every rider report will show. Publication means a report passed review, not that the road is open today.',
            ]],
        ];
        foreach ($sections as $i => [$type, $data]) {
            $json = json_encode($data);
            DB::table('home_sections')->insert(['id' => (string) Str::uuid(), 'type' => $type, 'position' => $i, 'is_visible' => true, 'draft_data' => $json,
                'published_position' => $i, 'published_visible' => true, 'published_data' => $json, 'published_at' => $now, 'created_at' => $now, 'updated_at' => $now]);
        }
        $menu = [['Explore', '/explore', false], ['Routes', '/routes', false], ['Experiences', '/experiences', false], ['Events', '/events', false], ['Preparation', '/preparation', false], ['My rides', '/rides', true]];
        foreach ($menu as $i => [$label, $target, $private]) {
            DB::table('menu_items')->insert(['id' => (string) Str::uuid(), 'location' => 'header', 'label' => $label, 'target' => $target, 'position' => $i, 'signed_in_only' => $private, 'is_visible' => true, 'created_at' => $now, 'updated_at' => $now]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('notices');
        Schema::dropIfExists('menu_items');
        Schema::dropIfExists('home_sections');
    }
};
