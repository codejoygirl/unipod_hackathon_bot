<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('community_meetings', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('community_id')->constrained('communities')->cascadeOnDelete();
            $table->string('title', 255);
            $table->string('category', 32)->default('office_hours');
            $table->string('platform', 32)->default('teams');
            $table->string('platform_name', 64)->nullable();
            $table->text('url');
            $table->string('schedule', 255)->nullable();
            $table->string('time_context', 255)->nullable();
            $table->string('host', 255)->nullable();
            $table->text('description')->nullable();
            $table->string('meeting_code', 128)->nullable();
            $table->string('passcode', 128)->nullable();
            $table->boolean('is_live_now')->default(false);
            $table->string('created_by_phone', 64)->nullable();
            $table->timestamp('published_at')->nullable()->index();
            $table->timestamps();

            $table->index(['community_id', 'published_at']);
        });

        Schema::create('web_push_subscriptions', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('community_id')->constrained('communities')->cascadeOnDelete();
            $table->string('member_phone', 64)->index();
            $table->text('endpoint');
            $table->string('public_key', 255);
            $table->string('auth_token', 255);
            $table->string('content_encoding', 32)->default('aes128gcm');
            $table->string('user_agent', 255)->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();

            $table->unique(['endpoint'], 'web_push_endpoint_unique');
            $table->index(['community_id', 'member_phone']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('web_push_subscriptions');
        Schema::dropIfExists('community_meetings');
    }
};
