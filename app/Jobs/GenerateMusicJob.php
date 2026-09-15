<?php

namespace App\Jobs;

use App\Contracts\Data\MusicRequest;
use App\Contracts\MusicGenerator;
use App\Enums\AssetType;
use App\Models\Asset;
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
    /**
     * @param  bool  $force  regenerate even if a usable track already exists
     *                       (FR-15). Without it the stage is a no-op when the
     *                       existing track still fits the timeline.
     */
    public function __construct(
        public int $projectId,
        public bool $force = false,
    ) {}

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

        $existing = $project->musicAsset();

        // NFR-3: a track that already covers this timeline is not worth paying
        // for twice. A second press of the audio button must cost nothing.
        if (! $this->force && $existing !== null && $this->stillFits($existing, $duration)) {
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

        $track = $recorder->record(
            project: $project,
            type: AssetType::Music,
            media: $media,
            operation: 'music.track',
            provider: $music->providerName(),
            durationMs: (int) ((microtime(true) - $startedAt) * 1000),
        );

        // Only one music track is ever in play (Phase 1). Anything it replaced
        // is dead weight on disk; its usage record survives the deletion.
        $project->assets()
            ->where('type', AssetType::Music)
            ->whereKeyNot($track->getKey())
            ->get()
            ->each->delete();
    }

    /**
     * The timeline moves when narration is re-measured or a scene is edited. A
     * track more than a second off no longer covers the video, and one much
     * longer than it was bought for is simply the wrong track.
     */
    protected function stillFits(Asset $music, float $requiredSeconds): bool
    {
        if (! $music->exists()) {
            return false;
        }

        return abs((float) $music->duration_seconds - $requiredSeconds) <= 1.0;
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
