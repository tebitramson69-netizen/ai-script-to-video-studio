<?php

namespace App\Services\Pipeline;

use App\Enums\ProjectStatus;
use App\Enums\ShotStatus;
use App\Models\Character;
use App\Models\Project;
use App\Models\Shot;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Owns every project status change, and with it the PRD §8 invalidation rule:
 *
 *   "Editing an upstream artifact invalidates downstream work. Editing the scene
 *    list resets to SCRIPT_READY; re-locking a character reference marks
 *    affected shots stale and resets them to SCENES_READY; regenerating one shot
 *    invalidates only assembly. Stale assets are marked, not deleted, and export
 *    is blocked while any stale shot exists."
 *
 * Nothing else in the app writes `projects.status` directly. That is the whole
 * point: invalidation is easy to get right in one place and impossible to get
 * right if it is spread across seven controllers.
 */
class ProjectStateMachine
{
    /**
     * Move the project forward. Never moves it backwards — a job that finishes
     * late must not resurrect a stage the owner has already invalidated, which
     * is what makes the pipeline safe to re-run (NFR-3).
     */
    public function advanceTo(Project $project, ProjectStatus $target): Project
    {
        if ($project->status->isAtLeast($target)) {
            return $project;
        }

        $project->forceFill(['status' => $target])->save();

        return $project;
    }

    /**
     * Move the project backwards to `$target`, but only if it is currently
     * further along. Raising a status is never invalidation, so this is a no-op
     * in that direction.
     */
    public function demoteTo(Project $project, ProjectStatus $target): Project
    {
        if (! $project->status->isAtLeast($target) || $project->status === $target) {
            return $project;
        }

        $project->forceFill(['status' => $target])->save();

        return $project;
    }

    /**
     * The scene list changed (text, order, mood, insert or delete).
     *
     * Everything downstream of the script is now suspect, so the project returns
     * to SCRIPT_READY. Shots are marked stale rather than deleted so their clips
     * remain viewable and re-usable if the owner undoes the edit.
     */
    public function sceneListEdited(Project $project): Project
    {
        return DB::transaction(function () use ($project) {
            $this->markShotsStale($project->shots()->getQuery());
            $this->clearExport($project);

            return $this->demoteTo($project, ProjectStatus::ScriptReady);
        });
    }

    /**
     * A character's canonical reference was locked or re-locked (FR-5).
     *
     * Only shots that actually feature this character are affected — a narrator
     * shot with no character in it is still valid, and re-rendering it would be
     * money spent for nothing.
     */
    public function characterReferenceLocked(Project $project, Character $character): Project
    {
        return DB::transaction(function () use ($project, $character) {
            $affected = $project->shots()
                ->getQuery()
                ->whereHas('characters', fn ($q) => $q->whereKey($character->getKey()));

            $staleCount = $this->markShotsStale($affected);

            $this->clearExport($project);

            // No shots exist yet (the normal first-lock case): this is forward
            // progress, not invalidation.
            if (! $project->shots()->exists()) {
                return $this->advanceTo($project, ProjectStatus::CharactersReady);
            }

            // Shots exist and some are now stale: they must be re-rendered, so
            // the project sits back at "shots planned, not rendered".
            if ($staleCount > 0) {
                return $this->demoteTo($project, ProjectStatus::ScenesReady);
            }

            return $project;
        });
    }

    /**
     * A single shot is being regenerated (FR-10).
     *
     * Narration and music are untouched by a shot re-render, so this invalidates
     * assembly only — the project falls back to VOICE_READY if audio already
     * exists, otherwise to SHOTS_READY.
     */
    public function shotInvalidated(Project $project, Shot $shot): Project
    {
        return DB::transaction(function () use ($project, $shot) {
            $shot->forceFill([
                'status' => ShotStatus::Pending,
                'error' => null,
            ])->save();

            $this->clearExport($project);

            $fallback = $project->narrationAsset() !== null
                ? ProjectStatus::VoiceReady
                : ProjectStatus::ShotsReady;

            return $this->demoteTo($project, $fallback);
        });
    }

    /**
     * Narration or music was regenerated (FR-15). Clips are unaffected; only the
     * assembled export is.
     */
    public function audioInvalidated(Project $project): Project
    {
        return DB::transaction(function () use ($project) {
            $this->clearExport($project);

            return $this->demoteTo($project, ProjectStatus::ShotsReady);
        });
    }

    /**
     * Why export is currently blocked, or null if it is not.
     *
     * Returned as a message rather than a bool so the UI can tell the owner what
     * to fix instead of just greying out a button.
     */
    public function exportBlockedReason(Project $project): ?string
    {
        if (! $project->shots()->exists()) {
            return 'No shots have been planned yet.';
        }

        $stale = $project->shots()->where('status', ShotStatus::Stale)->count();
        if ($stale > 0) {
            return "{$stale} shot(s) are stale because an upstream change invalidated them. Re-render them before exporting.";
        }

        $unrendered = $project->unrenderedShotCount();
        if ($unrendered > 0) {
            return "{$unrendered} shot(s) have not rendered successfully yet.";
        }

        if ($project->narrationAsset() === null) {
            return 'Narration has not been generated yet.';
        }

        return null;
    }

    /**
     * Mark every rendered shot in the given query stale. Pending and failed shots
     * are left alone — they have nothing to invalidate.
     *
     * @param  Builder<Shot>  $query
     * @return int number of shots marked stale
     */
    protected function markShotsStale($query): int
    {
        return (clone $query)
            ->whereIn('status', [ShotStatus::Rendered->value, ShotStatus::Stale->value])
            ->update(['status' => ShotStatus::Stale->value]);
    }

    /**
     * Drop the reference to the exported mp4. The asset row and the file are
     * kept — "stale assets are marked, not deleted" — but the project no longer
     * advertises it as the current export.
     */
    protected function clearExport(Project $project): void
    {
        if ($project->final_asset_id === null && $project->exported_at === null) {
            return;
        }

        $project->forceFill([
            'final_asset_id' => null,
            'exported_at' => null,
        ])->save();
    }
}
