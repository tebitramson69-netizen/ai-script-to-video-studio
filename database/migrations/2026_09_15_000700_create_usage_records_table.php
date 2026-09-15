<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('usage_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('asset_id')->nullable()->constrained('assets')->nullOnDelete();

            $table->string('provider', 64);
            $table->string('model')->nullable();

            // 'video.clip', 'image.reference', 'speech.narration', 'music.track',
            // 'sfx.effect', 'text.structure'
            $table->string('operation', 64);

            // NFR-5: every generation logs what it cost and in what unit, so a
            // pricing change can be re-costed from the record.
            $table->decimal('units', 12, 4)->default(0);
            $table->string('unit', 24)->nullable();
            $table->decimal('cost_usd', 8, 4)->default(0);

            $table->unsignedInteger('duration_ms')->nullable();
            $table->string('outcome', 16)->default('succeeded');
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['project_id', 'operation']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('usage_records');
    }
};
