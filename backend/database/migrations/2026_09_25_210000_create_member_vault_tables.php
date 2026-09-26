<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('member_vaults', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('owner_phone', 20);
            $table->string('name');
            $table->string('kind', 20)->default('personal');
            $table->timestamps();

            $table->unique(['owner_phone', 'kind']);
            $table->index('owner_phone');
        });

        Schema::create('member_vault_documents', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('vault_id')->constrained('member_vaults')->cascadeOnDelete();
            $table->string('owner_phone', 20);
            $table->string('filename');
            $table->string('mime', 120)->nullable();
            $table->unsignedInteger('byte_size')->default(0);
            $table->string('disk', 40)->default('local');
            $table->string('storage_path');
            $table->longText('extracted_text')->nullable();
            $table->string('status', 20)->default('ready');
            $table->timestamps();

            $table->index(['owner_phone', 'vault_id']);
        });

        Schema::create('member_vault_artefacts', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('vault_id')->constrained('member_vaults')->cascadeOnDelete();
            $table->string('owner_phone', 20);
            $table->string('kind', 40);
            $table->string('title');
            $table->longText('body');
            $table->timestamps();

            $table->index(['owner_phone', 'vault_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('member_vault_artefacts');
        Schema::dropIfExists('member_vault_documents');
        Schema::dropIfExists('member_vaults');
    }
};
