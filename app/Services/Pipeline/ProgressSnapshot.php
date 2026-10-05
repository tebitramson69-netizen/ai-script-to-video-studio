<?php

namespace App\Services\Pipeline;

use App\Enums\AssetType;
use App\Enums\ProjectStatus;
use App\Enums\ShotStatus;
use App\Models\Project;
use Illuminate\Support\Facades\Cache;

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
    public const RECONCILER_HEARTBEAT_KEY = 'studio:reconciler:last_run_at';

    /**
     * How long a provider request may sit outstanding with no sweep before the
     * owner is told something is wrong.
     *
     * The sweep is scheduled every minute, so anything beyond a few minutes
     * means it is not running. Generous enough not to flap on a slow sweep;
     * short enough to catch the mistake before a whole project has been paid
     * for and abandoned.
     */
    public const RECONCILER_STALE_AFTER_SECONDS = 300;

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

        /**
         * Work is outstanding at the provider but nothing is collecting it.
         *
         * Almost always one cause: `php artisan schedule:work` is not running.
         * The generation has been submitted and billed, and without the sweep it
         * will never be fetched — so this must be loud rather than inferred from
         * a progress bar that never moves.
         */
        public bool $reconcilerStalled = false,
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
            reconcilerStalled: $outstanding > 0 && self::reconcilerIsStalled(),
        );
    }

    /**
     * Whether a reconciliation sweep has run recently enough to be trusted.
     *
     * A missing heartbeat counts as stalled. That is deliberate: a cleared cache
     * and a scheduler that was never started look identical from here, and of
     * the two possible mistakes — warning when all is well, or staying silent
     * while paid generations are abandoned — only one costs money.
     */
    protected static function reconcilerIsStalled(): bool
    {
        $lastRun = Cache::get(self::RECONCILER_HEARTBEAT_KEY);

        if (! is_numeric($lastRun)) {
            return true;
        }

        return (now()->timestamp - (int) $lastRun) > self::RECONCILER_STALE_AFTER_SECONDS;
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
            'reconciler_stalled' => $this->reconcilerStalled,
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

            // Included so the warning appears without a manual refresh. It is a
            // boolean that flips at most twice, so it cannot make an idle
            // project look busy — which is the reason no clock is in here.
            $this->reconcilerStalled,
        ])), 0, 16);
    }
}
