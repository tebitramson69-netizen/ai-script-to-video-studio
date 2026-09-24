<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Which scene an asset belongs to, for the assets that belong to one.
     *
     * Narration and music are project-wide and leave this null; a sound effect
     * belongs to exactly one scene, and the assembler needs to know which in
     * order to position it — an effect is the first asset in this pipeline whose
     * place on the timeline is not simply "the whole video".
     *
     * nullOnDelete rather than cascade, deliberately. A database-level cascade
     * does not fire Eloquent events, so the Asset model's deleted() hook would
     * never run and the file would be orphaned on disk while its row vanished.
     * The Scene model deletes its own effects through Eloquent instead, which
     * takes the files with them; this constraint is the backstop for any path
     * that does not.
     */
    public function up(): void
    {
        Schema::table('assets', function (Blueprint $table) {
            $table->foreignId('scene_id')->nullable()->after('project_id')
                ->constrained()->nullOnDelete();

            $table->index(['scene_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::table('assets', function (Blueprint $table) {
            $table->dropForeign(['scene_id']);
            $table->dropIndex(['scene_id', 'type']);
            $table->dropColumn('scene_id');
        });
    }
};
