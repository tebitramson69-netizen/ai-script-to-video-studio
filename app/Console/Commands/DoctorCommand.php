<?php

namespace App\Console\Commands;

use App\Contracts\ImageGenerator;
use App\Contracts\MusicGenerator;
use App\Contracts\SoundEffectGenerator;
use App\Contracts\SpeechSynthesizer;
use App\Contracts\VideoGenerator;
use App\Integrations\Fal\FalClient;
use App\Services\Pipeline\ProgressSnapshot;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Answer, in one command, every question that has had to be asked by hand.
 *
 * Each of these was a `tinker --execute` one-liner typed from memory at least
 * twice during the first real run, and getting one wrong is not free: the
 * driver check is the difference between a run that spends and one that only
 * looks like it did, and the heartbeat check is the difference between clips
 * that get collected and clips that are paid for and abandoned.
 *
 * Deliberately read-only. It resolves drivers, reads a cache key and counts two
 * tables; it makes no provider call and cannot cost anything. Safe to run at
 * any point in a run, including while one is in flight.
 */
class DoctorCommand extends Command
{
    protected $signature = 'studio:doctor';

    protected $description = 'Check drivers, credential, scheduler heartbeat and queue depth before spending anything';

    /**
     * Short name => the interface to resolve and the config key that chooses
     * its driver.
     *
     * Both facts in one row on purpose. They were two parallel lists kept in
     * step by hand, and the second was a `match` with no default arm — so a
     * sixth capability added to the first list and forgotten in the second
     * threw UnhandledMatchError inside the command whose entire job is to keep
     * working when things are broken.
     *
     * @var array<string, array{interface: class-string, driver: string}>
     */
    protected const CAPABILITIES = [
        'video' => ['interface' => VideoGenerator::class, 'driver' => 'video_generator'],
        'image' => ['interface' => ImageGenerator::class, 'driver' => 'image_generator'],
        'speech' => ['interface' => SpeechSynthesizer::class, 'driver' => 'speech_synthesizer'],
        'music' => ['interface' => MusicGenerator::class, 'driver' => 'music_generator'],
        'sfx' => ['interface' => SoundEffectGenerator::class, 'driver' => 'sound_effect_generator'],
    ];

    public function handle(): int
    {
        $problems = $this->drivers();
        $this->newLine();
        $problems = [...$problems, ...$this->credential()];
        $this->newLine();
        $problems = [...$problems, ...$this->collection()];

        $this->newLine();

        if ($problems === []) {
            $this->info('All checks passed. Safe to spend.');

            return self::SUCCESS;
        }

        $this->error(count($problems).' problem(s) found:');

        foreach ($problems as $problem) {
            $this->line("  <fg=red>-</> {$problem}");
        }

        return self::FAILURE;
    }

    /**
     * Which concrete class each capability resolves to, and which model it is
     * pinned to. A `Fake*` class here is why a run can look free and succeed
     * for the wrong reason.
     *
     * @return list<string>
     */
    protected function drivers(): array
    {
        $problems = [];

        $this->line('<options=bold>Drivers</>');

        $rows = [];

        foreach (self::CAPABILITIES as $name => $capability) {
            try {
                $class = get_class(app($capability['interface']));
            } catch (Throwable $e) {
                $problems[] = "{$name}: driver will not resolve — ".$e->getMessage();
                $rows[] = [$name, '<fg=red>unresolvable</>', '—'];

                continue;
            }

            $model = (string) config("studio.default_{$name}_model", '—');
            $short = class_basename($class);

            $rows[] = [
                $name,
                str_starts_with($short, 'Fake') ? "<fg=yellow>{$short}</>" : "<fg=green>{$short}</>",
                $model === 'fake' ? "<fg=yellow>{$model}</>" : $model,
            ];
        }

        $this->table(['capability', 'driver', 'model'], $rows);

        return $problems;
    }

    /**
     * Present or missing, never the value. A credential printed to a terminal
     * is a credential in a scrollback buffer, a screenshot and a support thread.
     *
     * @return list<string>
     */
    protected function credential(): array
    {
        $problems = [];

        $this->line('<options=bold>Credential</>');

        $usesFal = in_array('fal', array_map(
            fn (array $capability) => config("studio.{$capability['driver']}"),
            self::CAPABILITIES,
        ), true);

        $hasKey = app(FalClient::class)->hasKey();

        if ($hasKey) {
            $this->line('  FAL_KEY <fg=green>present</>');
        } elseif ($usesFal) {
            $this->line('  FAL_KEY <fg=red>missing</>');
            $problems[] = 'FAL_KEY is not set, and at least one capability is bound to the fal driver — every call will fail.';
        } else {
            $this->line('  FAL_KEY <fg=yellow>missing</> (no capability uses fal, so nothing needs it)');
        }

        return $problems;
    }

    /**
     * The check that protects money.
     *
     * A submitted generation is billed whether or not anything collects it, and
     * collection is the reconciler's job alone. The sweep is a QUEUED job, so it
     * needs both processes: schedule:work puts it on the queue, queue:work runs
     * it. Either one alone leaves the heartbeat stale, which is why this reports
     * the queue depth beside it — a backlog says which half is missing.
     *
     * @return list<string>
     */
    protected function collection(): array
    {
        $problems = [];

        $this->line('<options=bold>Collection</>');

        // Every read here is wrapped, because this command is what the owner
        // runs WHEN something is wrong. A diagnostic that throws on the first
        // broken thing it touches reports nothing about the rest, and the
        // database being unreachable is itself one of the answers.
        try {
            $lastRun = Cache::get(ProgressSnapshot::RECONCILER_HEARTBEAT_KEY);

            // Waiting work only. A reserved row is a job a worker is running
            // right now, and a long RenderShotJob sits reserved for minutes -
            // counting it would read a DEAD SCHEDULER as a dead worker, which
            // is the exact misdiagnosis this command exists to end.
            $queued = (int) DB::table('jobs')->whereNull('reserved_at')->count();
            $failed = (int) DB::table('failed_jobs')->count();
        } catch (Throwable $e) {
            $this->line('  <fg=red>the database could not be read</>');
            $this->line('  '.$e->getMessage());

            $problems[] = 'The database is unreachable, so nothing about collection can be checked — '.
                'and no stage of the pipeline can run either. Check DB_DATABASE and that migrations have run.';

            return $problems;
        }

        $age = is_numeric($lastRun) ? time() - (int) $lastRun : null;

        // ProgressSnapshot's threshold, not one of our own. The page renders
        // that same constant as "no sweep in the last 5 minutes"; a second
        // number here meant a two-minute-old heartbeat read healthy on the page
        // and broken in this command, which is the misdiagnosis it exists to end.
        $healthy = $age !== null && $age <= ProgressSnapshot::RECONCILER_STALE_AFTER_SECONDS;

        $this->line('  last sweep     '.match (true) {
            $age === null => '<fg=red>never</>',
            $healthy => "<fg=green>{$age}s ago</>",
            default => "<fg=red>{$age}s ago</>",
        });

        $this->line("  waiting jobs   {$queued}");
        $this->line('  failed jobs    '.($failed > 0 ? "<fg=yellow>{$failed}</>" : '0'));

        // Reported whatever the heartbeat says. A failed job is a thing that
        // did not happen - a collection sweep that never collected, a stage
        // that was paid for and lost - and it stays failed while the sweep runs
        // happily around it. This check used to sit below the healthy-heartbeat
        // return, so a live studio with a failed job exited 0 and said "Safe to
        // spend". The owner's own failed job was only ever found because their
        // heartbeat happened to be stale at the time.
        if ($failed > 0) {
            $problems[] = "{$failed} job(s) have failed. Inspect them with `php artisan queue:failed`.";
        }

        if ($healthy) {
            return $problems;
        }

        // A backlog means the scheduler is producing and nothing is consuming;
        // an empty queue with a stale heartbeat means nothing is producing.
        $problems[] = $queued > 0
            ? "The sweep is not running: {$queued} job(s) are waiting and unconsumed. Start `php artisan queue:work`."
            : 'The sweep is not running and nothing is waiting. Start `php artisan schedule:work` — and keep `queue:work` running, because the sweep is a queued job.';

        return $problems;
    }
}
