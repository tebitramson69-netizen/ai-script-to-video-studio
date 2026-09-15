<?php

namespace App\Jobs;

use App\Contracts\Data\GeneratedMedia;
use App\Contracts\Data\SpeechRequest;
use App\Contracts\SpeechSynthesizer;
use App\Enums\AssetType;
use App\Models\Project;
use App\Services\Cost\CostEstimator;
use App\Services\Media\FfmpegRunner;
use App\Services\Pipeline\AssetRecorder;
use Illuminate\Support\Facades\File;
use Throwable;

/**
 * Stage 5a (PRD §8): narration (FR-12).
 *
 * Synthesised per shot, then concatenated into the single project narration
 * track Phase 1 calls for. Doing it per shot is what makes FR-16 real: each
 * shot learns its *measured* narration length, which replaces the
 * words-per-minute estimate and drives the trim/hold at assembly (FR-18).
 *
 * Synthesising one blob for the whole video would be cheaper in API calls and
 * would make per-scene timing unknowable.
 */
class GenerateNarrationJob extends StudioJob
{
    public function __construct(public int $projectId) {}

    public function handle(
        SpeechSynthesizer $speech,
        AssetRecorder $recorder,
        CostEstimator $costs,
        FfmpegRunner $ffmpeg,
    ): void {
        $project = Project::with('shots')->find($this->projectId);

        if ($project === null) {
            return;
        }

        $shots = $project->shots()->orderBy('sequence')->get();

        if ($shots->isEmpty()) {
            return;
        }

        $totalCharacters = $shots->sum(fn ($shot) => mb_strlen((string) $shot->narration_segment));
        $costs->assertCanSpend(
            $project,
            ($totalCharacters / 1000) * $speech->costPer1kCharactersUsd(),
            'Narration',
        );

        $segmentPaths = [];

        foreach ($shots as $shot) {
            $text = trim((string) $shot->narration_segment);

            // A silent visual beat. Keep its planned length and contribute
            // silence of that length to the narration track so the following
            // shots stay in sync.
            if ($text === '') {
                $segmentPaths[] = $this->silence($ffmpeg, (float) $shot->target_duration_seconds);

                continue;
            }

            $startedAt = microtime(true);

            $media = $speech->synthesize(new SpeechRequest(
                text: $text,
                language: $project->language,
                voiceId: $project->voice_id,
            ));

            $asset = $recorder->record(
                project: $project,
                type: AssetType::Narration,
                media: $media,
                operation: 'speech.narration',
                provider: $speech->providerName(),
                durationMs: (int) ((microtime(true) - $startedAt) * 1000),
            );

            // The measured duration is now the master clock for this shot.
            $shot->forceFill([
                'narration_asset_id' => $asset->getKey(),
                'narration_duration_seconds' => $media->durationSeconds,
            ])->save();

            $segmentPaths[] = $asset->absolutePath();
        }

        $this->storeCombinedTrack($project, $recorder, $ffmpeg, $segmentPaths, $speech->providerName());
    }

    /**
     * Concatenate the per-shot segments into the project's narration track. Its
     * segment boundaries line up exactly with the shot cuts, because they are
     * the same durations the assembler trims each clip to.
     *
     * @param  list<string>  $segmentPaths
     */
    protected function storeCombinedTrack(
        Project $project,
        AssetRecorder $recorder,
        FfmpegRunner $ffmpeg,
        array $segmentPaths,
        string $provider,
    ): void {
        if ($segmentPaths === []) {
            return;
        }

        $workDir = sys_get_temp_dir().'/studio_vo_'.$project->getKey().'_'.bin2hex(random_bytes(4));
        File::ensureDirectoryExists($workDir);

        try {
            $listFile = $workDir.'/segments.txt';
            File::put($listFile, implode("\n", array_map(
                fn (string $p) => "file '".str_replace("'", "'\\''", $p)."'",
                $segmentPaths,
            ))."\n");

            $combined = $workDir.'/narration.wav';

            $ffmpeg->run([
                '-f', 'concat', '-safe', '0', '-i', $listFile,
                '-ac', '1', '-ar', '44100',
                $combined,
            ]);

            // Zero cost: the segments were already billed individually. Recording
            // it again would double-count the project's spend.
            $recorder->record(
                project: $project,
                type: AssetType::NarrationTrack,
                media: new GeneratedMedia(
                    path: $combined,
                    mime: 'audio/wav',
                    model: 'concat',
                    costUsd: 0.0,
                    durationSeconds: $ffmpeg->durationSeconds($combined),
                    meta: ['combined' => true, 'segments' => count($segmentPaths)],
                ),
                operation: 'speech.narration.combined',
                provider: $provider,
            );
        } finally {
            File::deleteDirectory($workDir);
        }
    }

    protected function silence(FfmpegRunner $ffmpeg, float $seconds): string
    {
        $path = tempnam(sys_get_temp_dir(), 'studio_sil_').'.wav';

        $ffmpeg->run([
            '-f', 'lavfi',
            '-i', 'anullsrc=channel_layout=mono:sample_rate=44100',
            '-t', (string) max(0.1, round($seconds, 3)),
            $path,
        ]);

        return $path;
    }

    public function failed(Throwable $e): void
    {
        $project = Project::find($this->projectId);

        if ($project !== null) {
            app(AssetRecorder::class)->recordFailure(
                $project,
                'speech.narration',
                app(SpeechSynthesizer::class)->providerName(),
                $e->getMessage(),
            );
        }
    }
}
