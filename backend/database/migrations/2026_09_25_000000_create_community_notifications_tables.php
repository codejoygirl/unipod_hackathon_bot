<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('community_notifications', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('community_id')->constrained('communities')->cascadeOnDelete();
            $table->string('title', 255);
            $table->text('message');
            $table->string('category', 32)->default('Announcement');
            $table->string('action_query', 500)->nullable();
            $table->string('created_by_phone', 64)->nullable();
            $table->timestamp('published_at')->nullable()->index();
            $table->timestamps();

            $table->index(['community_id', 'published_at']);
        });

        Schema::create('community_notification_reads', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('notification_id')
                ->constrained('community_notifications')
                ->cascadeOnDelete();
            $table->string('member_phone', 64);
            $table->timestamp('read_at');
            $table->timestamps();

            $table->unique(['notification_id', 'member_phone'], 'notif_reads_unique');
            $table->index(['member_phone', 'read_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('community_notification_reads');
        Schema::dropIfExists('community_notifications');
    }
};
