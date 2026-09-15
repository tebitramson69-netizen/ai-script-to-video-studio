<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('type', 32);
            $table->string('disk', 32)->default('local');
            $table->string('path');
            $table->string('mime', 64)->nullable();
            $table->unsignedBigInteger('bytes')->nullable();
            $table->decimal('duration_seconds', 8, 3)->nullable();
            $table->string('model')->nullable();
            $table->decimal('cost_usd', 8, 4)->default(0);

            // Provider request/response detail worth keeping for reproducibility
            // (NFR-5): seed, revised prompt, provider job id.
            $table->json('meta')->nullable();

            $table->timestamps();

            $table->index(['project_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assets');
    }
};
