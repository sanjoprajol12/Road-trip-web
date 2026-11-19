<?php

namespace Tests\Feature;

use App\Models\Destination;
use App\Support\PreviewToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Stage A8: the homepage, menus and notices are managed in the staff portal; the public API shows published content only. */
class CmsTest extends TestCase
{
    use RefreshDatabase;

    private function as($u)
    {
        $this->app['auth']->forgetGuards();

        return $u ? $this->actingAs($u) : $this;
    }

    private function section(string $type): string
    {
        return DB::table('home_sections')->where('type', $type)->value('id');
    }

    public function test_the_seeded_homepage_is_the_existing_copy(): void
    {
        $home = $this->as(null)->getJson('/api/v1/home')->assertOk()->assertJsonPath('preview', false)->json('data');
        $this->assertSame(['hero', 'layers', 'featured_destinations', 'freshness'], array_column($home, 'type'));
        $this->assertSame(['Plan better.', 'Ride prepared.', 'Learn from real journeys.'], $home[0]['lines']);
        $this->assertSame('/images/photos/kathmandu-motorcycle', $home[0]['background']['base']);
        $this->assertSame('Every observation carries its date', $home[3]['heading']);
        $this->as(null)->getJson('/api/v1/menus/header')->assertOk()->assertJsonCount(6, 'data')->assertJsonPath('data.5.signed_in_only', true);
        $this->as(null)->getJson('/api/v1/menus/sidebar')->assertNotFound();
    }

    public function test_drafts_stay_private_until_published_and_publishing_is_audited(): void
    {
        $editor = $this->staff('admin');
        $hero = $this->section('hero');
        $data = DB::table('home_sections')->where('id', $hero)->value('draft_data');
        $data = json_decode($data, true);
        $data['lines'] = ['Ride the hills.'];
        $data['made_up_field'] = '<script>alert(1)</script>';
        $data['lead'] = '<b>Bold?</b> plain text only';
        $this->as($editor)->patchJson("/api/v1/admin/cms/home/sections/{$hero}", ['data' => $data])->assertOk()
            ->assertJsonPath('data.changed', true)->assertJsonMissingPath('data.data.made_up_field');
        // Not published yet: the public homepage is unchanged; a preview token shows the draft.
        $this->as(null)->getJson('/api/v1/home')->assertJsonPath('data.0.lines', ['Plan better.', 'Ride prepared.', 'Learn from real journeys.']);
        $token = PreviewToken::issue($editor->id);
        $this->as(null)->withHeader('X-Preview-Token', $token)->getJson('/api/v1/home')->assertJsonPath('preview', true)->assertJsonPath('data.0.lines', ['Ride the hills.']);
        $this->as(null)->withHeader('X-Preview-Token', $token.'x')->getJson('/api/v1/home')->assertJsonPath('preview', false);

        $this->as($editor)->postJson('/api/v1/admin/cms/home/publish')->assertOk();
        $home = $this->as(null)->getJson('/api/v1/home')->json('data');
        $this->assertSame(['Ride the hills.'], $home[0]['lines']);
        $this->assertSame('<b>Bold?</b> plain text only', $home[0]['lead'], 'stored as text; the website escapes it');
        $this->assertDatabaseHas('audit_logs', ['action' => 'home.published', 'actor_type' => 'staff']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'home.section_edited', 'subject_id' => $hero]);
    }

    public function test_section_data_is_validated(): void
    {
        $editor = $this->staff('admin');
        $hero = $this->section('hero');
        $base = json_decode(DB::table('home_sections')->where('id', $hero)->value('draft_data'), true);
        $bad = [
            ['lines' => []],
            ['lines' => ['x', 'y', 'z', 'w']],
            ['primary_cta' => ['label' => 'Go', 'to' => 'javascript:alert(1)']],
            ['primary_cta' => ['label' => 'Go', 'to' => 'http://insecure.example']],
            ['background' => ['kind' => 'bundled', 'ref' => '/images/photos/not-shipped']],
            ['background' => ['kind' => 'media', 'ref' => (string) \Illuminate\Support\Str::uuid()]],
        ];
        foreach ($bad as $over) {
            $this->as($editor)->patchJson("/api/v1/admin/cms/home/sections/{$hero}", ['data' => array_merge($base, $over)])->assertStatus(422);
        }
        $feat = $this->section('featured_destinations');
        $this->as($editor)->patchJson("/api/v1/admin/cms/home/sections/{$feat}", ['data' => ['heading' => 'Picks', 'ids' => [(string) \Illuminate\Support\Str::uuid()]]])->assertStatus(422);
        $this->as($editor)->postJson('/api/v1/admin/cms/home/sections', ['type' => 'carousel_of_doom'])->assertStatus(422);
    }

    public function test_featured_items_show_only_published_records_in_the_chosen_order(): void
    {
        $editor = $this->staff('admin');
        $a = Destination::forceCreate(['slug' => 'alpha', 'name' => 'Alpha', 'province' => 'Gandaki', 'status' => 'published']);
        $b = Destination::forceCreate(['slug' => 'bravo', 'name' => 'Bravo', 'province' => 'Gandaki', 'status' => 'published']);
        $draft = Destination::forceCreate(['slug' => 'charlie', 'name' => 'Charlie', 'province' => 'Gandaki', 'status' => 'draft']);
        $feat = $this->section('featured_destinations');
        $this->as($editor)->patchJson("/api/v1/admin/cms/home/sections/{$feat}", ['data' => ['heading' => 'Picks', 'link_label' => 'All', 'ids' => [$b->id, $draft->id, $a->id]]])->assertOk();
        $this->as($editor)->postJson('/api/v1/admin/cms/home/publish')->assertOk();
        $items = collect($this->as(null)->getJson('/api/v1/home')->json('data'))->firstWhere('type', 'featured_destinations')['items'];
        $this->assertSame(['bravo', 'alpha'], array_column($items, 'slug'));
    }

    public function test_order_visibility_schedule_and_archive(): void
    {
        $editor = $this->staff('admin');
        $ids = DB::table('home_sections')->orderBy('position')->pluck('id')->all();
        $this->as($editor)->putJson('/api/v1/admin/cms/home/order', ['ids' => array_reverse($ids)])->assertNoContent();
        $this->as($editor)->patchJson("/api/v1/admin/cms/home/sections/{$ids[1]}", ['is_visible' => false])->assertOk();
        $this->as($editor)->patchJson("/api/v1/admin/cms/home/sections/{$ids[2]}", ['publish_at' => now()->addDay()->toIso8601String()])->assertOk();
        $this->as($editor)->postJson("/api/v1/admin/cms/home/sections/{$ids[3]}/archive")->assertOk()->assertJsonPath('data.archived', true);
        $this->as($editor)->postJson('/api/v1/admin/cms/home/publish')->assertOk();
        $this->assertSame(['hero'], array_column($this->as(null)->getJson('/api/v1/home')->json('data'), 'type'));
        $this->travel(2)->days();
        $this->assertSame(['featured_destinations', 'hero'], array_column($this->as(null)->getJson('/api/v1/home')->json('data'), 'type'));
        $this->travelBack();
        $this->as($editor)->postJson("/api/v1/admin/cms/home/sections/{$ids[3]}/restore")->assertOk()->assertJsonPath('data.archived', false);
        $new = $this->as($editor)->postJson('/api/v1/admin/cms/home/sections', ['type' => 'text'])->assertCreated()->json('data');
        $this->assertFalse($new['is_visible'], 'new sections start hidden');
    }

    public function test_menus_and_notices(): void
    {
        $editor = $this->staff('admin');
        $this->as($editor)->putJson('/api/v1/admin/cms/menus/footer', ['items' => [['label' => 'Bad', 'target' => 'javascript:x']]])->assertStatus(422);
        $this->as($editor)->putJson('/api/v1/admin/cms/menus/footer', ['items' => [['label' => 'Explore', 'target' => '/explore'], ['label' => 'Hidden', 'target' => '/routes', 'is_visible' => false]]])->assertOk();
        $this->as(null)->getJson('/api/v1/menus/footer')->assertJsonCount(1, 'data')->assertJsonPath('data.0.to', '/explore');

        $draft = $this->as($editor)->postJson('/api/v1/admin/cms/notices', ['body' => 'New guides are being reviewed this week.', 'level' => 'info', 'status' => 'draft'])->assertCreated()->json('data.id');
        $this->as(null)->getJson('/api/v1/notices/active')->assertJsonCount(0, 'data');
        $this->as($editor)->putJson("/api/v1/admin/cms/notices/{$draft}", ['body' => 'New guides are being reviewed this week.', 'level' => 'info', 'status' => 'published', 'expires_at' => now()->addHour()->toIso8601String()])->assertOk();
        $this->as(null)->getJson('/api/v1/notices/active')->assertJsonCount(1, 'data');
        $this->travel(2)->hours();
        $this->as(null)->getJson('/api/v1/notices/active')->assertJsonCount(0, 'data');
        $this->assertDatabaseHas('audit_logs', ['action' => 'notice.published']);
    }

    public function test_each_cms_area_needs_its_own_permission(): void
    {
        $mod = $this->staff('moderator'); // no CMS permissions by default
        $hero = $this->section('hero');
        $this->as($mod)->getJson('/api/v1/admin/cms/home/sections')->assertForbidden();
        $this->as($mod)->postJson('/api/v1/admin/cms/home/publish')->assertForbidden();
        $this->as($mod)->putJson('/api/v1/admin/cms/menus/header', ['items' => []])->assertForbidden();
        $this->as($mod)->getJson('/api/v1/admin/cms/notices')->assertForbidden();
        $this->as($this->rider())->patchJson("/api/v1/admin/cms/home/sections/{$hero}", ['is_visible' => false])->assertUnauthorized();
        // An editor without publish rights can change drafts but not publish them.
        DB::table('roles')->insert(['slug' => 'editor', 'name' => 'Editor', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('permission_role')->insert(['role_slug' => 'editor', 'permission_key' => 'cms.home.edit']);
        $editor = $this->staff('editor');
        $this->as($editor)->patchJson("/api/v1/admin/cms/home/sections/{$hero}", ['is_visible' => false])->assertOk();
        $this->as($editor)->postJson('/api/v1/admin/cms/home/publish')->assertForbidden();
        $this->assertSame('hero', $this->as(null)->getJson('/api/v1/home')->json('data.0.type'));
    }

    public function test_branding_social_seo_and_footer_settings_are_public(): void
    {
        $admin = $this->staff('admin');
        $this->as($admin)->putJson('/api/v1/admin/settings/social', ['instagram' => 'http://instagram.com/x'])->assertStatus(422);
        $this->as($admin)->putJson('/api/v1/admin/settings/social', ['instagram' => 'https://www.instagram.com/myridenepal'])->assertOk();
        $this->as($admin)->putJson('/api/v1/admin/settings/seo', ['default_title' => str_repeat('x', 61)])->assertStatus(422);
        $this->as($admin)->putJson('/api/v1/admin/settings/branding', ['share_image_media_id' => (string) \Illuminate\Support\Str::uuid()])->assertStatus(422);
        $site = $this->as(null)->getJson('/api/v1/site')->assertOk();
        $site->assertJsonPath('data.social.instagram', 'https://www.instagram.com/myridenepal');
        $site->assertJsonPath('data.footer.tagline', 'Plan better. Ride prepared. Learn from real journeys.');
        $site->assertJsonPath('data.seo.default_title', 'My Ride Nepal: motorcycle travel in Nepal');
    }
}
