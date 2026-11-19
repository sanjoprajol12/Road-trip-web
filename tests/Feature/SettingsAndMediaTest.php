<?php

namespace Tests\Feature;

use App\Models\Contribution;
use App\Models\MediaAsset;
use App\Models\User;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Stage 3: settings registry, maintenance and registration switches, media library and storage settings. */
class SettingsAndMediaTest extends TestCase
{
    use RefreshDatabase;

    private function as(?\Illuminate\Contracts\Auth\Authenticatable $u)
    {
        $this->app['auth']->forgetGuards();
        Settings::forget(); // each request reads settings fresh, like a new request in production

        return $u ? $this->actingAs($u, 'sanctum') : $this;
    }

    private function png(int $w = 400, int $h = 300): UploadedFile
    {
        $img = imagecreatetruecolor($w, $h);
        imagefill($img, 0, 0, imagecolorallocate($img, 30, 90, 160));
        $path = tempnam(sys_get_temp_dir(), 'png');
        imagepng($img, $path);

        return new UploadedFile($path, 'test.png', 'image/png', null, true);
    }

    private function withRole(array $permissions): \App\Models\StaffAccount
    {
        $slug = 'r'.substr(md5(implode(',', $permissions).microtime()), 0, 8);
        DB::table('roles')->insert(['slug' => $slug, 'name' => "Role {$slug}", 'created_at' => now(), 'updated_at' => now()]);
        DB::table('permission_role')->insert(array_map(fn ($p) => ['role_slug' => $slug, 'permission_key' => $p], $permissions));

        return $this->staff($slug);
    }

    public function test_settings_are_validated_saved_audited_and_public_ones_are_published(): void
    {
        $admin = $this->staff('admin');
        $this->as(null)->getJson('/api/v1/site')->assertOk()->assertJsonPath('data.general.site_name', 'My Ride Nepal')
            ->assertJsonPath('data.registration.open', true)->assertJsonMissingPath('data.storage');

        $this->as($admin)->putJson('/api/v1/admin/settings/general', ['site_name' => 'X'])->assertStatus(422)->assertJsonValidationErrors('site_name');
        $this->as($admin)->putJson('/api/v1/admin/settings/general', ['timezone' => 'Mars/Base'])->assertStatus(422)->assertJsonValidationErrors('timezone');
        $this->as($admin)->putJson('/api/v1/admin/settings/general', ['default_language' => 'fr'])->assertStatus(422);
        $this->as($admin)->putJson('/api/v1/admin/settings/general', ['made_up' => 'x'])->assertStatus(422)->assertJsonValidationErrors('settings');
        $this->as($admin)->putJson('/api/v1/admin/settings/general', ['site_name' => 'My Ride Nepal Test', 'contact_email' => 'hello@example.test'])
            ->assertOk()->assertJsonPath('data.site_name', 'My Ride Nepal Test')->assertJsonPath('data.tagline', config('settings.registry')['general.tagline']['default']);
        $this->as(null)->getJson('/api/v1/site')->assertJsonPath('data.general.site_name', 'My Ride Nepal Test')->assertJsonPath('data.general.contact_email', 'hello@example.test');
        $log = DB::table('audit_logs')->where('action', 'settings.general.updated')->sole();
        $this->assertSame('My Ride Nepal', json_decode($log->old_data, true)['general.site_name']);
        $this->as(null)->getJson('/api/v1/admin/settings/general')->assertUnauthorized();
    }

    public function test_secret_settings_are_encrypted_never_returned_and_masked_in_the_audit_log(): void
    {
        // A secret key, as the mail and storage groups will have (stage 9 / decision D1).
        // (Registry keys contain dots, so the whole array is replaced rather than set with config dot notation.)
        config(['settings.registry' => config('settings.registry') + ['general.test_secret' => ['type' => 'string', 'default' => null, 'rules' => ['nullable', 'string'], 'secret' => true, 'public' => true]]]);
        $admin = $this->staff('admin');
        $this->as($admin)->putJson('/api/v1/admin/settings/general', ['test_secret' => 'super-secret-value'])->assertOk()->assertJsonPath('data.test_secret', ['set' => true]);
        $raw = DB::table('settings')->where('key', 'general.test_secret')->sole();
        $this->assertNull($raw->value);
        $this->assertStringNotContainsString('super-secret-value', $raw->secret);
        $this->assertSame('super-secret-value', Settings::get('general.test_secret'));
        $this->assertStringNotContainsString('super-secret-value', $this->as(null)->getJson('/api/v1/site')->getContent(), 'public flag never exposes a secret');
        $this->assertStringNotContainsString('super-secret-value', DB::table('audit_logs')->pluck('new_data')->implode(' '));
        $this->as($admin)->putJson('/api/v1/admin/settings/general', ['site_name' => 'Unrelated change'])->assertOk()->assertJsonPath('data.test_secret', ['set' => true]); // left out: kept
        $this->as($admin)->putJson('/api/v1/admin/settings/general', ['test_secret' => ''])->assertOk()->assertJsonPath('data.test_secret', ['set' => false]); // empty: cleared
    }

    public function test_each_settings_group_needs_its_own_permission(): void
    {
        $general = $this->withRole(['settings.general.manage']);
        $this->as($general)->getJson('/api/v1/admin/settings/general')->assertOk();
        foreach (['maintenance', 'registration', 'storage'] as $g) {
            $this->as($general)->getJson("/api/v1/admin/settings/{$g}")->assertForbidden();
        }
        $this->as($this->staff('moderator'))->putJson('/api/v1/admin/settings/general', ['site_name' => 'Moderators cannot'])->assertForbidden();
        $this->as($general)->postJson('/api/v1/admin/cache/clear')->assertForbidden();
        $this->as($this->withRole(['system.cache.manage']))->postJson('/api/v1/admin/cache/clear')->assertOk();
    }

    public function test_maintenance_mode_closes_the_public_api_but_not_staff_or_sign_in(): void
    {
        $admin = $this->staff('admin');
        $rider = $this->rider();
        $this->as($admin)->putJson('/api/v1/admin/settings/maintenance', ['enabled' => true, 'message' => 'Back after the database upgrade tonight.'])->assertOk();

        $this->as(null)->getJson('/api/v1/destinations')->assertStatus(503)->assertJsonPath('message', 'Back after the database upgrade tonight.')->assertJsonPath('maintenance', true);
        $this->as($rider)->getJson('/api/v1/trips')->assertStatus(503);
        $this->as(null)->getJson('/api/v1/site')->assertOk()->assertJsonPath('data.maintenance.enabled', true);
        $this->as(null)->getJson('/api/v1/health')->assertOk();
        $this->as(null)->getJson('/api/v1/auth/session')->assertOk();
        $this->as(null)->postJson('/api/v1/auth/login', ['email' => $rider->email, 'password' => 'password-1234'])->assertOk(); // rider sign-in stays open
        $this->as(null)->postJson('/api/v1/admin/auth/login', ['email' => $admin->email, 'password' => self::STAFF_PASSWORD])->assertOk();
        $this->as($admin)->getJson('/api/v1/admin/overview')->assertOk();
        // Staff check the closed site with a signed preview token; a forged or expired one does not open it.
        $token = $this->as($admin)->postJson('/api/v1/admin/preview-token')->assertOk()->json('token');
        $this->as(null)->withHeader('X-Preview-Token', $token)->getJson('/api/v1/destinations')->assertOk();
        $this->as(null)->withHeader('X-Preview-Token', $token.'x')->getJson('/api/v1/destinations')->assertStatus(503);
        $this->travel(31)->minutes();
        $this->as(null)->withHeader('X-Preview-Token', $token)->getJson('/api/v1/destinations')->assertStatus(503);
        $this->travelBack();
        $this->as($rider)->postJson('/api/v1/admin/preview-token')->assertUnauthorized();

        $this->as($admin)->putJson('/api/v1/admin/settings/maintenance', ['enabled' => false])->assertOk();
        $this->as(null)->getJson('/api/v1/destinations')->assertOk();
    }

    public function test_closing_registration_refuses_sign_up_only(): void
    {
        $admin = $this->staff('admin');
        $this->as($admin)->putJson('/api/v1/admin/settings/registration', ['open' => false, 'closed_message' => 'Sign-up opens again in December.'])->assertOk();
        $this->as(null)->postJson('/api/v1/auth/register', ['display_name' => 'Late Rider', 'email' => 'late@example.test', 'password' => 'long-enough-1'])
            ->assertForbidden()->assertJsonPath('message', 'Sign-up opens again in December.');
        $this->assertDatabaseMissing('users', ['email' => 'late@example.test']);
        $this->as(null)->postJson('/api/v1/admin/auth/login', ['email' => $admin->email, 'password' => self::STAFF_PASSWORD])->assertOk();
        $rider = $this->rider();
        $this->as(null)->postJson('/api/v1/auth/login', ['email' => $rider->email, 'password' => 'password-1234'])->assertOk(); // existing riders still sign in
    }

    public function test_settings_are_cached_until_changed_or_cleared(): void
    {
        $admin = $this->staff('admin');
        $this->assertSame('My Ride Nepal', Settings::get('general.site_name')); // warms the cache
        DB::table('settings')->insert(['key' => 'general.site_name', 'value' => json_encode('Changed behind the cache'), 'updated_at' => now()]);
        $this->assertSame('My Ride Nepal', Settings::get('general.site_name'));
        $this->actingAs($admin, 'sanctum')->postJson('/api/v1/admin/cache/clear')->assertOk();
        $this->assertSame('Changed behind the cache', Settings::get('general.site_name'));
    }

    public function test_media_library_requires_licence_and_credit_and_protects_used_images(): void
    {
        Storage::fake('local');
        $mod = $this->staff('moderator'); // media.manage is a moderator default
        $base = ['alt' => 'Lakeside road at dusk', 'credit' => 'Owner photo', 'license' => 'owner_supplied'];
        $this->as($mod)->post('/api/v1/admin/media', ['file' => $this->png()] + ['license' => 'cc_by_nc'] + $base)->assertStatus(422)->assertJsonValidationErrors('license');
        $this->as($mod)->post('/api/v1/admin/media', ['file' => $this->png(), 'alt' => 'Lakeside', 'license' => 'cc0'])->assertStatus(422)->assertJsonValidationErrors(['credit']);
        $fake = UploadedFile::fake()->createWithContent('fake.png', '<html><script>alert(1)</script></html>');
        $this->as($mod)->post('/api/v1/admin/media', ['file' => $fake] + $base)->assertStatus(422)->assertJsonValidationErrors('file');
        $this->as($this->rider())->post('/api/v1/admin/media', ['file' => $this->png()] + $base)->assertUnauthorized();

        $m = $this->as($mod)->post('/api/v1/admin/media', ['file' => $this->png()] + $base)->assertCreated()->json('data');
        $this->assertSame('image/webp', $m['mime']);
        $asset = MediaAsset::findOrFail($m['id']);
        Storage::disk('local')->assertExists($asset->path);
        $this->as(null)->get($m['url'])->assertOk()->assertHeader('Content-Type', 'image/webp');
        $this->as($mod)->getJson('/api/v1/admin/media?q=lakeside')->assertJsonPath('meta.total', 1);
        $this->as($mod)->patchJson("/api/v1/admin/media/{$asset->id}", ['license' => 'public_domain'])->assertOk()->assertJsonPath('data.license', 'public_domain');

        DB::table('media_usages')->insert(['media_asset_id' => $asset->id, 'owner_type' => 'setting', 'owner_id' => 'branding.logo', 'field' => 'logo', 'created_at' => now(), 'updated_at' => now()]);
        $this->as($mod)->deleteJson("/api/v1/admin/media/{$asset->id}")->assertStatus(422)->assertJsonValidationErrors('media');
        DB::table('media_usages')->delete();
        $this->as($mod)->deleteJson("/api/v1/admin/media/{$asset->id}")->assertNoContent();
        Storage::disk('local')->assertMissing($asset->path);
        $this->assertDatabaseHas('audit_logs', ['action' => 'media.deleted', 'subject_id' => $asset->id]);
    }

    public function test_storage_settings_limit_uploads_and_the_storage_test_reports_usage(): void
    {
        Storage::fake('local');
        $admin = $this->staff('admin');
        $this->as($admin)->putJson('/api/v1/admin/settings/storage', ['driver' => 's3'])->assertStatus(422); // no provider until decision D1
        $this->as($admin)->putJson('/api/v1/admin/settings/storage', ['image_types' => ['image/gif']])->assertStatus(422);
        $this->as($admin)->putJson('/api/v1/admin/settings/storage', ['max_upload_kb' => 99999])->assertStatus(422);
        $this->as($admin)->putJson('/api/v1/admin/settings/storage', ['image_types' => ['image/jpeg']])->assertOk();

        $author = $this->rider();
        $c = new Contribution;
        $c->forceFill(['author_id' => $author->id, 'kind' => 'observation', 'title' => 'Storage limit check', 'body' => 'An observation written only for the automated test suite.',
            'observed_on' => now()->subDay()])->save();
        $this->as($author)->post("/api/v1/contributions/{$c->id}/media", ['photo' => $this->png(), 'alt' => 'A test photo'])->assertStatus(422)->assertJsonValidationErrors('photo');
        $this->as($admin)->putJson('/api/v1/admin/settings/storage', ['image_types' => ['image/jpeg', 'image/png', 'image/webp']])->assertOk();
        $this->as($author)->post("/api/v1/contributions/{$c->id}/media", ['photo' => $this->png(), 'alt' => 'A test photo'])->assertCreated();
        $row = DB::table('contribution_media')->sole();
        $this->assertSame('local', $row->disk);
        $this->assertGreaterThan(0, $row->size_bytes);

        $this->as($admin)->postJson('/api/v1/admin/settings/storage/test')->assertOk()->assertJsonPath('ok', true)->assertJsonPath('disk', 'local')
            ->assertJsonPath('usage.rider_photos.files', 1);
        $this->as($this->staff('moderator'))->postJson('/api/v1/admin/settings/storage/test')->assertForbidden();
    }
}
