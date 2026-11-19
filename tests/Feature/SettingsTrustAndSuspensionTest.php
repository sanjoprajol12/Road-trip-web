<?php

namespace Tests\Feature;

use App\Models\Contribution;
use App\Models\User;
use App\Support\MailSettings;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Mail, maps, security and trust settings; community corroboration; contribution-only suspensions. */
class SettingsTrustAndSuspensionTest extends TestCase
{
    use RefreshDatabase;

    private function as(?\Illuminate\Contracts\Auth\Authenticatable $u)
    {
        $this->app['auth']->forgetGuards();

        return $u ? $this->actingAs($u, 'sanctum') : $this;
    }

    private function published(User $author): Contribution
    {
        $c = new Contribution(['kind' => 'observation', 'title' => 'Bridge open again', 'body' => 'Both lanes open on the day, light traffic in the morning.', 'observed_on' => now()->subDay()->toDateString()]);
        $c->author_id = $author->id;
        $c->save();
        $c->forceFill(['status' => 'published'])->save();

        return $c;
    }

    public function test_mail_settings_keep_the_password_secret_and_apply_at_runtime(): void
    {
        $admin = $this->staff('admin');
        $mail = ['override' => true, 'host' => 'smtp.example.test', 'port' => 2525, 'encryption' => 'tls', 'username' => 'mailer', 'password' => 'not-a-real-secret-123', 'from_address' => 'noreply@example.test', 'from_name' => 'My Ride Nepal'];
        $this->as($admin)->putJson('/api/v1/admin/settings/mail', ['host' => 'bad host!'] + $mail)->assertStatus(422)->assertJsonValidationErrors('host');
        $this->as($admin)->putJson('/api/v1/admin/settings/mail', $mail)->assertOk()->assertJsonPath('data.password', ['set' => true])->assertJsonMissing(['password' => 'not-a-real-secret-123']);
        $row = DB::table('settings')->where('key', 'mail.password')->first();
        $this->assertNull($row->value);
        $this->assertStringNotContainsString('not-a-real-secret', (string) $row->secret);
        $this->assertStringNotContainsString('not-a-real-secret', (string) DB::table('audit_logs')->where('action', 'settings.mail.updated')->value('new_data'));
        $this->as(null)->getJson('/api/v1/site')->assertOk()->assertJsonMissingPath('data.mail');

        MailSettings::apply();
        $this->assertSame('smtp.example.test', config('mail.mailers.smtp.host'));
        $this->assertSame('not-a-real-secret-123', config('mail.mailers.smtp.password'));
        $this->assertSame('noreply@example.test', config('mail.from.address'));

        // Test email goes only to the signed-in staff member, whatever address the request names.
        $this->as($admin)->putJson('/api/v1/admin/settings/mail', ['override' => false] + $mail)->assertOk();
        config(['mail.default' => 'array']);
        $this->as($admin)->postJson('/api/v1/admin/settings/mail/test-email', ['to' => 'someone@else.test'])->assertOk();
        $sent = app('mail.manager')->mailer('array')->getSymfonyTransport()->messages();
        $this->assertCount(1, $sent);
        $this->assertSame([$admin->email], array_map(fn ($a) => $a->getAddress(), $sent[0]->getOriginalMessage()->getTo()));
        $this->as($admin)->postJson('/api/v1/admin/settings/mail/test-connection')->assertStatus(502)->assertJsonPath('ok', false); // array mailer: no SMTP
        $this->assertDatabaseHas('audit_logs', ['action' => 'settings.mail.tested']);
        $this->as($this->staff('moderator'))->postJson('/api/v1/admin/settings/mail/test-connection')->assertForbidden();
    }

    public function test_map_settings_validate_urls_and_never_reach_private_hosts(): void
    {
        $admin = $this->staff('admin');
        $base = Settings::group('maps');
        unset($base['geocoding_key'], $base['routing_key']);
        $this->as($admin)->putJson('/api/v1/admin/settings/maps', ['tile_url' => 'http://tiles.example/{z}/{x}/{y}.png'] + $base)->assertStatus(422)->assertJsonValidationErrors('tile_url');
        $this->as($admin)->putJson('/api/v1/admin/settings/maps', ['tile_url' => 'https://tiles.example/static.png'] + $base)->assertStatus(422);
        $this->as($admin)->putJson('/api/v1/admin/settings/maps', ['default_zoom' => 8] + $base)->assertOk();
        $this->as(null)->getJson('/api/v1/site')->assertJsonPath('data.maps.default_zoom', 8)->assertJsonPath('data.maps.tile_url', 'https://tile.openstreetmap.org/{z}/{x}/{y}.png');

        $this->as($admin)->postJson('/api/v1/admin/settings/maps/test', ['which' => 'routing'])->assertOk()->assertJsonPath('ok', false)->assertJsonPath('message', 'No provider is configured.');
        $this->as($admin)->putJson('/api/v1/admin/settings/maps', ['routing_provider' => 'osrm'] + $base)->assertStatus(422)->assertJsonValidationErrors('routing_url');
        $this->as($admin)->putJson('/api/v1/admin/settings/maps', ['routing_provider' => 'osrm', 'routing_url' => 'https://127.0.0.1:5000'] + $base)->assertOk();
        $this->as($admin)->postJson('/api/v1/admin/settings/maps/test', ['which' => 'routing'])->assertOk()
            ->assertJsonPath('ok', false)->assertJsonPath('message', 'The provider address must be a public internet host.');
    }

    public function test_login_attempt_limit_is_a_setting(): void
    {
        $this->as($this->staff('admin'))->putJson('/api/v1/admin/settings/security', ['staff_idle_minutes' => 30, 'staff_max_hours' => 8, 'login_attempts_per_minute' => 3])->assertOk();
        $rider = $this->rider();
        $this->app['auth']->forgetGuards();
        foreach (range(1, 3) as $_) {
            $this->postJson('/api/v1/auth/login', ['email' => $rider->email, 'password' => 'wrong-password'])->assertStatus(422);
        }
        $this->postJson('/api/v1/auth/login', ['email' => $rider->email, 'password' => 'wrong-password'])->assertStatus(429);
    }

    public function test_community_corroboration_uses_the_threshold_and_eligible_riders_only(): void
    {
        $this->as($this->staff('admin'))->putJson('/api/v1/admin/settings/trust', ['corroboration_threshold' => 2, 'corroboration_window_days' => 30])->assertOk();
        $c = $this->published($this->rider());
        $paused = $this->rider();
        $this->as($paused)->postJson("/api/v1/contributions/{$c->id}/confirmations")->assertOk();
        // A rider paused afterwards no longer counts towards corroboration.
        DB::table('contribution_suspensions')->insert(['id' => (string) \Illuminate\Support\Str::uuid(), 'user_id' => $paused->id, 'until' => now()->addWeek(), 'reason' => 'Test pause reason', 'created_at' => now(), 'updated_at' => now()]);
        $this->as($this->rider())->postJson("/api/v1/contributions/{$c->id}/confirmations")->assertOk();
        $this->assertSame('published', $c->refresh()->status->value);
        $this->as($this->rider())->postJson("/api/v1/contributions/{$c->id}/confirmations")->assertOk();
        $this->assertSame('community_corroborated', $c->refresh()->status->value);
        $this->assertDatabaseHas('audit_logs', ['action' => 'contribution.community_corroborated', 'subject_id' => $c->id]);

        // Officially sourced reports are never downgraded to "corroborated".
        $o = $this->published($this->rider());
        $o->forceFill(['status' => 'officially_sourced'])->save();
        $this->as($this->rider())->postJson("/api/v1/contributions/{$o->id}/confirmations")->assertOk();
        $this->as($this->rider())->postJson("/api/v1/contributions/{$o->id}/confirmations")->assertOk();
        $this->assertSame('officially_sourced', $o->refresh()->status->value);
    }

    public function test_contribution_suspension_is_separate_appealable_and_audited(): void
    {
        $rider = $this->rider();
        $mod = $this->staff('moderator');
        $c = $this->published($this->rider());
        $this->as($mod)->postJson("/api/v1/admin/users/{$rider->id}/contribution-suspension", ['until' => now()->addDays(14)->toIso8601String(), 'reason' => 'short'])->assertStatus(422);
        $this->as($mod)->postJson("/api/v1/admin/users/{$rider->id}/contribution-suspension", ['until' => now()->addDays(14)->toIso8601String(), 'reason' => 'Three misleading road reports in one week.'])->assertCreated();
        $this->assertDatabaseHas('audit_logs', ['action' => 'user.contributions_suspended', 'subject_id' => $rider->id]);
        $this->assertSame(1, $rider->notifications()->count());

        // Contributions refused; the rest of the account works.
        $this->as($rider)->postJson("/api/v1/contributions/{$c->id}/reports", ['reason' => 'This is a test report text.'])->assertForbidden()->assertJsonPath('contribution_suspension.reason', 'Three misleading road reports in one week.');
        $this->as($rider)->postJson('/api/v1/trips', ['title' => 'Still planning'])->assertCreated();
        $this->as($rider)->getJson('/api/v1/users/me')->assertJsonPath('data.contribution_suspension.reason', 'Three misleading road reports in one week.')->assertJsonPath('data.suspended_until', null);

        $this->as($mod)->postJson("/api/v1/admin/users/{$rider->id}/contribution-suspension/review", ['decision' => 'upheld', 'note' => 'There is no appeal yet.'])->assertStatus(422);
        $this->as($rider)->postJson('/api/v1/users/me/contribution-appeal', ['appeal' => 'The reports were accurate; I have photos from that day.'])->assertOk();
        $this->as($rider)->postJson('/api/v1/users/me/contribution-appeal', ['appeal' => 'Second appeal text that is long enough.'])->assertStatus(422);
        $row = collect($this->as($mod)->getJson('/api/v1/admin/users?q='.$rider->display_name)->json('data'))->firstWhere('id', $rider->id);
        $this->assertSame('The reports were accurate; I have photos from that day.', $row['contribution_suspension']['appeal']);

        $this->as($mod)->postJson("/api/v1/admin/users/{$rider->id}/contribution-suspension/review", ['decision' => 'lifted', 'note' => 'Photos confirm the reports.'])->assertOk();
        $this->assertDatabaseHas('audit_logs', ['action' => 'user.contributions_lifted', 'subject_id' => $rider->id]);
        $this->as($rider)->postJson("/api/v1/contributions/{$c->id}/reports", ['reason' => 'This is a test report text.'])->assertStatus(202);

        // Staff cannot pause their own linked rider account; riders cannot reach the staff endpoint.
        $self = $this->rider();
        $this->as($this->staff('moderator', $self))->postJson("/api/v1/admin/users/{$self->id}/contribution-suspension", ['until' => now()->addDay()->toIso8601String(), 'reason' => 'Trying to pause myself.'])->assertStatus(422);
        $this->as($rider)->postJson("/api/v1/admin/users/{$self->id}/contribution-suspension", [])->assertUnauthorized();
    }
}
