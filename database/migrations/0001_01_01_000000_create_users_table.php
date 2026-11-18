<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // UUID ids keep the identifiers the Supabase-era schema used.
        Schema::create('users', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('display_name', 60);
            $table->string('username', 30)->nullable()->unique();
            $table->string('email')->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');
            $table->string('bio', 500)->nullable();
            $table->string('home_city', 80)->nullable();
            $table->timestamp('suspended_until')->nullable();
            $table->string('suspension_reason', 500)->nullable();
            $table->rememberToken();
            $table->timestamps();
        });
        DB::statement("alter table users add constraint users_username_format check (username ~ '^[a-z0-9_]{3,30}$')");
        DB::statement('alter table users add constraint users_display_name_length check (char_length(trim(display_name)) between 1 and 60)');

        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignUuid('user_id')->nullable()->index()->constrained()->cascadeOnDelete();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sessions');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('users');
    }
};
