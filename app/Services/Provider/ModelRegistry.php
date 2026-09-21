<?php

namespace App\Services\Provider;

use App\Contracts\Data\ModelCapabilities;
use App\Enums\AspectRatio;
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
}
