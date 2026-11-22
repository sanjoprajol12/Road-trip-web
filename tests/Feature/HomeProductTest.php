<?php

namespace Tests\Feature;

use App\Models\Destination;
use App\Models\RideRoute;
use App\Support\HomeContent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/** Session 21: the homepage shows the product with real published data only (planner, tools). */
class HomeProductTest extends TestCase
{
    use RefreshDatabase;

    private function planner(): array
    {
        $this->app['auth']->forgetGuards();

        return collect($this->getJson('/api/v1/home')->assertOk()->json('data'))->firstWhere('type', 'planner');
    }

    public function test_the_planner_shows_no_example_route_until_one_with_stretches_is_published(): void
    {
        Destination::create(['slug' => 'pokhara', 'name' => 'Pokhara', 'status' => 'published']);
        Destination::create(['slug' => 'jomsom', 'name' => 'Jomsom', 'status' => 'published']);
        Destination::create(['slug' => 'secret', 'name' => 'Secret', 'status' => 'draft']);
        $p = $this->planner();
        $this->assertNull($p['route']);
        $this->assertSame([['slug' => 'jomsom', 'name' => 'Jomsom'], ['slug' => 'pokhara', 'name' => 'Pokhara']], $p['places']); // published only, by name

        // A published route without stretches and a draft route with stretches are both skipped.
        RideRoute::create(['slug' => 'empty', 'name' => 'Empty', 'status' => 'published']);
        RideRoute::create(['slug' => 'draft', 'name' => 'Draft', 'status' => 'draft'])->segments()->create(['position' => 1, 'start_name' => 'A', 'end_name' => 'B', 'surface' => 'paved']);
        $this->assertNull($this->planner()['route']);

        $real = RideRoute::create(['slug' => 'kali', 'name' => 'Kali Gandaki', 'status' => 'published']);
        $real->segments()->create(['position' => 1, 'start_name' => 'Beni', 'end_name' => 'Tatopani', 'surface' => 'mixed', 'distance_km' => 40, 'estimated_hours' => 2.5]);
        $p = $this->planner();
        $this->assertSame('kali', $p['route']['slug']);
        $this->assertSame(40, $p['route']['segments'][0]['distance_km']);

        // A chosen route that is later unpublished is not shown.
        $id = DB::table('home_sections')->where('type', 'planner')->value('id');
        DB::table('home_sections')->where('id', $id)->update(['published_data' => json_encode(['heading' => 'Plan', 'intro' => null, 'ids' => [$real->id]])]);
        $this->assertSame('kali', $this->planner()['route']['slug']);
        $real->update(['status' => 'archived']);
        $this->assertNull($this->planner()['route']);
    }

    public function test_new_section_content_is_validated(): void
    {
        $this->assertSame(['heading' => 'Plan', 'ids' => []], HomeContent::validate('planner', ['heading' => 'Plan', 'ids' => [], 'extra' => 'dropped']));
        foreach ([
            ['planner', ['heading' => 'Plan', 'ids' => ['00000000-0000-0000-0000-000000000000']]], // no such route
            ['planner', ['heading' => 'Plan', 'ids' => [(string) \Illuminate\Support\Str::uuid(), (string) \Illuminate\Support\Str::uuid()]]], // one route at most
            ['tools', ['heading' => 'Tools', 'items' => []]],
            ['tools', ['heading' => 'Tools', 'items' => [['name' => 'x', 'text' => 'y', 'cta' => ['label' => 'Go', 'to' => 'javascript:alert(1)']]]]],
        ] as [$type, $data]) {
            try {
                HomeContent::validate($type, $data);
                $this->fail("{$type} accepted invalid content");
            } catch (ValidationException) {
                $this->addToAssertionCount(1);
            }
        }
        $tools = collect($this->getJson('/api/v1/home')->json('data'))->firstWhere('type', 'tools');
        $this->assertCount(6, $tools['items']);
        $this->assertSame('/rides', $tools['items'][1]['cta']['to']);
    }
}
