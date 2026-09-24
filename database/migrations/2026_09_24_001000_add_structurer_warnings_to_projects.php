<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What the structurer was unsure about, kept where the owner can see it.
 *
 * The breakdown already carried warnings — an empty cast, scenes merged to fit
 * the limit — and StructureScriptJob only wrote them to the log. A warning in a
 * log file cannot change a decision: the person who needs to know the cast is
 * empty is looking at the project page, not at `storage/logs`.
 *
 * Replaced wholesale on every re-parse, because they describe one parse rather
 * than accumulating. Nullable so a project parsed before this column existed
 * reads as "nothing recorded" rather than "no warnings".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->json('structurer_warnings')->nullable()->after('script');
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn('structurer_warnings');
        });
    }
};
