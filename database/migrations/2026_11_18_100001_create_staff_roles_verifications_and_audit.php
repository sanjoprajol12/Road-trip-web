<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_roles', function (Blueprint $table) {
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->enum('role', ['moderator', 'admin']);
            $table->foreignUuid('granted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('granted_at')->useCurrent();
            $table->primary(['user_id', 'role']);
        });

        Schema::create('user_verifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->enum('kind', ['identity', 'trip_contributor', 'expert_reviewer', 'event_organizer']);
            $table->string('scope', 40)->default('');
            $table->enum('status', ['pending', 'approved', 'rejected', 'suspended', 'revoked'])->default('pending');
            $table->text('statement');
            $table->text('review_note')->nullable();
            $table->foreignUuid('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'created_at']);
        });
        DB::statement("alter table user_verifications add constraint uv_scope check ((kind = 'expert_reviewer') = (scope <> '') and scope ~ '^[a-z_]{0,40}$')");
        DB::statement('alter table user_verifications add constraint uv_statement_length check (char_length(trim(statement)) between 20 and 2000)');
        DB::statement('alter table user_verifications add constraint uv_note_length check (char_length(review_note) <= 2000)');
        // At most one live claim per kind/scope; rejected and revoked rows stay as history.
        DB::statement("create unique index uv_one_open on user_verifications (user_id, kind, scope) where status in ('pending', 'approved', 'suspended')");

        // Append-only trail. No foreign key on actor_id so it survives account deletion.
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->uuid('actor_id')->nullable()->index();
            $table->string('action', 60);
            $table->string('subject_type', 40);
            $table->string('subject_id', 64);
            $table->jsonb('old_data')->nullable();
            $table->jsonb('new_data')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->timestamp('created_at')->useCurrent()->index();
            $table->index(['subject_type', 'subject_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
        Schema::dropIfExists('user_verifications');
        Schema::dropIfExists('user_roles');
    }
};
