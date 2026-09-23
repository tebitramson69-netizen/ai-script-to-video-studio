<?php

namespace App\Services\Provider;

use App\Contracts\Data\ModelCapabilities;
use App\Enums\AspectRatio;
use App\Enums\GenerationMode;
use App\Models\Project;
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
        $capabilities = $this->forProject($project);

        if (! $capabilities->supportsAspectRatio($project->aspect_ratio)) {
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
        $warnings = [];

        $lockedCharacters = $project->characters()
            ->whereNotNull('canonical_reference_asset_id')
            ->count();

        if ($lockedCharacters > 0 && ! $capabilities->supportsMode(GenerationMode::ImageToVideo)) {
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
        if (! $capabilities->supportsMode(GenerationMode::TextToVideo)) {
            $unrenderable = $project->shots()
                ->whereDoesntHave('characters', fn ($q) => $q->whereNotNull('canonical_reference_asset_id'))
                ->count();

            if ($unrenderable > 0) {
                $warnings[] = sprintf(
                    '%s is image-to-video only, and %d shot(s) have no locked character '.
                    'reference to start from. Those shots cannot be rendered on this model. '.
                    'Lock a character onto them, or pin the project to a model that also '.
                    'does text-to-video.',
                    $capabilities->label,
                    $unrenderable,
                );
            }
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
