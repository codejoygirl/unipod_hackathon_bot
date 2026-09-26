<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('member_project_messages', function (Blueprint $table) {
            $table->foreignUlid('artefact_id')
                ->nullable()
                ->after('used_filenames')
                ->constrained('member_vault_artefacts')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('member_project_messages', function (Blueprint $table) {
            $table->dropConstrainedForeignId('artefact_id');
        });
    }
};
