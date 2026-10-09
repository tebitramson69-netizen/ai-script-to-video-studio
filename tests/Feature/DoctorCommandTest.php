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
use Illuminate\Support\Str;
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

    protected function failedJob(): void
    {
        DB::table('failed_jobs')->insert([
            'uuid' => (string) Str::uuid(),
            'connection' => 'database',
            'queue' => 'default',
            'payload' => '{}',
            'exception' => 'PDOException: SQLSTATE[HY000]: General error: 5 database is locked',
            'failed_at' => now(),
        ]);
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

    /**
     * The failed-jobs check used to sit below the healthy-heartbeat early
     * return, so a running studio with a failed job exited 0 and printed
     * "All checks passed. Safe to spend." A diagnostic that is confidently
     * wrong is worse than none.
     *
     * The owner's own failed ReconcileProviderRequestsJob — the one carrying
     * the "database is locked" exception — was found only because their
     * heartbeat happened to be stale at that moment. With the three windows
     * running it would have gone quiet.
     */
    public function test_a_failed_job_is_a_problem_even_when_the_sweep_is_healthy(): void
    {
        $this->sweptAt(5);
        $this->failedJob();

        $output = $this->run_doctor();

        $this->assertStringContainsString('queue:failed', $output);
        $this->assertStringNotContainsString('All checks passed', $output);

        $this->artisan('studio:doctor')->assertExitCode(1);
    }

    /**
     * A reserved row is a job a worker is running right now. A RenderShotJob
     * sits reserved for minutes, so counting it meant a DEAD SCHEDULER during
     * a live render reported as a dead worker — the exact misdiagnosis this
     * command was built to end.
     */
    public function test_a_job_a_worker_is_running_is_not_a_backlog(): void
    {
        $this->sweptAt(600);

        DB::table('jobs')->insert([
            'queue' => 'default',
            'payload' => '{}',
            'attempts' => 1,
            'reserved_at' => time(),
            'available_at' => time(),
            'created_at' => time(),
        ]);

        $output = $this->run_doctor();

        // Nothing is WAITING, so the scheduler is the half that is down.
        $this->assertStringContainsString('schedule:work', $output);
        $this->assertStringNotContainsString('are waiting and unconsumed', $output);
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
