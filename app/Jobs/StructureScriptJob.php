<?php

namespace App\Jobs;

use App\Contracts\ScriptStructurer;
use App\Enums\ProjectStatus;
use App\Models\Project;
use App\Services\Pipeline\ProjectStateMachine;
use Illuminate\Support\Facades\DB;
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

        $breakdown = $structurer->structure($project->script, $project->language);

        DB::transaction(function () use ($project, $breakdown) {
            // Characters first: scenes reference them by name.
            $project->scenes()->delete();

            foreach ($breakdown->characters as $draft) {
                $project->characters()->firstOrCreate(
                    ['name' => $draft->name],
                    ['description' => $draft->description],
                );
            }

            foreach ($breakdown->scenes as $index => $draft) {
                $project->scenes()->create([
                    'sequence' => $index + 1,
                    'setting' => $draft->setting,
                    'narration' => $draft->narration,
                    'mood' => $draft->mood,
                    'action' => $draft->action,
                ]);
            }
        });

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
