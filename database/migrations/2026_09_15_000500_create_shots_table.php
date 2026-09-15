<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shots', function (Blueprint $table) {
            $table->id();

            // project_id is denormalised from scene_id so that project-wide
            // queries (export readiness, cost rollup) do not need a join.
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('scene_id')->constrained()->cascadeOnDelete();

            $table->unsignedInteger('sequence');
            $table->text('prompt');

            // The slice of the scene's narration this shot covers. One shot per
            // scene in P1 unless FR-17 splits it.
            $table->text('narration_segment')->nullable();

            $table->string('model')->nullable();
            $table->unsignedInteger('seed')->nullable();

            // What the timing engine asked the model for, rounded up to a
            // supported clip length (FR-16).
            $table->decimal('target_duration_seconds', 6, 2);

            // What the narration actually needs. At assembly the clip is
            // trimmed or held to this (FR-18).
            $table->decimal('narration_duration_seconds', 6, 2)->nullable();

            $table->foreignId('asset_id')->nullable()->constrained('assets')->nullOnDelete();
            $table->string('status', 16)->default('pending');
            $table->decimal('cost_usd', 8, 4)->default(0);
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->text('error')->nullable();
            $table->timestamp('rendered_at')->nullable();
            $table->timestamps();

            $table->unique(['project_id', 'sequence']);
            $table->index('status');
            $table->index(['project_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shots');
    }
};
