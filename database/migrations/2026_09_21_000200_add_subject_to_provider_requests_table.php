<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * What a provider request is *for*.
     *
     * Polymorphic rather than a `shot_id` column because the same ledger
     * carries video (a Shot), narration (also a Shot) and music (a Project).
     * A dedicated column per capability would mean a migration every time a new
     * capability is queued.
     */
    public function up(): void
    {
        Schema::table('provider_requests', function (Blueprint $table) {
            $table->nullableMorphs('subject');
        });
    }

    public function down(): void
    {
        Schema::table('provider_requests', function (Blueprint $table) {
            $table->dropMorphs('subject');
        });
    }
};
