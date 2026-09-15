<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Narration is synthesised per shot, not per project.
     *
     * FR-16 makes narration the master clock *per scene*, which is only
     * measurable if each shot has its own audio. The project-level narration
     * track that FR-12/Phase 1 calls for is then the ordered concatenation of
     * these, so its segment boundaries line up exactly with the cuts.
     */
    public function up(): void
    {
        Schema::table('shots', function (Blueprint $table) {
            $table->foreignId('narration_asset_id')
                ->nullable()
                ->after('asset_id')
                ->constrained('assets')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('shots', function (Blueprint $table) {
            $table->dropConstrainedForeignId('narration_asset_id');
        });
    }
};
