<?php

namespace App\Contracts\Data;

/**
 * What every provider hands back: a file on local disk plus what it cost and how
 * it was made.
 *
 * Providers return a *temporary* path. The pipeline is responsible for moving it
 * into the project's storage and creating the Asset row — a driver never touches
 * the database, which is what keeps drivers swappable and testable.
 */
readonly class GeneratedMedia
{
    /**
     * @param  string  $path  absolute path to the generated file on local disk
     * @param  float|null  $durationSeconds  null for still images
     * @param  array<string,mixed>  $meta  seed, provider job id, revised prompt — anything worth keeping for reproducibility (NFR-5)
     */
    public function __construct(
        public string $path,
        public string $mime,
        public string $model,
        public float $costUsd = 0.0,
        public ?float $durationSeconds = null,
        public array $meta = [],
    ) {}

    /**
     * The same media priced differently.
     *
     * A driver prices what it generated from whatever it has to hand, which is
     * not always the best evidence available: the charge the provider reports,
     * or the estimate the ledger persisted when the work was claimed, both
     * arrive outside the driver. This lets the pipeline correct the figure
     * without a driver ever reaching into the database.
     */
    public function withCostUsd(float $costUsd): self
    {
        return new self(
            path: $this->path,
            mime: $this->mime,
            model: $this->model,
            costUsd: $costUsd,
            durationSeconds: $this->durationSeconds,
            meta: $this->meta,
        );
    }
}
