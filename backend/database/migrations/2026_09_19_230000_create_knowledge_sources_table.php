<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('knowledge_documents', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUlid('community_id')->constrained('communities')->cascadeOnDelete();
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $table->string('name');
            $table->string('uri');
            $table->string('source_type', 50);
            $table->string('authority_tier', 50)->default('community_discussion');
            $table->string('lifecycle_status', 32)->default('draft');
            $table->string('language', 10)->default('en');
            $table->longText('content');
            $table->string('content_sha256', 64)->nullable();
            $table->string('storage_path')->nullable();
            $table->uuid('ai_source_id')->nullable();
            $table->uuid('ai_version_id')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamp('effective_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'uri']);
            $table->index(['tenant_id', 'community_id', 'lifecycle_status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('knowledge_documents');
    }
};
