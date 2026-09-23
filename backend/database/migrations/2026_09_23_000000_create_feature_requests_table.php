<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('feature_requests', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('community_id')->nullable()->constrained('communities')->nullOnDelete();
            $table->string('user_type', 32)->default('member'); // 'member' or 'admin'
            $table->string('title', 255);
            $table->text('description');
            $table->string('user_name', 255)->nullable();
            $table->string('user_phone', 64)->nullable();
            $table->string('user_email', 255)->nullable();
            $table->string('ref', 32)->nullable()->index();
            $table->string('status', 32)->default('open'); // 'open', 'approved', 'declined', 'implemented'
            $table->string('channel', 32)->default('web_chat');
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['community_id', 'status']);
            $table->index(['user_type', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('feature_requests');
    }
};
