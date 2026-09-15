<?php

namespace App\Jobs;

use App\Enums\ProjectStatus;
use App\Models\Project;
use App\Services\Pipeline\ProjectStateMachine;

/**
 * Tail of the audio chain: narration and music are both done, so the project has
 * reached VOICE_READY.
 *
 * A separate job rather than a line at the end of GenerateMusicJob, because the
 * chain must only advance when *both* audio stages succeeded — and because the
 * chain is where that fact is known.
 */
class FinalizeAudioJob extends StudioJob
{
    public int $tries = 1;

    public function __construct(public int $projectId) {}

    public function handle(ProjectStateMachine $stateMachine): void
    {
        $project = Project::find($this->projectId);

        if ($project === null || $project->narrationAsset() === null) {
            return;
        }

        $stateMachine->advanceTo($project, ProjectStatus::VoiceReady);
    }
}
