<?php

namespace Tests\Feature;

use App\Integrations\Fal\FalClient;
use App\Models\Project;
use App\Models\User;
use App\Services\Pipeline\ProgressSnapshot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * `studio:doctor` has to be right about WHICH half is missing.
 *
 * The sweep that collects paid generations is a queued job: schedule:work puts
 * it on the queue, queue:work runs it. Either alone leaves the heartbeat stale.
 * On 2026-10-06 that cost two debugging rounds, because the only guidance on
 * screen named schedule:work — so restarting it changed nothing while the real
 * fault was a dead worker, and five clips' worth of money sat uncollectable.
 *
 * The queue depth is what tells the two apart, which is why these tests pin the
 * advice and not just the exit code.
 */
class DoctorCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function run_doctor(): string
    {
        Artisan::call('studio:doctor');

        return Artisan::output();
    }

    protected function sweptAt(int $secondsAgo): void
    {
        Cache::put(ProgressSnapshot::RECONCILER_HEARTBEAT_KEY, time() - $secondsAgo, now()->addDay());
    }

    public function test_a_healthy_studio_passes(): void
    {
        $this->sweptAt(5);

        $this->artisan('studio:doctor')->assertExitCode(0);
    }

    public function test_a_backlog_blames_the_worker_not_the_scheduler(): void
    {
        $this->sweptAt(600);

        // The scheduler is clearly alive — it has queued ten sweeps nobody ran.
        for ($i = 0; $i < 10; $i++) {
            DB::table('jobs')->insert([
                'queue' => 'default',
                'payload' => '{}',
                'attempts' => 0,
                'available_at' => time(),
                'created_at' => time(),
            ]);
        }

        $output = $this->run_doctor();

        $this->assertStringContainsString('queue:work', $output);
        $this->assertStringNotContainsString('Start `php artisan schedule:work`', $output);
        $this->artisan('studio:doctor')->assertExitCode(1);
    }

    public function test_an_empty_queue_blames_the_scheduler_and_still_names_the_worker(): void
    {
        $this->sweptAt(600);

        $output = $this->run_doctor();

        // Nothing is producing sweeps.
        $this->assertStringContainsString('schedule:work', $output);

        // But the advice must still say the worker is required, because that is
        // the half the old on-page warning left out entirely.
        $this->assertStringContainsString('queue:work', $output);
    }

    public function test_a_sweep_that_has_never_run_is_a_problem(): void
    {
        Cache::forget(ProgressSnapshot::RECONCILER_HEARTBEAT_KEY);

        $this->artisan('studio:doctor')->assertExitCode(1);
    }

    public function test_a_missing_credential_is_only_a_problem_when_something_uses_fal(): void
    {
        $this->sweptAt(5);
        config(['studio.fal.key' => '']);
        $this->app->forgetInstance(FalClient::class);

        // Every driver is fake under test, so nothing needs the key.
        $this->artisan('studio:doctor')->assertExitCode(0);

        config(['studio.video_generator' => 'fal']);
        $this->app->forgetInstance(FalClient::class);

        $this->artisan('studio:doctor')->assertExitCode(1);
    }

    /**
     * The on-page warning is what the owner reads at the moment money is being
     * lost, and it named only `schedule:work`. Restarting that alone fixed
     * nothing, because the dead half was the worker — two rounds of debugging
     * spent on a banner that was half right.
     */
    public function test_the_on_page_warning_names_both_processes(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->for($user)->budget(1.00)->create();

        $this->actingAs($user)
            ->get(route('projects.show', $project))
            ->assertOk()
            ->assertSee('php artisan queue:work')
            ->assertSee('php artisan schedule:work')
            ->assertSee('studio:doctor');
    }

    public function test_it_reports_every_problem_rather_than_dying_on_the_first(): void
    {
        // This command is what the owner runs WHEN something is wrong, so one
        // broken thing must not hide the rest.
        Cache::forget(ProgressSnapshot::RECONCILER_HEARTBEAT_KEY);
        config(['studio.fal.key' => '', 'studio.video_generator' => 'fal']);
        $this->app->forgetInstance(FalClient::class);

        $output = $this->run_doctor();

        $this->assertStringContainsString('FAL_KEY', $output);
        $this->assertStringContainsString('schedule:work', $output);
        $this->assertStringContainsString('2 problem(s) found', $output);
    }
}
