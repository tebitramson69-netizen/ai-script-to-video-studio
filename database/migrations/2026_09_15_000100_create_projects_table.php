<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('projects', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->longText('script')->nullable();

            // FR-2: locked at creation, passed to every shot call.
            $table->string('aspect_ratio', 8)->default('16:9');

            $table->string('status', 32)->default('draft');

            // v1 is English-only (PRD D3 default); the column exists so adding
            // FR/Pidgin in Phase 3 is data, not a migration.
            $table->string('language', 12)->default('en');
            $table->string('voice_id')->nullable();
            $table->string('video_model')->nullable();
            $table->string('music_mood')->nullable();

            // FR-11 / NFR-4: hard cap, enforced before dispatching a run.
            $table->decimal('budget_cap_usd', 8, 2);

            $table->unsignedBigInteger('final_asset_id')->nullable();
            $table->timestamp('exported_at')->nullable();
            $table->timestamps();

            $table->index('status');
            $table->index(['user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('projects');
    }
};
