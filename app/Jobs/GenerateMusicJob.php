<?php

namespace App\Jobs;

use App\Contracts\Data\MusicRequest;
use App\Contracts\MusicGenerator;
use App\Enums\AssetType;
use App\Models\Project;
use App\Services\Cost\CostEstimator;
use App\Services\Pipeline\AssetRecorder;
use Throwable;

/**
 * Stage 5b (PRD §8): one background music track matched to the story's mood
 * (FR-13, Phase 1).
 */
class GenerateMusicJob extends StudioJob
{
    public function __construct(public int $projectId) {}

    public function handle(
        MusicGenerator $music,
        AssetRecorder $recorder,
        CostEstimator $costs,
    ): void {
        $project = Project::find($this->projectId);

        if ($project === null) {
            return;
        }

        // Match the music to the timeline the shots actually define, not to the
        // planned lengths — the assembler trims clips to narration (FR-18), so
        // planned totals would buy music that overruns the finished video.
        $duration = (float) $project->shots()
            ->get()
            ->sum(fn ($shot) => $shot->timelineDurationSeconds());

        if ($duration <= 0) {
            return;
        }

        $mood = $project->music_mood ?: $this->dominantMood($project);

        $costs->assertCanSpend(
            $project,
            ($duration / 60) * $music->costPerMinuteUsd(),
            'Music',
        );

        $startedAt = microtime(true);

        $media = $music->generate(new MusicRequest(mood: $mood, durationSeconds: $duration));

        $recorder->record(
            project: $project,
            type: AssetType::Music,
            media: $media,
            operation: 'music.track',
            provider: $music->providerName(),
            durationMs: (int) ((microtime(true) - $startedAt) * 1000),
        );
    }

    /**
     * The mood that appears in the most scenes. One track has to serve the whole
     * video in Phase 1, so the majority mood is the least-wrong choice.
     */
    protected function dominantMood(Project $project): string
    {
        $moods = $project->scenes()->pluck('mood')->filter()->all();

        if ($moods === []) {
            return 'neutral';
        }

        $counts = array_count_values($moods);
        arsort($counts);

        return (string) array_key_first($counts);
    }

    public function failed(Throwable $e): void
    {
        $project = Project::find($this->projectId);

        if ($project !== null) {
            app(AssetRecorder::class)->recordFailure(
                $project,
                'music.track',
                app(MusicGenerator::class)->providerName(),
                $e->getMessage(),
            );
        }
    }
}
