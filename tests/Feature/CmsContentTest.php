<?php

namespace Tests\Feature;

use App\Models\StaffAccount;
use App\Support\PreviewToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/** Stage 8 CMS: pages, FAQs, blog with taxonomies, team, popups, banners, contact inbox. */
class CmsContentTest extends TestCase
{
    use RefreshDatabase;

    private function as(?\Illuminate\Contracts\Auth\Authenticatable $u)
    {
        $this->app['auth']->forgetGuards();

        return $u ? $this->actingAs($u, 'sanctum') : $this;
    }

    /** A staff account whose only role holds exactly these permissions. */
    private function staffWith(array $keys): StaffAccount
    {
        $slug = 'r'.Str::lower(Str::random(8));
        DB::table('roles')->insert(['slug' => $slug, 'name' => $slug, 'created_at' => now(), 'updated_at' => now()]);
        foreach ($keys as $k) {
            DB::table('permission_role')->insert(['role_slug' => $slug, 'permission_key' => $k]);
        }

        return $this->staff($slug);
    }

    private function create(StaffAccount $s, string $type, array $body)
    {
        return $this->as($s)->postJson("/api/v1/admin/cms/entries/{$type}", $body);
    }

    public function test_module_permissions_and_publish_permission(): void
    {
        $editor = $this->staffWith(['cms.faqs.manage']);
        $this->as($editor)->getJson('/api/v1/admin/cms/types')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.type', 'faq')->assertJsonPath('can_publish', false);
        $this->create($editor, 'page', ['title' => 'About', 'slug' => 'about', 'status' => 'draft'])->assertForbidden();
        $this->create($editor, 'faq', ['title' => 'Can I ride solo?', 'body' => 'Yes.', 'status' => 'published'])->assertForbidden();
        $id = $this->create($editor, 'faq', ['title' => 'Can I ride solo?', 'body' => 'Yes.', 'status' => 'draft', 'data' => ['category' => 'Trips', 'admin' => 1]])
            ->assertCreated()->assertJsonPath('data.data', ['category' => 'Trips'])->json('data.id');
        $this->as(null)->getJson('/api/v1/content/faq')->assertJsonCount(0, 'data');

        $publisher = $this->staffWith(['cms.faqs.manage', 'cms.content.publish']);
        $this->as($publisher)->putJson("/api/v1/admin/cms/entries/faq/{$id}", ['title' => 'Can I ride solo?', 'body' => 'Yes.', 'status' => 'published'])->assertOk();
        $this->assertDatabaseHas('audit_logs', ['action' => 'cms.faq.published', 'subject_id' => $id]);
        $this->as(null)->getJson('/api/v1/content/faq')->assertJsonCount(1, 'data')->assertJsonMissingPath('data.0.updated_by_staff_id');
        // Live content cannot be edited without the publish permission, and published items are archived, not deleted.
        $this->as($editor)->putJson("/api/v1/admin/cms/entries/faq/{$id}", ['title' => 'Changed', 'body' => 'Yes.', 'status' => 'draft'])->assertForbidden();
        $this->as($publisher)->deleteJson("/api/v1/admin/cms/entries/faq/{$id}")->assertStatus(422);
        $this->as($this->rider())->getJson('/api/v1/admin/cms/entries/faq')->assertUnauthorized();
    }

    public function test_schedule_expiry_and_preview_of_pages(): void
    {
        $admin = $this->staff('admin');
        $this->create($admin, 'page', ['title' => 'About us', 'slug' => 'about', 'status' => 'published', 'publish_at' => now()->addDay()->toIso8601String(),
            'data' => ['blocks' => [['kind' => 'heading', 'text' => 'Who we are'], ['kind' => 'script', 'text' => 'x']]]])->assertStatus(422)->assertJsonValidationErrors('data.blocks.1.kind');
        $this->create($admin, 'page', ['title' => 'About us', 'slug' => 'about', 'status' => 'published', 'publish_at' => now()->addDay()->toIso8601String(),
            'seo_title' => 'About My Ride Nepal', 'data' => ['blocks' => [['kind' => 'heading', 'text' => 'Who we are']]]])->assertCreated();
        $this->create($admin, 'page', ['title' => 'Same slug', 'slug' => 'about', 'status' => 'draft'])->assertStatus(422)->assertJsonValidationErrors('slug');
        $this->as(null)->getJson('/api/v1/content/page/about')->assertNotFound(); // scheduled for tomorrow

        $token = PreviewToken::issue($admin->id);
        $this->as(null)->getJson('/api/v1/content/page/about', [PreviewToken::HEADER => $token])->assertOk()->assertJsonPath('preview', true)
            ->assertJsonPath('data.data.blocks.0.text', 'Who we are')->assertJsonPath('data.seo_title', 'About My Ride Nepal');
        $this->as(null)->getJson('/api/v1/content/page/about', [PreviewToken::HEADER => $token.'x'])->assertNotFound();

        $this->travel(2)->days();
        $this->as(null)->getJson('/api/v1/content/page/about')->assertOk()->assertJsonPath('preview', false);
        $this->travelBack(); // staff sessions end after 12 h; the banner checks need no time travel
        $this->create($admin, 'banner', ['title' => 'Old banner', 'status' => 'published', 'expires_at' => now()->subHour()->toIso8601String(), 'data' => ['placement' => 'home']])->assertStatus(201);
        $this->create($admin, 'banner', ['title' => 'Live banner', 'status' => 'published', 'data' => ['placement' => 'home', 'cta' => ['label' => 'Read', 'to' => 'javascript:alert(1)']]])
            ->assertStatus(422)->assertJsonValidationErrors('data.cta.to');
        $this->create($admin, 'banner', ['title' => 'Live banner', 'status' => 'published', 'data' => ['placement' => 'home', 'cta' => ['label' => 'Read', 'to' => '/blog']]])->assertCreated();
        $this->as(null)->getJson('/api/v1/content/banner?placement=home')->assertJsonCount(1, 'data')->assertJsonPath('data.0.title', 'Live banner');
        $this->as(null)->getJson('/api/v1/content/banner?placement=blog')->assertJsonCount(0, 'data');
    }

    public function test_blog_with_categories_tags_and_authors(): void
    {
        $admin = $this->staff('admin');
        $cat = $this->create($admin, 'blog_category', ['title' => 'Gear', 'slug' => 'gear', 'status' => 'published'])->json('data.id');
        $tag = $this->create($admin, 'blog_tag', ['title' => 'Monsoon', 'slug' => 'monsoon', 'status' => 'published'])->json('data.id');
        $author = $this->create($admin, 'author', ['title' => 'Test Author', 'slug' => 'test-author', 'status' => 'published'])->json('data.id');
        $this->create($admin, 'post', ['title' => 'Wrong ref', 'slug' => 'wrong', 'body' => 'Text', 'status' => 'draft', 'data' => ['category_id' => $tag]])->assertStatus(422)->assertJsonValidationErrors('data.category_id');
        $this->create($admin, 'post', ['title' => 'Rain covers', 'slug' => 'rain-covers', 'summary' => 'Keeping bags dry.', 'body' => 'Long text', 'status' => 'published',
            'data' => ['category_id' => $cat, 'tag_ids' => [$tag], 'author_id' => $author]])->assertCreated();
        $this->create($admin, 'post', ['title' => 'Other post', 'slug' => 'other', 'body' => 'Long text', 'status' => 'published'])->assertCreated();

        $this->as(null)->getJson('/api/v1/content/post')->assertJsonCount(2, 'data')->assertJsonPath('data.0.body', null);
        $this->as(null)->getJson('/api/v1/content/post?category=gear')->assertJsonCount(1, 'data')->assertJsonPath('data.0.data.category.title', 'Gear');
        $this->as(null)->getJson('/api/v1/content/post?tag=monsoon')->assertJsonCount(1, 'data');
        $this->as(null)->getJson('/api/v1/content/post?author=test-author')->assertJsonCount(1, 'data');
        $this->as(null)->getJson('/api/v1/content/post?q=bags')->assertJsonCount(1, 'data');
        $this->as(null)->getJson('/api/v1/content/post/rain-covers')->assertOk()->assertJsonPath('data.body', 'Long text')->assertJsonPath('data.data.tags.0.slug', 'monsoon');
        // Archive then delete: refused while a post still uses the tag.
        $this->as($admin)->putJson("/api/v1/admin/cms/entries/blog_tag/{$tag}", ['title' => 'Monsoon', 'slug' => 'monsoon', 'status' => 'archived'])->assertOk();
        $this->as($admin)->deleteJson("/api/v1/admin/cms/entries/blog_tag/{$tag}")->assertStatus(422);
        $this->as(null)->getJson('/api/v1/content/post/rain-covers')->assertJsonCount(0, 'data.data.tags'); // archived tags disappear publicly
    }

    public function test_media_in_use_cannot_be_deleted(): void
    {
        $admin = $this->staff('admin');
        $media = (string) Str::uuid();
        DB::table('media_assets')->insert(['id' => $media, 'disk' => 'local', 'path' => 'media/x.webp', 'visibility' => 'public', 'mime' => 'image/webp', 'size_bytes' => 10,
            'width' => 10, 'height' => 10, 'alt' => 'A test image', 'credit' => 'Owner supplied', 'license' => 'owner_supplied', 'created_at' => now(), 'updated_at' => now()]);
        $id = $this->create($admin, 'team_member', ['title' => 'Test Person', 'status' => 'draft', 'media_asset_id' => $media, 'data' => ['role' => 'Editor']])->assertCreated()->json('data.id');
        $this->assertDatabaseHas('media_usages', ['media_asset_id' => $media, 'owner_type' => 'cms_entries', 'owner_id' => $id]);
        $this->as($admin)->deleteJson("/api/v1/admin/media/{$media}")->assertStatus(422);
        $this->as($admin)->deleteJson("/api/v1/admin/cms/entries/team_member/{$id}")->assertNoContent();
        $this->assertDatabaseMissing('media_usages', ['owner_id' => $id]);
        // The same protection now covers the branding share image (settings) and homepage sections.
        $this->as($admin)->putJson('/api/v1/admin/settings/branding', ['share_image_media_id' => $media])->assertOk();
        $this->as($admin)->deleteJson("/api/v1/admin/media/{$media}")->assertStatus(422);
        $this->as($admin)->putJson('/api/v1/admin/settings/branding', ['share_image_media_id' => null])->assertOk();
        $this->as($admin)->deleteJson("/api/v1/admin/media/{$media}")->assertNoContent();
    }

    public function test_contact_form_and_inbox(): void
    {
        $msg = ['name' => 'Test Sender', 'email' => 'Sender@Example.test', 'subject' => 'A question', 'body' => 'Is there a ride calendar for spring?'];
        $this->as(null)->postJson('/api/v1/contact', $msg + ['website' => 'spam.example'])->assertStatus(422);
        $this->as(null)->postJson('/api/v1/contact', ['body' => 'short'] + $msg)->assertStatus(422);
        $this->as(null)->postJson('/api/v1/contact', $msg)->assertCreated();
        $this->assertDatabaseHas('contact_messages', ['email' => 'sender@example.test', 'status' => 'new']);
        $this->assertDatabaseMissing('contact_messages', ['ip_hash' => '127.0.0.1']);

        $this->as($this->rider())->getJson('/api/v1/admin/contact-messages')->assertUnauthorized();
        $this->as($this->staffWith(['cms.faqs.manage']))->getJson('/api/v1/admin/contact-messages')->assertForbidden();
        $reader = $this->staffWith(['contact.view']);
        $id = $this->as($reader)->getJson('/api/v1/admin/contact-messages')->assertOk()->assertJsonPath('meta.unread', 1)->json('data.0.id');
        $this->as($reader)->patchJson("/api/v1/admin/contact-messages/{$id}", ['read' => true])->assertOk();
        $this->as($reader)->patchJson("/api/v1/admin/contact-messages/{$id}", ['status' => 'done'])->assertForbidden();
        $this->as($reader)->deleteJson("/api/v1/admin/contact-messages/{$id}")->assertForbidden();
        $manager = $this->staffWith(['contact.view', 'contact.manage']);
        $this->as($manager)->patchJson("/api/v1/admin/contact-messages/{$id}", ['status' => 'spam'])->assertOk();
        $this->assertDatabaseHas('audit_logs', ['action' => 'contact.spam', 'subject_id' => $id]);
        $this->as($manager)->getJson('/api/v1/admin/contact-messages')->assertJsonCount(0, 'data'); // spam hidden by default
        $this->as($manager)->getJson('/api/v1/admin/contact-messages?status=spam')->assertJsonCount(1, 'data');
    }
}
