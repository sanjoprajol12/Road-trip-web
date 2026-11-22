<?php

namespace Tests\Feature;

use App\Models\ContributionMedia;
use App\Models\MediaAsset;
use App\Models\RideRoute;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Session 24: production can never accept uploads that a replaced instance would lose (UPLOAD_SAFETY_DESIGN.md). */
class UploadPolicyTest extends TestCase
{
    use RefreshDatabase;

    private const PAUSED = 'Photo uploads are paused for now. Everything else works, and you can send your report without photos.';

    private function as(?\Illuminate\Contracts\Auth\Authenticatable $u)
    {
        $this->app['auth']->forgetGuards();
        Settings::forget();

        return $u ? $this->actingAs($u, 'sanctum') : $this;
    }

    private function env(string $name): void
    {
        $this->app->detectEnvironment(fn () => $name);
        // Laravel skips CSRF only in the "testing" environment; CSRF itself is verified live (session 22).
        $this->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class);
    }

    private function staffWith(array $keys): \App\Models\StaffAccount
    {
        $slug = 'r'.strtolower(\Illuminate\Support\Str::random(8));
        DB::table('roles')->insert(['slug' => $slug, 'name' => $slug, 'created_at' => now(), 'updated_at' => now()]);
        foreach ($keys as $k) {
            DB::table('permission_role')->insert(['role_slug' => $slug, 'permission_key' => $k]);
        }

        return $this->staff($slug);
    }

    /** A rider with a draft report they may add photos to. @return array{0: \App\Models\User, 1: string} */
    private function report(): array
    {
        $rider = $this->rider();
        $seg = RideRoute::create(['slug' => 'upload-road', 'name' => 'Upload road', 'status' => 'published'])
            ->segments()->create(['position' => 1, 'start_name' => 'A', 'end_name' => 'B', 'surface' => 'mixed'])->id;
        $id = $this->as($rider)->postJson('/api/v1/contributions', ['kind' => 'road_report', 'route_segment_id' => $seg, 'title' => 'Mud after rain near the bridge',
            'body' => 'Slow going for about two kilometres, passable with care on a 150cc bike.', 'observed_on' => now()->subDay()->toDateString(),
            'details' => ['surface' => 'dirt', 'motorcycle' => 'Test bike']])->assertCreated()->json('data.id');

        return [$rider, $id];
    }

    private function riderUpload(\App\Models\User $rider, string $id)
    {
        return $this->as($rider)->postJson("/api/v1/contributions/{$id}/media", ['photo' => UploadedFile::fake()->image('road.png', 400, 300), 'alt' => 'Muddy road near the bridge']);
    }

    private function staffUpload()
    {
        return $this->as($this->staff('admin'))->post('/api/v1/admin/media', ['file' => UploadedFile::fake()->image('lake.png', 400, 300), 'alt' => 'Lakeside road at dusk',
            'credit' => 'Owner photo', 'license' => 'owner_supplied'], ['Accept' => 'application/json']);
    }

    private function setting(string $key, mixed $value): void
    {
        DB::table('settings')->updateOrInsert(['key' => $key], ['value' => json_encode($value), 'updated_at' => now()]);
        Settings::forget();
    }

    private function assertNothingStored(string ...$disks): void
    {
        foreach ($disks as $d) {
            $this->assertSame([], Storage::disk($d)->allFiles(), "no file on {$d}");
        }
        $this->assertSame(0, ContributionMedia::count(), 'no rider photo row');
        $this->assertSame(0, MediaAsset::count(), 'no library row');
    }

    public function test_uploads_switched_off_by_environment_or_setting_are_refused_for_riders_and_staff(): void
    {
        Storage::fake('local');
        Log::spy();
        [$rider, $id] = $this->report();

        config(['mrn.uploads.enabled' => false]);
        $this->riderUpload($rider, $id)->assertStatus(422)->assertJsonValidationErrors(['photo' => self::PAUSED]);
        $this->staffUpload()->assertStatus(422)->assertJsonValidationErrors(['file' => self::PAUSED]);
        // The setting cannot turn uploads on against the environment.
        $this->setting('storage.uploads_enabled', true);
        $this->riderUpload($rider, $id)->assertStatus(422);

        config(['mrn.uploads.enabled' => true]);
        $this->setting('storage.uploads_enabled', false);
        $this->setting('storage.uploads_paused_message', 'Uploads are off during the storage move.');
        $this->riderUpload($rider, $id)->assertStatus(422)->assertJsonValidationErrors(['photo' => 'Uploads are off during the storage move.']);
        $this->staffUpload()->assertStatus(422)->assertJsonValidationErrors(['file' => 'Uploads are off during the storage move.']);

        $this->assertNothingStored('local');
        Log::shouldNotHaveReceived('critical'); // a deliberate pause is not an incident

        // Everything else keeps working: the report itself, without photos.
        $this->as($rider)->getJson("/api/v1/contributions/{$id}")->assertOk();
        $this->as(null)->getJson('/api/v1/site')->assertOk()->assertJsonPath('data.uploads', ['enabled' => false, 'message' => 'Uploads are off during the storage move.']);
        $this->as(null)->getJson('/api/v1/health')->assertOk()->assertJsonPath('status', 'ok')->assertJsonPath('uploads', 'paused');
    }

    public function test_production_refuses_the_local_disk_alerts_once_and_keeps_existing_images_viewable(): void
    {
        Storage::fake('local');
        [$rider, $id] = $this->report();
        // Uploaded earlier, while allowed (testing environment): these must stay viewable afterwards.
        $photo = $this->riderUpload($rider, $id)->assertCreated()->json('data.url');
        $asset = $this->staffUpload()->assertCreated()->json('data.id');
        $this->as($this->staff('moderator'))->postJson("/api/v1/admin/contributions/{$id}/decision", ['decision' => 'publish']);

        $this->env('production');
        Log::spy();
        $this->riderUpload($rider, $id)->assertStatus(422)->assertJsonValidationErrors(['photo' => self::PAUSED]);
        $this->staffUpload()->assertStatus(422)->assertJsonValidationErrors(['file' => self::PAUSED]);
        $this->riderUpload($rider, $id)->assertStatus(422);
        Log::shouldHaveReceived('critical')->once()->withArgs(function (string $message, array $context) {
            // Operational facts only: no file names, user data, paths or credentials.
            return str_contains($message, 'Uploads refused') && array_keys($context) === ['reason', 'disk', 'driver', 'environment']
                && $context['reason'] === 'storage_not_persistent' && $context['disk'] === 'local' && $context['environment'] === 'production';
        });
        $this->assertSame(1, ContributionMedia::count());
        $this->assertSame(1, MediaAsset::count());
        $this->assertCount(2, Storage::disk('local')->allFiles());

        $this->as($rider)->get($photo)->assertOk()->assertHeader('Content-Type', 'image/webp');
        $this->as(null)->get("/api/v1/files/{$asset}")->assertOk();
        $this->as(null)->getJson('/api/v1/site')->assertJsonPath('data.uploads.enabled', false);
    }

    public function test_the_local_disk_is_refused_in_every_environment_except_development_and_testing(): void
    {
        Storage::fake('local');
        [$rider, $id] = $this->report();
        foreach (['staging', 'production', 'preview'] as $env) { // owner decision O6: no override exists
            $this->env($env);
            $this->riderUpload($rider, $id)->assertStatus(422)->assertJsonValidationErrors(['photo' => self::PAUSED]);
        }
        $this->assertNothingStored('local');
    }

    public function test_production_writes_to_a_configured_durable_disk_and_never_falls_back_to_local(): void
    {
        Storage::fake('local');
        Storage::fake('s3');
        [$rider, $id] = $this->report();
        $this->env('production');
        $this->setting('storage.driver', 's3'); // as staff will set it once a provider exists (D1)

        // Chosen but not configured: refused and alerted, nothing written anywhere (no fallback to local).
        config(['filesystems.disks.s3.bucket' => null]);
        Log::spy();
        $this->riderUpload($rider, $id)->assertStatus(422);
        Log::shouldHaveReceived('critical')->once()->withArgs(fn ($m, $c) => $c['reason'] === 'storage_not_configured' && $c['disk'] === 's3');
        $this->assertNothingStored('local', 's3');

        config(['filesystems.disks.s3.bucket' => 'mrn-test', 'filesystems.disks.s3.key' => 'test-key', 'filesystems.disks.s3.secret' => 'test-secret']);
        $this->riderUpload($rider, $id)->assertCreated();
        $this->staffUpload()->assertCreated();
        $this->assertCount(2, Storage::disk('s3')->allFiles());
        $this->assertSame([], Storage::disk('local')->allFiles());
        $this->assertSame(['s3'], ContributionMedia::pluck('disk')->unique()->values()->all());
        $this->assertSame(['s3'], MediaAsset::pluck('disk')->unique()->values()->all());
        $this->as(null)->getJson('/api/v1/health')->assertJsonPath('uploads', 'enabled');
    }

    public function test_development_and_testing_use_the_local_disk(): void
    {
        Storage::fake('local');
        [$rider, $id] = $this->report();
        foreach (['local', 'testing'] as $env) {
            $this->env($env);
            $this->riderUpload($rider, $id)->assertCreated();
        }
        $this->assertCount(2, Storage::disk('local')->allFiles());
        $this->as(null)->getJson('/api/v1/site')->assertJsonPath('data.uploads', ['enabled' => true, 'message' => null]);
    }

    public function test_failures_leave_neither_orphaned_rows_nor_orphaned_files(): void
    {
        Storage::fake('local');
        [$rider, $id] = $this->report();

        // The database step fails after the file was written: the file is removed again.
        ContributionMedia::creating(fn () => throw new \RuntimeException('database down'));
        $this->riderUpload($rider, $id)->assertStatus(500);
        MediaAsset::creating(fn () => throw new \RuntimeException('database down'));
        $this->staffUpload()->assertStatus(500);
        $this->assertNothingStored('local');
        ContributionMedia::flushEventListeners();
        MediaAsset::flushEventListeners();

        // The storage write fails: a clear message, an alert, and no row.
        Log::spy();
        $failing = \Mockery::mock(\Illuminate\Contracts\Filesystem\Filesystem::class);
        $failing->shouldReceive('put')->andReturnFalse();
        $failing->shouldReceive('delete')->andReturnTrue();
        Storage::set('local', $failing);
        $this->riderUpload($rider, $id)->assertStatus(422)->assertJsonValidationErrors(['photo' => 'The image could not be stored right now. Try again later.']);
        Log::shouldHaveReceived('critical')->once()->withArgs(fn ($m, $c) => $c['reason'] === 'storage_write_failed');
        $this->assertSame(0, ContributionMedia::count());
    }

    public function test_the_policy_never_lets_anyone_past_authorization_and_validation_still_applies(): void
    {
        Storage::fake('local');
        [$rider, $id] = $this->report();
        // Allowed uploads: someone else's report, guests and riders on the staff library are refused as before.
        $this->riderUpload($this->rider(), $id)->assertForbidden();
        $this->as(null)->postJson("/api/v1/contributions/{$id}/media", ['photo' => UploadedFile::fake()->image('x.png', 400, 300), 'alt' => 'Guest photo try'])->assertUnauthorized();
        $this->as($rider)->post('/api/v1/admin/media', ['file' => UploadedFile::fake()->image('x.png', 400, 300)], ['Accept' => 'application/json'])->assertUnauthorized();
        $viewer = $this->staffWith(['overview.view']);
        $this->as($viewer)->post('/api/v1/admin/media', ['file' => UploadedFile::fake()->image('x.png', 400, 300)], ['Accept' => 'application/json'])->assertForbidden();
        // Validation is unchanged: not an image, too small.
        $this->as($rider)->postJson("/api/v1/contributions/{$id}/media", ['photo' => UploadedFile::fake()->createWithContent('x.jpg', 'not an image'), 'alt' => 'Not really a photo'])->assertStatus(422);
        $this->as($rider)->postJson("/api/v1/contributions/{$id}/media", ['photo' => UploadedFile::fake()->image('x.png', 100, 100), 'alt' => 'Far too small'])->assertStatus(422)->assertJsonValidationErrors('photo');
        $this->assertNothingStored('local');

        // Staff reason details are behind the storage permission.
        $this->as($viewer)->getJson('/api/v1/admin/settings/storage/upload-status')->assertForbidden();
        $this->as($this->staff('admin'))->getJson('/api/v1/admin/settings/storage/upload-status')->assertOk()
            ->assertJsonPath('data.allowed', true)->assertJsonPath('data.disk', 'local')->assertJsonPath('data.environment', 'testing');
        $this->env('production');
        $this->as($this->staff('admin'))->getJson('/api/v1/admin/settings/storage/upload-status')->assertJsonPath('data.reason', 'storage_not_persistent');
        $this->as(null)->getJson('/api/v1/site')->assertJsonMissingPath('data.uploads.reason');
    }
}
