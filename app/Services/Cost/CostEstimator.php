<?php

namespace App\Services\Cost;

use App\Contracts\Data\ModelCapabilities;
use App\Contracts\ImageGenerator;
use App\Contracts\MusicGenerator;
use App\Contracts\SoundEffectGenerator;
use App\Contracts\SpeechSynthesizer;
use App\Enums\ShotStatus;
use App\Exceptions\BudgetExceededException;
use App\Models\Project;
use App\Models\Scene;
use App\Models\Shot;
use App\Services\Provider\ModelRegistry;
use App\Services\Timing\NarrationEstimator;
use Illuminate\Support\Collection;

/**
 * Estimates what a run will cost, and enforces the hard per-project cap
 * (FR-11, NFR-4).
 *
 * Prices come from the model registry (video) and from the bound drivers
 * (image, speech, music) — never from a table in this class, so a model swap or
 * a driver swap re-prices the estimate automatically.
 *
 * Note that this no longer depends on a video driver at all: a project can be
 * planned and costed with no provider configured, which is what lets the owner
 * see a price before deciding whether to pay for one.
 */
class CostEstimator
{
    public function __construct(
        protected ImageGenerator $image,
        protected SpeechSynthesizer $speech,
        protected MusicGenerator $music,
        protected SoundEffectGenerator $soundEffects,
        protected NarrationEstimator $estimator,
        protected ModelRegistry $models,
    ) {}

    /**
     * Cost of rendering the shots that still need rendering, plus any audio that
     * does not exist yet. Work already paid for is excluded — re-running a stage
     * must not double-count (NFR-3).
     */
    public function estimateRemainingRun(Project $project): CostEstimate
    {
        $lineItems = [];

        // Grouped by the model each shot was planned on, because with per-shot
        // selection a project can carry two rates at once. Pricing the whole
        // run at the primary's rate would understate a project whose character
        // shots render on a more expensive image-to-video model — and an
        // understated estimate is one the cap waves through.
        //
        // Using the bound driver's rate instead of the planned model's would be
        // worse again: a zero-cost local fake would wave an unaffordable run
        // straight past the cap.
        $pending = $project->shots()
            ->whereIn('status', [
                ...ShotStatus::needingRenderValues(),
                ShotStatus::Queued->value,
            ])
            ->selectRaw('model, sum(target_duration_seconds) as seconds')
            ->groupBy('model')
            ->pluck('seconds', 'model');

        foreach ($pending as $modelKey => $seconds) {
            $seconds = (float) $seconds;

            if ($seconds <= 0) {
                continue;
            }

            $capabilities = $this->capabilitiesFor($project, (string) $modelKey);

            $label = $pending->count() > 1
                ? sprintf('Video clips on %s (%s)', $capabilities->label, $this->formatSeconds($seconds))
                : 'Video clips ('.$this->formatSeconds($seconds).')';

            $lineItems[$label] = $seconds * $capabilities->costPerSecondUsd();
        }

        $unlockedCharacters = $project->characters()->whereNull('canonical_reference_asset_id')->count();
        if ($unlockedCharacters > 0) {
            $candidates = (int) config('studio.image.candidates_per_character', 3);
            $lineItems["Character references ({$unlockedCharacters} × {$candidates} candidates)"] =
                $unlockedCharacters * $candidates * $this->image->costPerImageUsd();
        }

        if ($project->narrationAsset() === null) {
            $segments = $this->narrationSegments($project);

            if ($segments !== []) {
                $characters = array_sum(array_map(mb_strlen(...), $segments));
                $calls = count($segments);

                // Summed per request, because that is how it is billed. Summing
                // the characters first and dividing once under-charges by
                // roughly the shot count — measured 2026-10-08 as $0.0031
                // estimated against $0.10 actually charged for five calls.
                $lineItems[sprintf(
                    'Narration (%d call(s), %d characters)',
                    $calls,
                    $characters,
                )] = array_sum(array_map(
                    fn (string $text) => $this->speech->costForCharacters(mb_strlen($text)),
                    $segments,
                ));
            }
        }

        if ($project->musicAsset() === null) {
            $seconds = $this->estimatedRuntimeSeconds($project);
            if ($seconds > 0) {
                $lineItems['Music ('.$this->formatSeconds($seconds).')'] =
                    $this->music->costForSeconds($seconds);
            }
        }

        // Only the scenes that carry a cue and do not already have an effect.
        // Counting every scene would inflate the estimate on a script the
        // structurer found no ambience in — which is most of them — and an
        // estimate that is routinely too high is one the owner learns to ignore.
        // Outstanding only: a scene whose effect still matches its cue is not
        // re-bought. This used to ask whether the scene had ANY effect asset,
        // while the job asks whether the one it has still matches the cue - so
        // an edited cue was bought by the job and priced at $0.00 here.
        $pendingEffects = $this->cuedScenes($project, outstandingOnly: true);

        if ($pendingEffects->isNotEmpty()) {
            $lineItems[sprintf('Sound effects (%d scene(s))', $pendingEffects->count())] =
                $this->soundEffectsUsd($pendingEffects);
        }

        return new CostEstimate(
            lineItems: $lineItems,
            alreadySpentUsd: $project->spentUsd(),
            budgetCapUsd: (float) $project->budget_cap_usd,
        );
    }

    /**
     * Estimate first, spend second. Every job that costs money calls this.
     *
     * @throws BudgetExceededException
     */
    public function assertWithinBudget(Project $project): CostEstimate
    {
        $estimate = $this->estimateRemainingRun($project);

        if ($estimate->exceedsBudget()) {
            throw new BudgetExceededException($estimate);
        }

        return $estimate;
    }

    /**
     * Cheap guard for a single additional charge (one shot regeneration, one
     * narration re-run) where building the whole estimate would be overkill.
     *
     * @throws BudgetExceededException
     */
    public function assertCanSpend(Project $project, float $additionalUsd, string $label): CostEstimate
    {
        $estimate = new CostEstimate(
            lineItems: [$label => $additionalUsd],
            alreadySpentUsd: $project->spentUsd(),
            budgetCapUsd: (float) $project->budget_cap_usd,
        );

        if ($estimate->exceedsBudget()) {
            throw new BudgetExceededException($estimate);
        }

        return $estimate;
    }

    /*
    |--------------------------------------------------------------------------
    | Forced regeneration
    |--------------------------------------------------------------------------
    |
    | estimateRemainingRun() answers "what is left to do", and after a finished
    | run the answer is nothing. That is correct for the progress panel and
    | wrong for FR-15: a forced regeneration redoes work that already exists, so
    | gating it on the remaining-run estimate authorises a full re-synthesis it
    | priced at $0.00.
    |
    | These price the forced work instead. They deliberately mirror each job's
    | own selection rather than the estimator's — the job decides what it will
    | buy, so the gate has to ask the same question the job will.
    |
    */

    /**
     * Re-voicing every shot that has words. GenerateNarrationJob's
     * needsSynthesis() returns true for all of them when forced.
     */
    public function forcedNarrationUsd(Project $project): float
    {
        return array_sum(array_map(
            fn (string $text) => $this->speech->costForCharacters(mb_strlen($text)),
            $this->narrationSegments($project),
        ));
    }

    /**
     * One new bed at the current timeline length.
     */
    public function forcedMusicUsd(Project $project): float
    {
        $seconds = $this->estimatedRuntimeSeconds($project);

        return $seconds > 0 ? $this->music->costForSeconds($seconds) : 0.0;
    }

    /**
     * One new effect for every cued scene that has a timeline — which is
     * GenerateSoundEffectsJob::pendingScenes() with force set, minus the
     * stillCovered() filter it skips in that mode.
     */
    public function forcedSoundEffectsUsd(Project $project): float
    {
        return $this->soundEffectsUsd($this->cuedScenes($project, outstandingOnly: false));
    }

    /**
     * The scenes a sound-effect run will buy for, forced or not.
     *
     * Mirrors GenerateSoundEffectsJob::pendingScenes() through the same three
     * Scene methods it uses, so the gate and the job cannot answer differently.
     *
     * @return Collection<int, Scene>
     */
    protected function cuedScenes(Project $project, bool $outstandingOnly): Collection
    {
        return $project->scenes()
            ->with(['shots', 'assets'])
            ->cued()
            ->get()
            ->filter(fn (Scene $scene) => $scene->hasTimeline())
            ->filter(fn (Scene $scene) => ! $outstandingOnly || ! $scene->soundEffectIsCurrent());
    }

    /**
     * Priced at each scene's own length, because fal bills this endpoint in
     * seconds: a 3-second ambience and a 22-second one differ by 7x.
     *
     * @param  Collection<int, Scene>  $scenes
     */
    protected function soundEffectsUsd(Collection $scenes): float
    {
        return (float) $scenes->sum(
            fn (Scene $scene) => $this->soundEffects->costForSeconds($scene->timelineDurationSeconds()),
        );
    }

    /**
     * Total narration text across the project, in scene order.
     */
    protected function narrationText(Project $project): string
    {
        return trim(implode(' ', $project->scenes()->pluck('narration')->all()));
    }

    /**
     * One entry per synthesis request the narration stage will make.
     *
     * Mirrors GenerateNarrationJob: it voices one shot per request and skips a
     * shot with no words, which gets local silence rather than a paid call. So
     * the unit of cost is the shot, not the project.
     *
     * Before shots are planned there is nothing finer to go on, so scenes stand
     * in for them. That is the right proxy — the planner emits at least one
     * shot per scene — and it errs low only while the number is still a guess.
     *
     * @return list<string>
     */
    protected function narrationSegments(Project $project): array
    {
        $shots = $project->shots()->orderBy('sequence')->pluck('narration_segment');

        $source = $shots->isNotEmpty()
            ? $shots
            : $project->scenes()->orderBy('sequence')->pluck('narration');

        return $source
            ->map(fn ($text) => trim((string) $text))
            ->filter(fn (string $text) => $text !== '')
            ->values()
            ->all();
    }

    /**
     * How long the finished video is expected to run.
     *
     * The NARRATION-driven timeline (FR-18), not the sum of the clip lengths
     * bought from the model. Those two are equal only when no shot carries
     * slack, and the assembler trims every surplus second away, so quoting the
     * purchased seconds overstated a real five-scene export by better than 2x
     * — 25.0s promised against an 11.60s file.
     *
     * Deliberately routed through Shot::timelineDurationSeconds() rather than
     * repeating the fallback here: the assembler, GenerateMusicJob and the
     * end-to-end test all measure a shot that way, and a fourth definition is
     * a fourth thing to drift.
     *
     * Falls back to the narration estimate before any shot exists, so a cost
     * figure can still be shown on the scene-review screen.
     */
    public function estimatedRuntimeSeconds(Project $project): float
    {
        // loadMissing, not shots(): ProjectController::show() has already eager
        // loaded this relation, and going through the builder re-queried and
        // re-hydrated every Shot - three times per render once the surplus row
        // was added, for rows already sitting in memory.
        $planned = $project->loadMissing('shots')->timelineDurationSeconds();

        if ($planned > 0) {
            return $planned;
        }

        return $this->estimator->estimateSeconds($this->narrationText($project));
    }

    /**
     * Seconds of clip bought beyond what the narration needs, and therefore
     * trimmed off at assembly (FR-18).
     *
     * Surfaced rather than hidden. Once estimatedRuntimeSeconds() reports the
     * true timeline the overspend stops being visible as a discrepancy, and
     * money quietly spent on frames nobody will ever see is exactly what a cost
     * panel exists to show. ShotPlan::slackSeconds() already names the same
     * quantity per shot; this is the project total.
     */
    public function trimmedSurplusSeconds(Project $project): float
    {
        return round(
            $project->loadMissing('shots')->shots->sum(fn (Shot $shot) => $shot->slackSeconds()),
            2,
        );
    }

    /**
     * Capabilities for a model key read off a shot row.
     *
     * Falls back to the project's primary rather than throwing: the owner is
     * only looking at a cost preview here, and the render path resolves
     * properly and refuses there if it must.
     */
    protected function capabilitiesFor(Project $project, string $modelKey): ModelCapabilities
    {
        // Same rule as ModelRegistry::forShot(): a key that is not one of the
        // project's chosen models is a stale row, not a third model. Pricing it
        // at face value would let a shot carrying 'fake' be costed at zero on a
        // project pinned to something expensive.
        foreach ($this->models->modelsForProject($project) as $candidate) {
            if ($candidate->key === $modelKey) {
                return $candidate;
            }
        }

        return $this->models->forProject($project);
    }

    protected function formatSeconds(float $seconds): string
    {
        if ($seconds < 60) {
            return round($seconds).'s';
        }

        return floor($seconds / 60).'m '.round(fmod($seconds, 60)).'s';
    }
}
