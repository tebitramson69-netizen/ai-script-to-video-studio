<?php

namespace App\Jobs;

use App\Contracts\ScriptStructurer;
use App\Enums\ProjectStatus;
use App\Models\Project;
use App\Services\Pipeline\ProjectStateMachine;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Stage 1 (PRD §8): script → structured scene list + detected cast
 * (FR-1, FR-3, FR-4).
 *
 * Replaces any existing breakdown wholesale. That is safe because this job only
 * runs from DRAFT or on an explicit owner re-parse, and the state machine
 * invalidates downstream work when the scene list changes.
 */
class StructureScriptJob extends StudioJob
{
    public function __construct(public int $projectId) {}

    public function handle(
        ScriptStructurer $structurer,
        ProjectStateMachine $stateMachine,
    ): void {
        $project = Project::find($this->projectId);

        if ($project === null || blank($project->script)) {
            return;
        }

        $stateMachine->advanceTo($project, ProjectStatus::Scripting);

        // InvalidScriptException is not caught: it means the owner must change
        // the script, and failed() below returns the project to DRAFT so they
        // can. Swallowing it would leave a project that looks parsed.
        $breakdown = $structurer->structure($project->script, $project->language);

        DB::transaction(function () use ($project, $breakdown) {
            // Characters first: scenes reference them by name.
            $project->scenes()->delete();

            // Characters are upserted rather than replaced: a re-parse must not
            // destroy a locked canonical reference the owner has already paid
            // for (PRD §10.2).
            $characters = [];

            foreach ($breakdown->characters as $draft) {
                $characters[$draft->name] = $project->characters()->firstOrCreate(
                    ['name' => $draft->name],
                    ['description' => $draft->description],
                );
            }

            foreach ($breakdown->scenes as $index => $draft) {
                $scene = $project->scenes()->create([
                    'sequence' => $index + 1,
                    'setting' => $draft->setting,
                    'narration' => $draft->narration,
                    'mood' => $draft->mood->value,
                    'action' => $draft->action,
                    'sfx_cue' => $draft->sfxCue,
                ]);

                // The structurer's attribution is persisted, not recomputed
                // later. Stripping a dialogue cue removes the name a text search
                // would have matched, so re-deriving it downstream would lose
                // the cast of every scene that had dialogue in it.
                $ids = array_values(array_filter(array_map(
                    fn (string $name) => $characters[$name]?->getKey(),
                    $draft->characterNames,
                )));

                if ($ids !== []) {
                    $scene->characters()->sync($ids);
                }
            }
        });

        // Warnings are the structurer telling the owner where it was unsure — an
        // empty cast, a scene list that hit the cap. Stored on the project so the
        // project page can show them, because a warning in a log file cannot
        // change a decision: the person who needs to know the cast is empty is
        // looking at the page, not at storage/logs. Logged as well, for whoever
        // is reading logs rather than the UI.
        $project->forceFill([
            'structurer_warnings' => $breakdown->warnings === [] ? null : $breakdown->warnings,
        ])->save();

        foreach ($breakdown->warnings as $warning) {
            Log::info('Script structurer warning', [
                'project_id' => $project->getKey(),
                'provider' => $structurer->providerName(),
                'warning' => $warning,
            ]);
        }

        $project->refresh();
        $stateMachine->advanceTo($project, ProjectStatus::ScriptReady);
    }

    public function failed(Throwable $e): void
    {
        $project = Project::find($this->projectId);

        // Leave the project where the owner can retry from, rather than stranded
        // in SCRIPTING with no way forward.
        $project?->forceFill(['status' => ProjectStatus::Draft])->save();
    }
}
