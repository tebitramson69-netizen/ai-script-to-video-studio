<?php

namespace App\Services\Pipeline;

use App\Contracts\Data\GeneratedMedia;
use App\Enums\AssetType;
use App\Models\Asset;
use App\Models\Project;
use App\Models\Scene;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * The single path from "a provider returned a file" to "the project owns an
 * asset and has been billed for it".
 *
 * Every generation goes through here, which is what makes NFR-5 (observability)
 * and the §11 cost rollup true by construction rather than by remembering: it is
 * not possible to add an asset without also writing its usage record.
 */
class AssetRecorder
{
    /**
     * @param  string  $operation  e.g. 'video.clip', 'speech.narration' — matches usage_records.operation
     * @param  Scene|null  $scene  the scene this asset belongs to, for the assets that belong to one.
     *                             Narration and music cover the whole video and leave this null; a
     *                             sound effect sets it, because the assembler has to know where on
     *                             the timeline to place it.
     */
    public function record(
        Project $project,
        AssetType $type,
        GeneratedMedia $media,
        string $operation,
        string $provider,
        ?int $durationMs = null,
        ?Scene $scene = null,
    ): Asset {
        $disk = config('studio.disk', 'local');
        $extension = pathinfo($media->path, PATHINFO_EXTENSION) ?: 'bin';
        $path = sprintf('%s/%s/%s.%s', $project->storageDirectory(), $type->value, Str::uuid(), $extension);

        $stream = fopen($media->path, 'rb');
        if ($stream === false) {
            throw new \RuntimeException("Generated file is unreadable: {$media->path}");
        }

        try {
            Storage::disk($disk)->put($path, $stream);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        $bytes = @filesize($media->path) ?: null;

        // The provider wrote to a temp file; once it is in storage that copy is
        // just clutter in the system temp directory.
        @unlink($media->path);

        return DB::transaction(function () use (
            $project, $type, $media, $operation, $provider, $durationMs, $disk, $path, $bytes, $scene
        ) {
            $asset = $project->assets()->create([
                'scene_id' => $scene?->getKey(),
                'type' => $type,
                'disk' => $disk,
                'path' => $path,
                'mime' => $media->mime,
                'bytes' => $bytes,
                'duration_seconds' => $media->durationSeconds,
                'model' => $media->model,
                'cost_usd' => $media->costUsd,
                'meta' => $media->meta,
            ]);

            $project->usageRecords()->create([
                'asset_id' => $asset->getKey(),
                'provider' => $provider,
                'model' => $media->model,
                'operation' => $operation,
                'units' => $this->unitsFor($operation, $media),
                'unit' => $this->unitFor($operation),
                'cost_usd' => $media->costUsd,
                'duration_ms' => $durationMs,
                'outcome' => 'succeeded',
                'meta' => $media->meta,
            ]);

            return $asset;
        });
    }

    /**
     * Record a call that cost money but produced nothing usable, so failures
     * still show up in the project's spend. A provider that charges for a
     * rejected generation is not hypothetical.
     */
    public function recordFailure(
        Project $project,
        string $operation,
        string $provider,
        string $message,
        float $costUsd = 0.0,
        ?string $model = null,
    ): void {
        $project->usageRecords()->create([
            'provider' => $provider,
            'model' => $model,
            'operation' => $operation,
            'units' => 0,
            'cost_usd' => $costUsd,
            'outcome' => 'failed',
            'meta' => ['error' => Str::limit($message, 1000)],
        ]);
    }

    protected function unitsFor(string $operation, GeneratedMedia $media): float
    {
        return match (true) {
            str_starts_with($operation, 'video.') => (float) ($media->durationSeconds ?? 0),
            str_starts_with($operation, 'speech.') => (float) ($media->meta['characters'] ?? 0),
            str_starts_with($operation, 'music.') => round((float) ($media->durationSeconds ?? 0) / 60, 4),
            default => 1.0,
        };
    }

    protected function unitFor(string $operation): string
    {
        return match (true) {
            str_starts_with($operation, 'video.') => 'seconds',
            str_starts_with($operation, 'speech.') => 'characters',
            str_starts_with($operation, 'music.') => 'minutes',
            default => 'calls',
        };
    }
}
