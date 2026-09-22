<?php

namespace Tests\Unit;

use App\Enums\ProviderRequestStatus;
use App\Integrations\Fal\FalResponseMapper;
use PHPUnit\Framework\TestCase;

/**
 * The assumptions, written down.
 *
 * Every field name in FalResponseMapper is a documented pattern rather than an
 * observed one — fal.ai is unreachable from this codebase, so nothing here has
 * been checked against a real response. This file exists so that when the
 * captured shapes arrive, correcting the mapper is a matter of changing a
 * fixture and reading which assertion breaks, rather than re-deriving what the
 * adapter believed.
 *
 * The tolerance tests matter more than the happy-path ones. They are what stops
 * a single renamed key from taking down a pipeline that has already been paid
 * for.
 */
class FalResponseMapperTest extends TestCase
{
    protected FalResponseMapper $mapper;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mapper = new FalResponseMapper;
    }

    public function test_the_documented_status_strings_map_onto_the_lifecycle(): void
    {
        $this->assertSame(ProviderRequestStatus::InQueue, $this->mapper->status(['status' => 'IN_QUEUE']));
        $this->assertSame(ProviderRequestStatus::InProgress, $this->mapper->status(['status' => 'IN_PROGRESS']));
        $this->assertSame(ProviderRequestStatus::Completed, $this->mapper->status(['status' => 'COMPLETED']));
    }

    /**
     * Matched on substrings rather than equality on purpose.
     *
     * Reading an unfamiliar terminal state as "still running" is the expensive
     * mistake: the poller would wait forever on work that is finished and
     * already billed.
     */
    public function test_unfamiliar_terminal_spellings_are_still_read_as_terminal(): void
    {
        $this->assertSame(ProviderRequestStatus::Completed, $this->mapper->status(['status' => 'COMPLETED_WITH_WARNINGS']));
        $this->assertSame(ProviderRequestStatus::Completed, $this->mapper->status(['status' => 'succeeded']));
        $this->assertSame(ProviderRequestStatus::Failed, $this->mapper->status(['state' => 'ERROR']));
        $this->assertSame(ProviderRequestStatus::Cancelled, $this->mapper->status(['status' => 'CANCELLED']));
    }

    public function test_an_unrecognised_status_is_null_rather_than_a_guess(): void
    {
        // Null travels up as "fal said something I do not understand", which is
        // a reportable fact. Defaulting to InProgress would hide it behind a
        // poll loop that never ends.
        $this->assertNull($this->mapper->status(['status' => 'BANANA']));
        $this->assertNull($this->mapper->status([]));
    }

    public function test_the_request_id_is_read_from_any_of_its_documented_spellings(): void
    {
        $this->assertSame('a', $this->mapper->requestId(['request_id' => 'a']));
        $this->assertSame('b', $this->mapper->requestId(['requestId' => 'b']));
        $this->assertSame('c', $this->mapper->requestId(['id' => 'c']));
        $this->assertNull($this->mapper->requestId(['queue_position' => 2]));
    }

    public function test_the_video_url_is_found_at_the_documented_path(): void
    {
        $this->assertSame('https://cdn.fal.media/a.mp4', $this->mapper->videoUrl([
            'video' => ['url' => 'https://cdn.fal.media/a.mp4', 'file_size' => 12345],
            'seed' => 42,
        ]));
    }

    /**
     * The tolerance that makes the assumption survivable.
     *
     * If fal nests the output differently from the documented shape, the
     * adapter still finds the file — and the failure, if any, is a wrong URL
     * rather than a pipeline that cannot collect work it has paid for.
     */
    public function test_a_video_url_nested_somewhere_unexpected_is_still_found(): void
    {
        $this->assertSame('https://cdn.fal.media/deep.mp4', $this->mapper->videoUrl([
            'payload' => ['outputs' => [['media' => ['href' => 'https://cdn.fal.media/deep.mp4']]]],
        ]));
    }

    public function test_a_non_video_url_is_not_mistaken_for_the_output(): void
    {
        $this->assertNull($this->mapper->videoUrl([
            'status' => 'COMPLETED',
            'logs_url' => 'https://fal.run/logs/123',
            'docs' => 'https://fal.ai/models',
        ]));
    }

    public function test_a_reported_charge_is_read_and_silence_stays_null(): void
    {
        $this->assertSame(0.35, $this->mapper->actualCostUsd(['metrics' => ['billed_cost_usd' => 0.35]]));
        $this->assertSame(0.35, $this->mapper->actualCostUsd(['price' => '0.35']));

        // Null, not 0.0 — "free" and "not reported" bill very differently.
        $this->assertNull($this->mapper->actualCostUsd(['video' => ['url' => 'https://x/a.mp4']]));
    }

    public function test_a_field_that_merely_contains_the_word_cost_is_not_read_as_a_charge(): void
    {
        // 'costume' must not be read as a price. The boundary is deliberate:
        // a wrong number here is recorded as real spend against the budget cap.
        $this->assertNull($this->mapper->actualCostUsd(['costume_count' => 3]));
    }

    public function test_a_duration_in_seconds_is_read_and_one_in_milliseconds_is_not(): void
    {
        $this->assertSame(5.0, $this->mapper->durationSeconds(['video' => ['duration_seconds' => 5]]));
        $this->assertSame(10.0, $this->mapper->durationSeconds(['duration' => 10]));

        // 5000 read as seconds would land in the timeline as an 83-minute clip
        // and drag the whole export out with it (FR-18).
        $this->assertNull($this->mapper->durationSeconds(['duration_ms' => 5000]));
    }

    public function test_fal_own_routing_urls_are_read_when_present(): void
    {
        $body = [
            'request_id' => 'r',
            'status_url' => 'https://queue.fal.run/x/requests/r/status',
            'response_url' => 'https://queue.fal.run/x/requests/r',
        ];

        $this->assertSame('https://queue.fal.run/x/requests/r/status', $this->mapper->statusUrl($body));
        $this->assertSame('https://queue.fal.run/x/requests/r', $this->mapper->resultUrl($body));
        $this->assertNull($this->mapper->cancelUrl($body));
    }

    public function test_an_error_message_is_surfaced_from_wherever_it_sits(): void
    {
        $this->assertSame('prompt rejected', $this->mapper->errorMessage(['detail' => 'prompt rejected']));
        $this->assertSame('boom', $this->mapper->errorMessage(['error' => ['message' => 'boom']]));
        $this->assertNull($this->mapper->errorMessage(['status' => 'COMPLETED']));
    }
}
