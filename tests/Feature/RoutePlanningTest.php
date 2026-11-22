<?php

namespace Tests\Feature;

use App\Models\Destination;
use App\Models\RideRoute;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Session 18: route discovery filters (B13) and per-stretch riding time (B5). */
class RoutePlanningTest extends TestCase
{
    use RefreshDatabase;

    private function route(string $slug, ?float $km, array $surfaces, ?string $province = null, bool $track = false): RideRoute
    {
        $from = $province ? Destination::create(['slug' => "from-{$slug}", 'name' => "From {$slug}", 'status' => 'published', 'province' => $province]) : null;
        $r = RideRoute::create(['slug' => $slug, 'name' => "Road {$slug}", 'status' => 'published', 'distance_km' => $km, 'start_destination_id' => $from?->id]);
        foreach ($surfaces as $i => $surface) {
            $seg = $r->segments()->create(['position' => $i + 1, 'start_name' => "P{$i}", 'end_name' => 'P'.($i + 1), 'surface' => $surface]);
            if ($track && $i === 0) {
                $seg->forceFill(['path' => [[28.0, 84.0], [28.1, 84.1]]])->save(); // only the staff import sets a track
            }
        }

        return $r;
    }

    public function test_route_filters(): void
    {
        $this->route('smooth', 120, ['paved', 'paved'], 'Gandaki', true);
        $this->route('rough', 80, ['paved', 'gravel'], 'Karnali');
        $this->route('unknown-length', null, ['dirt']);
        RideRoute::create(['slug' => 'draft', 'name' => 'Road draft', 'status' => 'draft', 'distance_km' => 10]);
        $slugs = fn (string $q) => collect($this->getJson("/api/v1/routes{$q}")->assertOk()->json('data'))->pluck('slug')->sort()->values()->all();

        $this->assertSame(['rough', 'smooth', 'unknown-length'], $slugs(''));
        $this->assertSame(['smooth'], $slugs('?surface=paved_only'));
        $this->assertSame(['rough', 'unknown-length'], $slugs('?surface=rough'));
        $this->assertSame(['rough'], $slugs('?max_km=100')); // unknown length is not "short"
        $this->assertSame(['rough'], $slugs('?province=Karnali'));
        $this->assertSame(['smooth'], $slugs('?has_track=1'));
        $this->assertSame(['smooth'], $slugs('?q=smooth'));
        $this->getJson('/api/v1/routes?surface=fast')->assertStatus(422);
        $this->getJson('/api/v1/routes?max_km=-5')->assertStatus(422);
    }

    public function test_staff_record_riding_time_per_stretch(): void
    {
        $r = $this->route('timed', 50, ['paved']);
        $seg = $r->segments()->first();
        $base = ['id' => $seg->id, 'start_name' => 'P0', 'end_name' => 'P1', 'surface' => 'paved', 'distance_km' => 50];
        $this->actingAs($this->staff('admin'), 'sanctum')->putJson("/api/v1/admin/routes/{$r->id}/segments", ['segments' => [$base + ['estimated_hours' => 60]]])
            ->assertStatus(422)->assertJsonValidationErrors('segments.0.estimated_hours');
        $this->actingAs($this->staff('admin'), 'sanctum')->putJson("/api/v1/admin/routes/{$r->id}/segments", ['segments' => [$base + ['estimated_hours' => 1.5]]])->assertOk();
        $this->getJson('/api/v1/routes/timed')->assertJsonPath('data.segments.0.estimated_hours', 1.5);
    }
}
