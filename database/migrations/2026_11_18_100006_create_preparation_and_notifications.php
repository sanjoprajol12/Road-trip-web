<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Preparation and safety guides. Safety-critical categories publish only after an expert in that field reviewed them.
        Schema::create('guides', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('slug', 80)->unique();
            $table->string('title', 140);
            $table->enum('category', ['preparation', 'mechanical', 'medical', 'road_safety', 'weather', 'emergency']);
            $table->text('body');
            $table->text('sources');
            $table->enum('status', ['draft', 'published', 'archived'])->default('draft');
            $table->foreignUuid('author_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('expert_reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('expert_reviewed_at')->nullable();
            $table->timestamps();
        });

        // Private to each rider: no staff access.
        Schema::create('emergency_contacts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->string('name', 120);
            $table->string('phone', 40);
            $table->string('relation', 60)->nullable();
            $table->timestamps();
        });

        Schema::create('notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('type');
            $table->uuidMorphs('notifiable');
            $table->jsonb('data');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
        Schema::dropIfExists('emergency_contacts');
        Schema::dropIfExists('guides');
    }
};
