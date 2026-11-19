<?php

use App\Support\Permissions;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Roadmap stage 3: site settings, the media library, and a storage disk recorded per file so stored files
 * keep working after a storage provider is added. Additive only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('settings', function (Blueprint $table) {
            $table->string('key', 80)->primary();
            $table->jsonb('value')->nullable();
            $table->text('secret')->nullable(); // encrypted with APP_KEY; never returned by the API
            $table->foreignUuid('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('updated_at')->nullable();
        });
        DB::statement('alter table settings add constraint settings_one_value check (value is null or secret is null)');

        Schema::create('media_assets', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('disk', 20);
            $table->string('path');
            $table->enum('visibility', ['public', 'private'])->default('public');
            $table->string('mime', 40);
            $table->unsignedInteger('size_bytes');
            $table->unsignedSmallInteger('width');
            $table->unsignedSmallInteger('height');
            $table->string('alt', 200);
            $table->string('credit', 200);
            // Owner rule: CC0 / public domain, or supplied by the owner.
            $table->enum('license', ['cc0', 'public_domain', 'owner_supplied']);
            $table->string('source_url', 500)->nullable();
            $table->string('place_note', 200)->nullable(); // which place it shows (photos only for the place shown)
            $table->foreignUuid('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index('created_at');
        });

        // Where an asset is used (branding, CMS, destinations…). A used asset cannot be deleted.
        Schema::create('media_usages', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('media_asset_id')->constrained()->restrictOnDelete();
            $table->string('owner_type', 60);
            $table->string('owner_id', 80);
            $table->string('field', 60);
            $table->timestamps();
            $table->unique(['owner_type', 'owner_id', 'field']);
        });

        Schema::table('contribution_media', function (Blueprint $table) {
            $table->string('disk', 20)->default('local');
            $table->unsignedInteger('size_bytes')->nullable();
        });

        Permissions::sync();
        if (DB::table('roles')->where('slug', 'moderator')->exists()) {
            DB::table('permission_role')->insertOrIgnore(['role_slug' => 'moderator', 'permission_key' => 'media.manage']);
        }
    }

    public function down(): void
    {
        Schema::table('contribution_media', fn (Blueprint $table) => $table->dropColumn(['disk', 'size_bytes']));
        Schema::dropIfExists('media_usages');
        Schema::dropIfExists('media_assets');
        Schema::dropIfExists('settings');
    }
};
