<?php

namespace App\Services\Retention;

use App\Enums\AssetType;
use App\Enums\ShotStatus;
use App\Models\Asset;
use App\Models\Project;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * NFR-7: "clips/finals are large — keep final .mp4 + locked references; allow
 * purging intermediate shot clips after successful export; show storage used."
 *
 * Only shot clips are ever purged. The exported video is the deliverable and the
 * locked character references are what make a re-render consistent with what was
 * already produced — deleting either would cost far more than the disk it saves.
 */
class RetentionManager
{
    /**
     * Why this project cannot be purged, or null if it can.
     *
     * Purging is only safe once the clips have actually been used for something
     * the owner is keeping.
     */
    public function purgeBlockedReason(Project $project): ?string
    {
        if ($project->finalAsset === null || $project->exported_at === null) {
            return 'Nothing has been exported yet, so the clips are still the only copy of this work.';
        }

        if (! $project->finalAsset->exists()) {
            return 'The exported video is missing from storage, so the clips are still needed.';
        }

        if ($project->hasStaleShots()) {
            return 'Some shots are stale and will need re-rendering; purging now would only mean paying to render them twice.';
        }

        if ($this->purgeableAssets($project)->isEmpty()) {
            return 'There are no intermediate clips left to purge.';
        }

        return null;
    }

    public function canPurge(Project $project): bool
    {
        return $this->purgeBlockedReason($project) === null;
    }

    /**
     * Delete this project's intermediate shot clips.
     *
     * The shots are marked Purged rather than left looking rendered: they can no
     * longer be assembled from, and pretending otherwise would produce an export
     * that fails deep inside FFmpeg instead of a clear message up front.
     *
     * @return int bytes reclaimed
     */
    public function purge(Project $project, bool $force = false): int
    {
        if (! $force && ($reason = $this->purgeBlockedReason($project)) !== null) {
            throw new RuntimeException($reason);
        }

        $assets = $this->purgeableAssets($project);
        $reclaimed = (int) $assets->sum('bytes');

        DB::transaction(function () use ($project, $assets) {
            $assetIds = $assets->pluck('id')->all();

            $project->shots()
                ->whereIn('asset_id', $assetIds)
                ->update(['status' => ShotStatus::Purged->value]);

            // Deleting the model (rather than the query) fires the `deleted`
            // event that removes the file from disk.
            $assets->each->delete();
        });

        return $reclaimed;
    }

    /**
     * @return Collection<int, Asset>
     */
    public function purgeableAssets(Project $project)
    {
        return $project->assets()
            ->where('type', AssetType::ShotClip)
            ->get();
    }

    public function humanBytes(int $bytes): string
    {
        if ($bytes <= 0) {
            return '0 B';
        }

        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $power = min((int) floor(log($bytes, 1024)), count($units) - 1);

        return round($bytes / (1024 ** $power), $power === 0 ? 0 : 1).' '.$units[$power];
    }
}
