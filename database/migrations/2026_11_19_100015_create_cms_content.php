<?php

use App\Support\Permissions;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Stage 8 CMS: one table for pages, FAQs, blog posts, categories, tags, authors, team members, popups and banners
// (type-specific fields in `data`, validated by config/cms.php), and the contact inbox. Additive only.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cms_entries', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('type', 20);
            $t->string('slug', 100)->nullable();
            $t->string('title', 200);
            $t->string('summary', 500)->nullable();
            $t->text('body')->nullable();
            $t->jsonb('data')->default('{}');
            $t->foreignUuid('media_asset_id')->nullable()->constrained()->nullOnDelete();
            $t->string('status', 10)->default('draft');
            $t->timestamp('publish_at')->nullable();
            $t->timestamp('expires_at')->nullable();
            $t->timestamp('published_at')->nullable();
            $t->integer('position')->default(0);
            $t->string('seo_title', 70)->nullable();
            $t->string('seo_description', 170)->nullable();
            $t->foreignUuid('updated_by_staff_id')->nullable()->constrained('staff_accounts')->nullOnDelete();
            $t->timestamps();
            $t->index(['type', 'status', 'position']);
        });
        DB::statement("alter table cms_entries add constraint cms_entries_type check (type in ('page', 'faq', 'post', 'blog_category', 'blog_tag', 'author', 'team_member', 'popup', 'banner'))");
        DB::statement("alter table cms_entries add constraint cms_entries_status check (status in ('draft', 'published', 'archived'))");
        DB::statement('alter table cms_entries add constraint cms_entries_schedule check (expires_at is null or publish_at is null or expires_at > publish_at)');
        DB::statement('create unique index cms_entries_type_slug on cms_entries (type, slug) where slug is not null');

        Schema::create('contact_messages', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('name', 100);
            $t->string('email', 255);
            $t->string('subject', 150);
            $t->text('body');
            $t->string('status', 10)->default('new');
            $t->timestamp('read_at')->nullable();
            $t->foreignUuid('handled_by_staff_id')->nullable()->constrained('staff_accounts')->nullOnDelete();
            $t->string('ip_hash', 64)->nullable(); // hashed with the app key: abuse checks without keeping the address
            $t->timestamps();
            $t->index(['status', 'created_at']);
        });
        DB::statement("alter table contact_messages add constraint contact_messages_status check (status in ('new', 'open', 'done', 'spam'))");

        Permissions::sync();
    }

    public function down(): void
    {
        Schema::dropIfExists('contact_messages');
        Schema::dropIfExists('cms_entries');
    }
};
