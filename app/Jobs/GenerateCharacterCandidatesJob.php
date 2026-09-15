<?php

namespace App\Jobs;

use App\Contracts\Data\ImageRequest;
use App\Contracts\ImageGenerator;
use App\Enums\AssetType;
use App\Models\Character;
use App\Services\Cost\CostEstimator;
use App\Services\Pipeline\AssetRecorder;
use Throwable;

/**
 * Stage 2 (PRD §8): candidate reference images for one character (FR-5).
 *
 * The owner then locks exactly one of them, and that locked image is what every
 * shot in the video is generated from (FR-6). Locking is the consistency
 * mechanism — the image model itself is incidental (§10.2).
 */
class GenerateCharacterCandidatesJob extends StudioJob
{
    public function __construct(
        public int $characterId,
        public ?int $count = null,
    ) {}

    public function handle(
        ImageGenerator $images,
        AssetRecorder $recorder,
        CostEstimator $costs,
    ): void {
        $character = Character::with('project')->find($this->characterId);

        if ($character === null) {
            return;
        }

        $project = $character->project;
        $count = $this->count ?? (int) config('studio.image.candidates_per_character', 3);

        $costs->assertCanSpend(
            $project,
            $count * $images->costPerImageUsd(),
            "Reference images for {$character->name}",
        );

        $prompt = $this->buildPrompt($character);

        for ($i = 0; $i < $count; $i++) {
            $startedAt = microtime(true);

            $media = $images->generate(new ImageRequest(
                prompt: $prompt,
                aspectRatio: $project->aspect_ratio,
                seed: random_int(1, 2_000_000_000),
                label: $character->name,
            ));

            $asset = $recorder->record(
                project: $project,
                type: AssetType::CharacterReference,
                media: $media,
                operation: 'image.reference',
                provider: $images->providerName(),
                durationMs: (int) ((microtime(true) - $startedAt) * 1000),
            );

            // Tag the candidate with its character so the review screen can group
            // candidates without a separate table.
            $asset->forceFill([
                'meta' => array_merge($asset->meta ?? [], ['character_id' => $character->getKey()]),
            ])->save();
        }
    }

    /**
     * A reference-sheet style prompt: the point is a clean, consistent, reusable
     * likeness, not an interesting picture.
     */
    protected function buildPrompt(Character $character): string
    {
        $description = trim((string) $character->description);

        return trim(sprintf(
            'Character reference portrait of %s. %s '.
            'Neutral expression, front-facing, even lighting, plain uncluttered background, '.
            'full head and shoulders visible, photorealistic, consistent features.',
            $character->name,
            $description !== '' ? $description : '',
        ));
    }

    public function failed(Throwable $e): void
    {
        $character = Character::with('project')->find($this->characterId);

        if ($character?->project !== null) {
            app(AssetRecorder::class)->recordFailure(
                $character->project,
                'image.reference',
                app(ImageGenerator::class)->providerName(),
                $e->getMessage(),
            );
        }
    }
}
