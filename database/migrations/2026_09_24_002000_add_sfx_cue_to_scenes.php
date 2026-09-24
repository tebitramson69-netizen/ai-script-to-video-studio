<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The ambience the structurer heard in this scene's own prose (FR-13).
     *
     * Nullable, and null is the common case by design: a cue is only set when a
     * closed keyword map matches, because every cue is a paid request and a cue
     * that should not have been there is money spent on a sound that does not
     * belong in the video. Editable by the owner for the same reason the scene
     * list is (FR-3) — a heuristic's blind spot should be correctable before
     * anything is spent, not after.
     */
    public function up(): void
    {
        Schema::table('scenes', function (Blueprint $table) {
            $table->string('sfx_cue', 200)->nullable()->after('action');
        });
    }

    public function down(): void
    {
        Schema::table('scenes', function (Blueprint $table) {
            $table->dropColumn('sfx_cue');
        });
    }
};
