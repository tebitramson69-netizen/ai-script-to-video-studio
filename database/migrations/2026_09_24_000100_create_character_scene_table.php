<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which characters the structurer attributed to which scene.
 *
 * Until now SceneDraft::characterNames was computed and then discarded, and
 * PlanShotsJob re-derived the cast by searching the narration text for each
 * character's name. That worked only because nothing removed names from the
 * narration.
 *
 * Dialogue handling breaks it. A cue like "ADA: Where is the boat?" must have
 * its label stripped, or the single narrator voice (FR-14) reads out "Ada colon,
 * where is the boat" — and once stripped, the name is no longer in the text for
 * a search to find. The scene would silently lose its character reference, and
 * PRD G2 character consistency would fail for exactly the scenes that have
 * characters in them.
 *
 * So attribution becomes data rather than something re-guessed downstream.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('character_scene', function (Blueprint $table) {
            $table->id();
            $table->foreignId('character_id')->constrained()->cascadeOnDelete();
            $table->foreignId('scene_id')->constrained()->cascadeOnDelete();

            // One row per pair. The structurer already de-duplicates, and the
            // validator refuses a duplicate, but a scene listing a character
            // twice would double its weight in any later per-character logic.
            $table->unique(['character_id', 'scene_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('character_scene');
    }
};
