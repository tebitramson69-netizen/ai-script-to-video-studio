<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scenes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();

            // 1-based display order. Editable by the owner (FR-3), which is why
            // it is a plain integer rather than the primary key order.
            $table->unsignedInteger('sequence');

            $table->string('setting');
            $table->text('narration');
            $table->string('mood', 64)->nullable();
            $table->text('action')->nullable();
            $table->timestamps();

            $table->unique(['project_id', 'sequence']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scenes');
    }
};
