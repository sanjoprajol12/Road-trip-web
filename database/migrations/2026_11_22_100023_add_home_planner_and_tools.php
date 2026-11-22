<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 * Session 21, homepage: two section types that show the product (the working route planner and the tools riders
 * have), inserted after the hero. The hero gets a specific headline only while it is still the seeded copy that no
 * staff member has edited; anything staff changed is left alone. All of it stays editable in the staff portal.
 */
return new class extends Migration
{
    private const OLD_TYPES = ['hero', 'slides', 'layers', 'featured_destinations', 'featured_routes', 'featured_experiences', 'freshness', 'banner', 'text'];

    private const OLD_HERO = ['Plan better.', 'Ride prepared.', 'Learn from real journeys.'];

    private function hero(): array
    {
        return [
            'eyebrow' => 'Motorcycle trip planning for Nepal',
            'lines' => ['Plan the ride', 'stretch by stretch.'],
            'lead' => 'Pick where you start and where you are going. See the recorded routes between them, each stretch with its distance, riding-time estimate, surface and fuel stops, then add it to a trip you plan with your group. Every report is dated; nothing here is live.',
            'primary_cta' => ['label' => 'Plan a route', 'to' => '/journey'],
            'secondary_cta' => ['label' => 'Browse routes', 'to' => '/routes'],
        ];
    }

    private function sections(): array
    {
        return [
            'planner' => [
                'heading' => 'Start with where you are going',
                'intro' => 'Choose two places. You get the routes recorded between them and what lies along each one: fuel, repairs, places to stay and places worth the stop.',
                'ids' => [],
            ],
            'tools' => [
                'heading' => 'Everything the ride needs, in one plan',
                'intro' => 'Each tool works from what staff and riders have recorded, with the date it was seen. Gaps are shown as gaps.',
                'items' => [
                    ['name' => 'Routes, stretch by stretch', 'text' => 'Distance, riding-time estimates, surface, services and the longest run without a recorded fuel station, in either direction.', 'cta' => ['label' => 'Browse routes', 'to' => '/routes']],
                    ['name' => 'Trips you plan together', 'text' => 'Add a whole route, single stretches or checkpoints. Days, overnight stops, planned and spent costs, a checklist and notes, shared with the riders you invite.', 'cta' => ['label' => 'Plan a trip', 'to' => '/rides']],
                    ['name' => 'Places worth the stop', 'text' => 'Viewpoints, temples, lakes and more on the map. Riders can suggest new ones; staff review them first.', 'cta' => ['label' => 'See places', 'to' => '/places']],
                    ['name' => 'Reports from riders', 'text' => 'Road reports tied to the exact stretch and the day it was ridden, reviewed before they appear and marked when they get old.', 'cta' => ['label' => 'Read reports', 'to' => '/experiences']],
                    ['name' => 'Organised rides', 'text' => 'Group rides hosted by verified organisers, with places, waitlists and updates.', 'cta' => ['label' => 'See events', 'to' => '/events']],
                    ['name' => 'Preparation', 'text' => 'Guides on permits, the motorcycle and riding safely, each with its sources.', 'cta' => ['label' => 'Get ready', 'to' => '/preparation']],
                ],
            ],
        ];
    }

    private function typeCheck(array $types): void
    {
        DB::statement('alter table home_sections drop constraint if exists home_sections_type');
        DB::statement("alter table home_sections add constraint home_sections_type check (type in ('".implode("','", $types)."'))");
    }

    public function up(): void
    {
        $this->typeCheck(array_keys(config('home.types')));
        if (DB::table('home_sections')->whereIn('type', ['planner', 'tools'])->exists()) {
            return; // already there (staff may have added them): change nothing
        }
        DB::transaction(function () {
            $now = now();
            $hero = DB::table('home_sections')->where('type', 'hero')->whereNull('archived_at')->orderBy('position')->first();
            $after = $hero ? $hero->position + 1 : 0;
            $afterPublished = $hero?->published_position !== null ? $hero->published_position + 1 : 0;
            DB::table('home_sections')->where('position', '>=', $after)->update(['position' => DB::raw('position + 2')]);
            DB::table('home_sections')->whereNotNull('published_position')->where('published_position', '>=', $afterPublished)->update(['published_position' => DB::raw('published_position + 2')]);
            $i = 0;
            foreach ($this->sections() as $type => $data) {
                $json = json_encode($data);
                DB::table('home_sections')->insert(['id' => (string) Str::uuid(), 'type' => $type, 'position' => $after + $i, 'is_visible' => true, 'draft_data' => $json,
                    'published_position' => $afterPublished + $i, 'published_visible' => true, 'published_data' => $json, 'published_at' => $now, 'created_at' => $now, 'updated_at' => $now]);
                $i++;
            }
            // Only the untouched seeded hero gets the new headline (never staff-edited copy).
            if ($hero && $hero->updated_by_staff_id === null && json_decode($hero->draft_data, true)['lines'] === self::OLD_HERO && json_decode((string) $hero->published_data, true)['lines'] === self::OLD_HERO) {
                $data = array_merge(json_decode($hero->draft_data, true), $this->hero());
                DB::table('home_sections')->where('id', $hero->id)->update(['draft_data' => json_encode($data), 'published_data' => json_encode($data), 'updated_at' => $now]);
            }
        });
    }

    public function down(): void
    {
        DB::transaction(function () {
            foreach (DB::table('home_sections')->whereIn('type', ['planner', 'tools'])->orderByDesc('position')->get() as $s) {
                DB::table('home_sections')->where('id', $s->id)->delete();
                DB::table('home_sections')->where('position', '>', $s->position)->update(['position' => DB::raw('position - 1')]);
                if ($s->published_position !== null) {
                    DB::table('home_sections')->whereNotNull('published_position')->where('published_position', '>', $s->published_position)->update(['published_position' => DB::raw('published_position - 1')]);
                }
            }
            $hero = DB::table('home_sections')->where('type', 'hero')->orderBy('position')->first();
            if ($hero && $hero->updated_by_staff_id === null && json_decode($hero->draft_data, true)['lines'] === $this->hero()['lines']) {
                $data = array_merge(json_decode($hero->draft_data, true), ['eyebrow' => 'Motorcycle travel in Nepal', 'lines' => self::OLD_HERO,
                    'lead' => 'Destinations, routes and road reports from riders who were there, each one dated, sourced and kept apart from what is merely old news.',
                    'primary_cta' => ['to' => '/explore', 'label' => 'Explore destinations'], 'secondary_cta' => ['to' => '/sign-up', 'label' => 'Create an account']]);
                DB::table('home_sections')->where('id', $hero->id)->update(['draft_data' => json_encode($data), 'published_data' => json_encode($data)]);
            }
        });
        $this->typeCheck(self::OLD_TYPES);
    }
};
