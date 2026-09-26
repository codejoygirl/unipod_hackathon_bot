<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('member_projects', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('owner_phone', 20);
            $table->string('name');
            $table->timestamps();

            $table->index('owner_phone');
        });

        Schema::create('member_project_files', function (Blueprint $table) {
            $table->id();
            $table->foreignUlid('project_id')->constrained('member_projects')->cascadeOnDelete();
            $table->foreignUlid('document_id')->constrained('member_vault_documents')->cascadeOnDelete();
            $table->string('owner_phone', 20);
            $table->timestamps();

            $table->unique(['project_id', 'document_id']);
            $table->index('owner_phone');
        });

        Schema::create('member_project_chats', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('project_id')->constrained('member_projects')->cascadeOnDelete();
            $table->string('owner_phone', 20);
            $table->string('title')->default('New chat');
            $table->timestamps();

            $table->index(['owner_phone', 'project_id']);
        });

        Schema::create('member_project_messages', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('chat_id')->constrained('member_project_chats')->cascadeOnDelete();
            $table->string('owner_phone', 20);
            $table->string('role', 20);
            $table->longText('body');
            $table->json('used_filenames')->nullable();
            $table->timestamps();

            $table->index('chat_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('member_project_messages');
        Schema::dropIfExists('member_project_chats');
        Schema::dropIfExists('member_project_files');
        Schema::dropIfExists('member_projects');
    }
};
