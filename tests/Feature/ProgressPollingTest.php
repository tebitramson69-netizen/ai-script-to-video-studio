<?php

namespace Tests\Feature;

use App\Enums\AssetType;
use App\Enums\ProjectStatus;
use App\Enums\ProviderRequestStatus;
use App\Enums\ShotStatus;
use App\Models\Asset;
use App\Models\Project;
use App\Models\ProviderRequest;
use App\Models\Scene;
use App\Models\Shot;
use App\Models\User;
use App\Services\Pipeline\ProgressSnapshot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The polling endpoint behind the live progress strip.
 *
 * The pipeline runs on a queue, so a page served mid-render shows stale numbers
 * until someone presses refresh — and an owner who refreshes at the wrong moment
 * sees "0 of 6 rendered" and reasonably concludes the stage failed.
 *
 * Four properties are worth more than the payload's shape, and each one has cost
 * a real application somewhere:
 *
 * 1. `busy` is the poller's on/off switch. If it read false while work was
 *    running, the strip would freeze at the one moment it is wanted; if it read
 *    true on an idle project, the browser would poll a finished project forever.
 * 2. `fingerprint` must not move on its own. A snapshot containing a clock would
 *    make every poll look like progress, and the client reloads the page on
 *    structural change — so a timestamp in here is a page that refreshes every
 *    three seconds by itself.
 * 3. It is behind the same policy as the page. Spend, shot counts and failures
 *    are not public facts.
 * 4. It is a GET that changes nothing, which is why it needs no CSRF token — and
 *    that is only safe while it stays read-only.
 */
class ProgressPollingTest extends TestCase
{
    use RefreshDatabase;

    protected function project(array $attributes = []): Project
    {
        return Project::factory()->budget(20.00)->create($attributes);
    }

    protected function shot(Project $project, ShotStatus $status, int $sequence): Shot
    {
        return Shot::factory()
            ->for($project)
            ->for(Scene::factory()->for($project)->create(['sequence' => $sequence]))
            ->create(['sequence' => $sequence, 'status' => $status]);
    }

    // ------------------------------------------------------------- busy flag

    public function test_it_reports_busy_while_shots_are_still_in_flight(): void
    {
        $project = $this->project();
        $this->shot($project, ShotStatus::Rendered, 1);
        $this->shot($project, ShotStatus::Rendering, 2);

        $snapshot = ProgressSnapshot::for($project->fresh());

        $this->assertTrue($snapshot->busy);
        $this->assertSame(2, $snapshot->shots['total']);
        $this->assertSame(1, $snapshot->shots['rendered']);
        $this->assertSame(1, $snapshot->shots['in_flight']);
    }

    public function test_a_planned_but_unrendered_shot_counts_as_busy(): void
    {
        // The owner has just pressed Render and the queue has not picked the job
        // up yet. A poller that stopped here would go quiet at exactly the moment
        // it is being watched.
        $project = $this->project();
        $this->shot($project, ShotStatus::Pending, 1);

        $this->assertTrue(ProgressSnapshot::for($project->fresh())->busy);
    }

    public function test_a_finished_project_is_not_busy_so_the_browser_stops_polling(): void
    {
        $project = $this->project(['status' => ProjectStatus::ExportReady]);
        $this->shot($project, ShotStatus::Rendered, 1);

        $this->assertFalse(ProgressSnapshot::for($project->fresh())->busy);
    }

    public function test_an_outstanding_provider_request_keeps_it_busy_even_with_no_shot_in_flight(): void
    {
        // A render can outlive the worker that started it (FR-10). After a
        // restart the shot row may look settled while the provider is still
        // working and still billing, and that is precisely when the owner needs
        // to be told something is open.
        $project = $this->project();
        $this->shot($project, ShotStatus::Rendered, 1);

        ProviderRequest::create([
            'project_id' => $project->getKey(),
            'capability' => 'video.clip',
            'provider' => 'fal',
            'fingerprint' => 'test-outstanding-1',
            'status' => ProviderRequestStatus::InProgress,
        ]);

        $snapshot = ProgressSnapshot::for($project->fresh());

        $this->assertTrue($snapshot->busy);
        $this->assertSame(1, $snapshot->providerRequestsInFlight);
    }

    public function test_a_stale_shot_is_reported_but_does_not_count_as_work_in_progress(): void
    {
        // Stale means "waiting for a decision", not "running". Counting it as
        // busy would poll forever on a project whose owner has gone to lunch.
        $project = $this->project();
        $this->shot($project, ShotStatus::Stale, 1);

        $snapshot = ProgressSnapshot::for($project->fresh());

        $this->assertFalse($snapshot->busy);
        $this->assertSame(1, $snapshot->shots['stale']);
    }

    // ----------------------------------------------------------- fingerprint

    public function test_the_fingerprint_is_stable_across_calls_that_change_nothing(): void
    {
        $project = $this->project();
        $this->shot($project, ShotStatus::Rendered, 1);

        $first = ProgressSnapshot::for($project->fresh())->fingerprint();
        $second = ProgressSnapshot::for($project->fresh())->fingerprint();

        // If this ever contained a clock, the client would treat every poll as
        // progress and reload the page every few seconds by itself.
        $this->assertSame($first, $second);
    }

    public function test_the_fingerprint_moves_when_a_shot_finishes(): void
    {
        $project = $this->project();
        $shot = $this->shot($project, ShotStatus::Rendering, 1);

        $before = ProgressSnapshot::for($project->fresh())->fingerprint();

        $shot->update(['status' => ShotStatus::Rendered]);

        $this->assertNotSame($before, ProgressSnapshot::for($project->fresh())->fingerprint());
    }

    public function test_the_fingerprint_moves_when_the_export_lands(): void
    {
        $project = $this->project();
        $this->shot($project, ShotStatus::Rendered, 1);

        $before = ProgressSnapshot::for($project->fresh())->fingerprint();

        $project->update([
            'final_asset_id' => Asset::factory()->for($project)->type(AssetType::FinalVideo)->create()->getKey(),
        ]);

        $after = ProgressSnapshot::for($project->fresh());

        $this->assertNotSame($before, $after->fingerprint());
        $this->assertTrue($after->assets['final']);
    }

    // -------------------------------------------------------------- endpoint

    public function test_the_owner_gets_the_snapshot_as_json(): void
    {
        $project = $this->project();
        $this->shot($project, ShotStatus::Rendered, 1);

        $response = $this->actingAs($project->user)
            ->getJson(route('projects.status', $project));

        $response->assertOk()
            ->assertJsonStructure([
                'status', 'status_label', 'busy', 'fingerprint',
                'shots' => ['total', 'rendered', 'in_flight', 'failed', 'stale'],
                'assets' => ['narration', 'music', 'sound_effects', 'final'],
                'provider_requests_in_flight', 'spent_usd', 'budget_cap_usd',
                'export_blocked_reason',
            ])
            ->assertJsonPath('shots.rendered', 1);
    }

    public function test_another_users_project_is_not_readable(): void
    {
        $project = $this->project();
        $intruder = User::factory()->create();

        // Spend, shot counts and failure states are not public facts, and a
        // polling endpoint is the easiest one to forget to authorize.
        $this->actingAs($intruder)
            ->getJson(route('projects.status', $project))
            ->assertForbidden();
    }

    public function test_a_guest_is_not_served_the_snapshot(): void
    {
        $project = $this->project();

        $this->getJson(route('projects.status', $project))->assertUnauthorized();
    }

    public function test_it_sends_an_etag_so_an_unchanged_poll_can_cost_no_body(): void
    {
        $project = $this->project();
        $this->shot($project, ShotStatus::Rendered, 1);

        $first = $this->actingAs($project->user)->getJson(route('projects.status', $project));
        $etag = $first->headers->get('ETag');

        $this->assertNotNull($etag, 'The polling endpoint must offer an ETag.');

        // Most polls return exactly the bytes of the last one. On a metered
        // mobile connection — the normal case here — a 304 is the difference
        // between paying for those bytes hundreds of times and not at all.
        $this->actingAs($project->user)
            ->withHeaders(['If-None-Match' => $etag])
            ->getJson(route('projects.status', $project))
            ->assertStatus(304);
    }

    public function test_polling_does_not_change_anything(): void
    {
        $project = $this->project(['status' => ProjectStatus::ShotsReady]);
        $this->shot($project, ShotStatus::Rendered, 1);

        $before = [
            'status' => $project->status,
            'spend' => $project->spentUsd(),
            'assets' => $project->assets()->count(),
            'usage' => $project->usageRecords()->count(),
        ];

        // The endpoint is reachable by a plain GET, with no CSRF token. That is
        // only safe while it stays read-only, so this asserts the property the
        // exemption depends on.
        foreach (range(1, 3) as $ignored) {
            $this->actingAs($project->user)->getJson(route('projects.status', $project))->assertOk();
        }

        $project->refresh();

        $this->assertSame($before['status'], $project->status);
        $this->assertEqualsWithDelta($before['spend'], $project->spentUsd(), 0.0001);
        $this->assertSame($before['assets'], $project->assets()->count());
        $this->assertSame($before['usage'], $project->usageRecords()->count());
    }

    // ------------------------------------------------------------------- page

    public function test_the_page_renders_the_strip_and_the_config_island(): void
    {
        $project = $this->project();
        $this->shot($project, ShotStatus::Rendered, 1);

        $response = $this->actingAs($project->user)->get(route('projects.show', $project));

        $response->assertOk()
            ->assertSee('id="progress-strip"', false)
            ->assertSee('id="progress-config"', false)
            ->assertSee('js/studio-progress.js', false);

        // @json escapes the slashes in the URL, so the island is checked in the
        // form it actually ships in rather than the form route() returns.
        $response->assertSee(str_replace('/', '\\/', route('projects.status', $project)), false);

        // The strip starts hidden and the numbers are already rendered by Blade,
        // so the page is correct with JavaScript switched off rather than showing
        // a bar that will never move.
        $response->assertSee('hidden', false);
        $response->assertSee('Live updates need JavaScript', false);
    }

    public function test_the_island_carries_the_same_fingerprint_the_endpoint_would_return(): void
    {
        $project = $this->project();
        $this->shot($project, ShotStatus::Rendering, 1);

        $fingerprint = $this->actingAs($project->user)
            ->getJson(route('projects.status', $project))
            ->json('fingerprint');

        // If these disagreed, the poller's first successful request would look
        // like a change and reload the page immediately — every single load.
        $this->actingAs($project->user)
            ->get(route('projects.show', $project))
            ->assertSee($fingerprint, false);
    }
}
