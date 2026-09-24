<?php

namespace App\Services\Pipeline;

use App\Enums\AssetType;
use App\Enums\ProjectStatus;
use App\Enums\ShotStatus;
use App\Models\Project;

/**
 * What a project is doing right now, small enough to poll.
 *
 * The pipeline runs on a queue, so a render that takes minutes leaves the page
 * showing whatever was true when it was served. Until now the only way to see
 * progress was to press refresh, which is a poor experience and an actively
 * misleading one: an owner who refreshes at the wrong moment sees "0 of 6
 * rendered" and reasonably concludes the stage failed.
 *
 * This is the whole payload behind that. Two properties matter more than the
 * numbers.
 *
 * `busy` decides whether the client polls at all. It is true when the database
 * can see work in progress — shots not yet terminal, or provider requests still
 * outstanding — so an idle project costs one request and then nothing.
 *
 * `fingerprint` decides whether the client does anything with the answer. It is
 * a hash of everything below, so "has this changed?" is a string comparison
 * rather than a diff, and it does not depend on HTTP caching behaving a
 * particular way.
 */
readonly class ProgressSnapshot
{
    /**
     * @param  array<string, int>  $shots  shot counts by our own labels, not the enum's
     * @param  array<string, bool|int>  $assets  which audio and video assets exist yet
     */
    private function __construct(
        public ProjectStatus $status,
        public bool $busy,
        public array $shots,
        public array $assets,
        public int $providerRequestsInFlight,
        public float $spentUsd,
        public float $budgetCapUsd,
        public ?string $exportBlockedReason,
    ) {}

    public static function for(Project $project, ?string $exportBlockedReason = null): self
    {
        // Counted in one query rather than one per state. This endpoint is polled,
        // so its cost is paid over and over — and every poll also holds PHP's
        // session file lock for its duration, which a form submission in another
        // tab would have to wait behind.
        $byStatus = $project->shots()
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $count = fn (ShotStatus ...$states) => array_sum(array_map(
            fn (ShotStatus $s) => (int) ($byStatus[$s->value] ?? 0),
            $states,
        ));

        $inFlight = $count(ShotStatus::Pending, ShotStatus::Queued, ShotStatus::Rendering);
        $outstanding = $project->providerRequests()->outstanding()->count();

        $assetCounts = $project->assets()
            ->selectRaw('type, count(*) as total')
            ->groupBy('type')
            ->pluck('total', 'type');

        return new self(
            status: $project->status,

            // Pending is included deliberately. A planned-but-unrendered shot is
            // not "work in progress" strictly speaking, but the owner who just
            // pressed Render is looking at exactly that state while the queue
            // picks the job up — and a poller that stopped there would go quiet
            // at the one moment it is wanted.
            busy: $inFlight > 0 || $outstanding > 0 || $project->status === ProjectStatus::Scripting,

            shots: [
                'total' => (int) $byStatus->sum(),
                'rendered' => $count(ShotStatus::Rendered),
                'in_flight' => $inFlight,
                'failed' => $count(ShotStatus::Failed),
                'stale' => $count(ShotStatus::Stale),
                'purged' => $count(ShotStatus::Purged),
            ],

            assets: [
                'narration' => (int) ($assetCounts[AssetType::NarrationTrack->value] ?? 0) > 0,
                'music' => (int) ($assetCounts[AssetType::Music->value] ?? 0) > 0,
                'sound_effects' => (int) ($assetCounts[AssetType::SoundEffect->value] ?? 0),
                'final' => $project->final_asset_id !== null,
            ],

            providerRequestsInFlight: $outstanding,
            spentUsd: round($project->spentUsd(), 4),
            budgetCapUsd: round((float) $project->budget_cap_usd, 2),
            exportBlockedReason: $exportBlockedReason,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'next_action' => $this->status->nextAction(),
            'busy' => $this->busy,
            'shots' => $this->shots,
            'assets' => $this->assets,
            'provider_requests_in_flight' => $this->providerRequestsInFlight,
            'spent_usd' => $this->spentUsd,
            'budget_cap_usd' => $this->budgetCapUsd,
            'export_blocked_reason' => $this->exportBlockedReason,
            'fingerprint' => $this->fingerprint(),
        ];
    }

    /**
     * A stable hash of everything the client would react to.
     *
     * Deliberately excludes any clock. A snapshot that changed every second
     * would make every poll look like progress, and the client would reload the
     * page on a project that is doing nothing.
     */
    public function fingerprint(): string
    {
        return substr(hash('sha256', json_encode([
            $this->status->value,
            $this->busy,
            $this->shots,
            $this->assets,
            $this->providerRequestsInFlight,
            $this->spentUsd,
            $this->exportBlockedReason,
        ])), 0, 16);
    }
}
