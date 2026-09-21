<?php

namespace App\Contracts;

use App\Contracts\Data\ClipRequest;
use App\Contracts\Data\GeneratedMedia;
use App\Enums\ProviderRequestStatus;

/**
 * A video generator whose work is submitted and collected later.
 *
 * Separate from VideoGenerator rather than folded into it, so a provider that
 * genuinely answers in one call is not forced to fake a queue, and a local
 * renderer does not have to pretend to have request ids. The pipeline takes the
 * async path only when the bound generator implements this.
 *
 * Why this matters: a real video render takes minutes. Holding a queue worker
 * open for the duration wastes the worker and, worse, loses the thread if the
 * worker restarts — while the provider carries on generating, and charging. The
 * request id has to outlive the process that asked for it.
 */
interface QueueableVideoGenerator extends VideoGenerator
{
    /**
     * Hand the work to the provider and return its request id.
     *
     * Must not wait for completion. The caller has already claimed the right to
     * spend this money; this method's only job is to get the work accepted and
     * hand back a handle.
     *
     * @throws ProviderException
     */
    public function submitClip(ClipRequest $request): string;

    /**
     * Where the provider says that request has got to.
     *
     * @throws ProviderException
     */
    public function checkStatus(string $providerRequestId): ProviderRequestStatus;

    /**
     * Collect a finished result.
     *
     * Only called once checkStatus() reports completion. Returns media on local
     * disk, exactly as the synchronous path does — everything downstream of the
     * adapter stays identical whichever path produced the file.
     *
     * @throws ProviderException
     */
    public function fetchResult(string $providerRequestId): GeneratedMedia;
}
