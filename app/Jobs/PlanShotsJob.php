<?php

namespace App\Jobs;

use App\Enums\ProjectStatus;
use App\Enums\ShotStatus;
use App\Models\Character;
use App\Models\Project;
use App\Models\Scene;
use App\Services\Pipeline\ProjectStateMachine;
use App\Services\Provider\ModelRegistry;
use App\Services\Timing\ShotPlanner;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Stage 3 (PRD §8): turn scenes into renderable shots with prompts and target
 * durations (FR-8, FR-16, FR-17).
 *
 * Costs nothing — it is pure planning. That is deliberate: the owner sees the
 * full shot list and its estimated price before a single clip is bought, and
 * this job needs no provider driver at all, only the model's declared limits.
 */
class PlanShotsJob extends StudioJob
{
    public function __construct(public int $projectId) {}

    public function handle(
        ShotPlanner $planner,
        ProjectStateMachine $stateMachine,
    ): void {
        $project = Project::with('scenes', 'characters')->find($this->projectId);

        if ($project === null || $project->scenes->isEmpty()) {
            return;
        }

        // Planning is free, rendering is not. Refuse here rather than letting
        // the owner queue a run whose every shot the model will reject.
        $incompatibility = app(ModelRegistry::class)->incompatibilityReason($project);

        if ($incompatibility !== null) {
            throw new RuntimeException("Cannot plan shots: {$incompatibility}");
        }

        // The project's pinned model decides the limits, not whichever driver
        // happens to be bound. Planning against the driver would buy clip
        // lengths the chosen model cannot render.
        $capabilities = app(ModelRegistry::class)->forProject($project);
        $clipLengths = $capabilities->clipLengths;
        $maxShots = (int) config('studio.limits.max_shots', 60);

        DB::transaction(function () use ($project, $planner, $clipLengths, $capabilities, $maxShots) {
            // Planning replaces the shot list. Any rendered clips are detached
            // rather than deleted — the Asset rows survive, so nothing the owner
            // has already paid for is destroyed by a re-plan.
            $project->shots()->delete();

            $sequence = 1;

            foreach ($project->scenes as $scene) {
                foreach ($planner->planScene($scene, $clipLengths) as $plan) {
                    if ($sequence > $maxShots) {
                        break 2;
                    }

                    $shot = $project->shots()->create([
                        'scene_id' => $scene->getKey(),
                        'sequence' => $sequence++,
                        'prompt' => $this->buildPrompt($scene, $project),
                        'narration_segment' => $plan->narrationSegment,
                        'model' => $capabilities->key,
                        'seed' => random_int(1, 2_000_000_000),
                        'target_duration_seconds' => $plan->targetDurationSeconds,

                        // Estimated for now; replaced by the measured TTS
                        // duration once narration is synthesised.
                        'narration_duration_seconds' => $plan->narrationDurationSeconds,

                        'status' => ShotStatus::Pending,
                    ]);

                    // FR-17: shots split from one scene share its cast, so the
                    // same locked reference drives every one of them.
                    $shot->characters()->sync(
                        $this->charactersInScene($scene, $project)->pluck('id')->all()
                    );
                }
            }
        });

        $project->refresh();
        $stateMachine->advanceTo($project, ProjectStatus::ScenesReady);
    }

    /**
     * FR-8: the shot prompt carries the setting, the action, the mood and the
     * cast. The locked reference image is attached separately, at render time.
     */
    protected function buildPrompt(Scene $scene, Project $project): string
    {
        $cast = $this->charactersInScene($scene, $project)->pluck('name')->implode(', ');

        $parts = array_filter([
            $scene->setting,
            $cast !== '' ? "featuring {$cast}" : null,
            trim((string) $scene->action) ?: null,
            $scene->mood ? "{$scene->mood} mood" : null,
            'cinematic, natural camera movement',
        ]);

        return implode('. ', $parts).'.';
    }

    /**
     * @return Collection<int, Character>
     */
    protected function charactersInScene(Scene $scene, Project $project)
    {
        $haystack = $scene->narration.' '.$scene->action.' '.$scene->setting;

        return $project->characters->filter(
            fn ($character) => (bool) preg_match(
                '/\b'.preg_quote($character->name, '/').'\b/iu',
                $haystack,
            ),
        );
    }
}
