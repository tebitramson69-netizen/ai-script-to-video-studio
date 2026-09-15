<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('characters', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->text('description')->nullable();

            // FR-5/FR-6: the ONE locked image reused across every shot. Null
            // until the owner locks a candidate.
            $table->foreignId('canonical_reference_asset_id')
                ->nullable()
                ->constrained('assets')
                ->nullOnDelete();

            $table->timestamp('locked_at')->nullable();
            $table->timestamps();

            $table->unique(['project_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('characters');
    }
};
