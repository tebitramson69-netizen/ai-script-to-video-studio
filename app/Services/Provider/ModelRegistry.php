<?php

namespace App\Services\Provider;

use App\Contracts\Data\ModelCapabilities;
use App\Contracts\Data\SpeechModelCapabilities;
use App\Enums\AspectRatio;
use App\Enums\GenerationMode;
use App\Models\Project;
use App\Models\Shot;
use InvalidArgumentException;

/**
 * Resolves model capabilities from `config/studio.video_models`.
 *
 * Exists so that the question "what can this model actually do?" has exactly
 * one answer, reachable from a form request, a job, or a Blade view alike —
 * rather than each of them reading config in its own slightly different way.
 */
class ModelRegistry
{
    /** @var array<string, ModelCapabilities> */
    protected array $cache = [];

    /** @var array<string, SpeechModelCapabilities> */
    protected array $speechCache = [];

    public function video(string $key): ModelCapabilities
    {
        if (isset($this->cache[$key])) {
            return $this->cache[$key];
        }

        $config = config("studio.video_models.{$key}");

        if (! is_array($config)) {
            throw new InvalidArgumentException(
                "Unknown video model '{$key}'. Registered: ".implode(', ', $this->availableKeys()).'. '.
                'Note that model keys cannot contain dots — config() would read them as nested paths.'
            );
        }

        return $this->cache[$key] = ModelCapabilities::fromConfig($key, $config);
    }

    public function defaultVideo(): ModelCapabilities
    {
        return $this->video((string) config('studio.default_video_model', 'fake'));
    }

    /**
     * The model a project renders on: its own choice, or the configured default.
     */
    public function forProject(Project $project): ModelCapabilities
    {
        return $this->video($project->video_model ?: (string) config('studio.default_video_model', 'fake'));
    }

    /**
     * The companion model for shots that have a locked character reference,
     * when the project has chosen one.
     *
     * Null means one model renders everything — which is the behaviour this
     * system had before per-shot selection existed, and remains the default.
     */
    public function imageModelForProject(Project $project): ?ModelCapabilities
    {
        $key = trim((string) $project->video_model_i2v);

        if ($key === '') {
            return null;
        }

        $capabilities = $this->video($key);

        // A companion that cannot do image-to-video is not a companion. Rather
        // than fail the render, fall back to the primary: the owner gets the
        // single-model behaviour they had before, and degradationWarnings()
        // tells them the pairing is not doing anything.
        return $capabilities->supportsMode(GenerationMode::ImageToVideo)
            ? $capabilities
            : null;
    }

    /**
     * Which model renders a shot, given whether it has a reference to start from.
     *
     * This is the whole of per-shot selection. A script contains establishing
     * shots and character shots, and no single model serves both: an
     * image-to-video endpoint cannot render an empty street, and a
     * text-to-video one cannot hold a character's face between cuts. Choosing
     * per shot is what lets one project do both.
     *
     * The fallbacks are deliberate and both err toward rendering rather than
     * failing: a referenced shot with no companion configured renders on the
     * primary (losing consistency, which degradationWarnings() reports), and an
     * unreferenced shot renders on whichever of the two can do text-to-video.
     */
    public function resolveForShot(Project $project, bool $hasLockedReference): ModelCapabilities
    {
        $primary = $this->forProject($project);
        $companion = $this->imageModelForProject($project);

        if ($hasLockedReference) {
            // Prefer whichever can actually use the reference. If the primary
            // already does image-to-video there is nothing to switch to.
            if ($primary->supportsMode(GenerationMode::ImageToVideo)) {
                return $primary;
            }

            return $companion ?? $primary;
        }

        if ($primary->supportsMode(GenerationMode::TextToVideo)) {
            return $primary;
        }

        // The primary is image-to-video only and this shot has nothing to start
        // from. The companion is the last chance; if it cannot do
        // text-to-video either, return the primary so the refusal names the
        // model the owner actually chose.
        return $companion !== null && $companion->supportsMode(GenerationMode::TextToVideo)
            ? $companion
            : $primary;
    }

    /**
     * The model a shot was planned on.
     *
     * Read from the shot rather than re-resolved, so a clip is always costed
     * and rendered against the model the timing engine planned its duration
     * for. Re-resolving here would let a shot planned at 8 seconds be rendered
     * by a model whose ladder is 5 or 10 (NFR-5 reproducibility).
     */
    public function forShot(Shot $shot): ModelCapabilities
    {
        $key = trim((string) $shot->model);

        // Authoritative only if it is one of the models this project chose.
        //
        // Anything else is a stale row, not a third model: a shot planned
        // before the pin changed, or seeded by a factory. Honouring it would
        // let a project pinned to an expensive model be costed at whatever key
        // happens to sit in that column — and a zero-cost 'fake' would wave an
        // unaffordable run straight past the budget cap (FR-11, NFR-4).
        foreach ($this->modelsForProject($shot->project) as $candidate) {
            if ($candidate->key === $key) {
                return $candidate;
            }
        }

        return $this->resolveForShot($shot->project, $shot->hasLockedReference());
    }

    /**
     * Every model this project may render on — one or two.
     *
     * Compatibility checks run over all of them, because a ratio the companion
     * cannot produce fails just as hard as one the primary cannot.
     *
     * @return list<ModelCapabilities>
     */
    public function modelsForProject(Project $project): array
    {
        $companion = $this->imageModelForProject($project);
        $primary = $this->forProject($project);

        return $companion === null || $companion->key === $primary->key
            ? [$primary]
            : [$primary, $companion];
    }

    /**
     * Capabilities of one text-to-speech model.
     */
    public function speech(string $key): SpeechModelCapabilities
    {
        if (isset($this->speechCache[$key])) {
            return $this->speechCache[$key];
        }

        $config = config("studio.speech_models.{$key}");

        if (! is_array($config)) {
            throw new InvalidArgumentException(
                "Unknown speech model '{$key}'. Registered: ".implode(', ', $this->availableSpeechKeys()).'. '.
                'Note that model keys cannot contain dots — config() would read them as nested paths.'
            );
        }

        return $this->speechCache[$key] = SpeechModelCapabilities::fromConfig($key, $config);
    }

    public function defaultSpeech(): SpeechModelCapabilities
    {
        return $this->speech((string) config('studio.default_speech_model', 'fake'));
    }

    /**
     * @return list<string>
     */
    public function availableSpeechKeys(): array
    {
        return array_keys((array) config('studio.speech_models', []));
    }

    /**
     * @return list<string>
     */
    public function availableKeys(): array
    {
        return array_keys((array) config('studio.video_models', []));
    }

    /**
     * @return array<string, ModelCapabilities>
     */
    public function allVideo(): array
    {
        return array_reduce(
            $this->availableKeys(),
            function (array $carry, string $key) {
                $carry[$key] = $this->video($key);

                return $carry;
            },
            [],
        );
    }

    /**
     * Aspect ratios the given model can actually produce.
     *
     * Drives the project-creation form, so the owner is never offered a choice
     * that cannot be rendered. Veo 3.1, for one, has no 1:1.
     *
     * @return list<AspectRatio>
     */
    public function aspectRatiosFor(?ModelCapabilities $capabilities = null): array
    {
        $capabilities ??= $this->defaultVideo();

        return array_values(array_filter(
            AspectRatio::cases(),
            fn (AspectRatio $ratio) => $capabilities->supportsAspectRatio($ratio),
        ));
    }

    /**
     * Why this project cannot be rendered on its model, or null if it can.
     *
     * Checked before shots are planned rather than at render time: discovering
     * an unsupported aspect ratio after paying for the first clip is the
     * expensive way to learn it.
     */
    public function incompatibilityReason(Project $project): ?string
    {
        // Every model the project may render on, not just the primary. A ratio
        // the companion cannot produce fails just as hard, and it would fail
        // halfway through a paid run rather than before it.
        foreach ($this->modelsForProject($project) as $capabilities) {
            if ($capabilities->supportsAspectRatio($project->aspect_ratio)) {
                continue;
            }

            $supported = implode(', ', array_map(
                fn (AspectRatio $r) => $r->value,
                $this->aspectRatiosFor($capabilities),
            ));

            return sprintf(
                '%s cannot produce %s video. It supports %s. '.
                'Choose a different model, or recreate the project at a supported ratio.',
                $capabilities->label,
                $project->aspect_ratio->value,
                $supported,
            );
        }

        return null;
    }

    public function isCompatible(Project $project): bool
    {
        return $this->incompatibilityReason($project) === null;
    }

    /**
     * Capability gaps that do not stop the render but quietly change what comes
     * out of it.
     *
     * Distinct from incompatibilityReason(): those refuse the run, these let it
     * proceed on terms the owner needs to have agreed to. The one that matters
     * today is a text-to-video-only model — the pipeline degrades gracefully by
     * dropping the character reference and rendering from the prompt alone,
     * which is right for one odd shot and wrong for every shot in the video.
     * Silent graceful degradation of the PRD's second goal, paid for at full
     * price, is worse than a warning.
     *
     * @return list<string>
     */
    public function degradationWarnings(Project $project): array
    {
        $capabilities = $this->forProject($project);
        $models = $this->modelsForProject($project);
        $warnings = [];

        $canDo = fn (GenerationMode $mode) => array_reduce(
            $models,
            fn (bool $carry, ModelCapabilities $m) => $carry || $m->supportsMode($mode),
            false,
        );

        $lockedCharacters = $project->characters()
            ->whereNotNull('canonical_reference_asset_id')
            ->count();

        // Asked of the pair, not the primary. A text-to-video primary with an
        // image-to-video companion covers this case completely, and warning
        // about it anyway would train the owner to ignore the warnings that
        // still mean something.
        if ($lockedCharacters > 0 && ! $canDo(GenerationMode::ImageToVideo)) {
            $warnings[] = sprintf(
                '%s is text-to-video only, so the %d locked character reference(s) cannot be '.
                'used as a starting frame. Shots will render from their prompt alone and '.
                'characters will not stay consistent between them (PRD G2, FR-6). '.
                'Switch to an image-to-video model before rendering, or accept the drift.',
                $capabilities->label,
                $lockedCharacters,
            );
        }

        // The mirror image, and the more serious of the two: a text-to-video-only
        // model degrades (characters drift), but an image-to-video-only model
        // cannot render an uncharactered shot at all. Counted here so the number
        // is concrete before anything is spent, rather than arriving later as N
        // failed shots.
        if (! $canDo(GenerationMode::TextToVideo)) {
            $unrenderable = $project->shots()
                ->whereDoesntHave('characters', fn ($q) => $q->whereNotNull('canonical_reference_asset_id'))
                ->count();

            if ($unrenderable > 0) {
                $warnings[] = sprintf(
                    '%s is image-to-video only and no text-to-video companion is set, so '.
                    'the %d shot(s) with no locked character reference cannot be rendered. '.
                    'Lock a character onto them, or choose a companion model that does '.
                    'text-to-video.',
                    $capabilities->label,
                    $unrenderable,
                );
            }
        }

        // A companion that cannot do image-to-video was silently ignored by
        // imageModelForProject(). Saying so beats leaving the owner believing
        // their character shots are being handled.
        $chosenCompanion = trim((string) $project->video_model_i2v);

        if ($chosenCompanion !== '' && $this->imageModelForProject($project) === null) {
            $warnings[] = sprintf(
                'The companion model "%s" cannot do image-to-video, so it is being ignored '.
                'and every shot renders on %s.',
                $chosenCompanion,
                $capabilities->label,
            );
        }

        if ($capabilities->emitsNativeAudio && ! $capabilities->supportsNativeAudioToggle) {
            $warnings[] = sprintf(
                '%s generates its own audio and offers no way to turn it off, so the clip '.
                'is paid for with a soundtrack that assembly then discards (FR-14).',
                $capabilities->label,
            );
        }

        return $warnings;
    }
}
