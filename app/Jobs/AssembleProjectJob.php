<?php

namespace App\Jobs;

use App\Contracts\Data\GeneratedMedia;
use App\Enums\AssetType;
use App\Enums\ProjectStatus;
use App\Models\Project;
use App\Services\Media\FfmpegRunner;
use App\Services\Media\VideoAssembler;
use App\Services\Pipeline\AssetRecorder;
use App\Services\Pipeline\ProjectStateMachine;
use RuntimeException;
use Throwable;

/**
 * Stage 6 (PRD §8): assemble everything into one .mp4 (FR-19 – FR-21).
 *
 * Assembly is local FFmpeg work, so it costs nothing and is safe to re-run. It
 * refuses to run while any shot is stale — PRD §8 blocks export in that state,
 * and exporting a video with a knowingly outdated shot in it is worse than not
 * exporting at all.
 */
class AssembleProjectJob extends StudioJob
{
    /** Local work: a transient failure here is an environment problem, not a provider one. */
    public int $tries = 2;

    public function __construct(public int $projectId) {}

    public function handle(
        VideoAssembler $assembler,
        AssetRecorder $recorder,
        ProjectStateMachine $stateMachine,
        FfmpegRunner $ffmpeg,
    ): void {
        $project = Project::find($this->projectId);

        if ($project === null) {
            return;
        }

        $blocked = $stateMachine->exportBlockedReason($project);

        if ($blocked !== null) {
            throw new RuntimeException("Cannot export: {$blocked}");
        }

        $path = $assembler->assemble($project);

        $asset = $recorder->record(
            project: $project,
            type: AssetType::FinalVideo,
            media: new GeneratedMedia(
                path: $path,
                mime: 'video/mp4',
                model: 'ffmpeg',
                costUsd: 0.0,
                durationSeconds: $ffmpeg->durationSeconds($path),
                meta: [
                    'shots' => $project->shots()->count(),
                    'aspect_ratio' => $project->aspect_ratio->value,
                ],
            ),
            operation: 'assembly.export',
            provider: 'local',
        );

        $project->forceFill([
            'final_asset_id' => $asset->getKey(),
            'exported_at' => now(),
        ])->save();

        $stateMachine->advanceTo($project->fresh(), ProjectStatus::ExportReady);
    }

    public function failed(Throwable $e): void
    {
        $project = Project::find($this->projectId);

        if ($project !== null) {
            app(AssetRecorder::class)->recordFailure(
                $project,
                'assembly.export',
                'local',
                $e->getMessage(),
            );
        }
    }
}
