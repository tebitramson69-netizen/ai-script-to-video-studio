<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A project may render on two models: one for shots that have a locked
 * character reference, one for shots that do not.
 *
 * A single pin cannot express that. Kling's image-to-video endpoint takes a
 * starting frame and nothing else, so a project pinned to it cannot render an
 * establishing shot; a project pinned to its text-to-video sibling renders
 * every shot from the prompt alone and characters drift (PRD G2, FR-6). Real
 * scripts contain both kinds of shot, so neither pin is right for the whole
 * video.
 *
 * Nullable, and null keeps the existing behaviour exactly: one model for
 * everything. Choosing a companion is opt-in.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->string('video_model_i2v')->nullable()->after('video_model');
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn('video_model_i2v');
        });
    }
};
