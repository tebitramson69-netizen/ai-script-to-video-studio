<?php

namespace App\Console\Commands;

use App\Enums\ProjectStatus;
use App\Models\Project;
use App\Services\Retention\RetentionManager;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

/**
 * NFR-7: reclaim the disk taken by intermediate shot clips once a project has
 * been exported.
 *
 * Video is measured in gigabytes. On a small VPS this is the difference between
 * a studio that keeps working and one that fills its disk after a dozen videos.
 */
class PurgeIntermediatesCommand extends Command
{
    protected $signature = 'studio:purge-intermediates
                            {project? : Project id. Omit to consider every exported project.}
                            {--days=0 : Only purge projects exported at least this many days ago.}
                            {--dry-run : Report what would be reclaimed without deleting anything.}';

    protected $description = 'Delete intermediate shot clips from exported projects, keeping the final video and locked references';

    public function handle(RetentionManager $retention): int
    {
        $projects = $this->targets();

        if ($projects->isEmpty()) {
            $this->info('No projects are eligible for purging.');

            return self::SUCCESS;
        }

        $dryRun = (bool) $this->option('dry-run');
        $totalReclaimed = 0;
        $purged = 0;

        foreach ($projects as $project) {
            $reason = $retention->purgeBlockedReason($project);

            if ($reason !== null) {
                $this->line("  <fg=yellow>skip</> #{$project->id} {$project->title} — {$reason}");

                continue;
            }

            $reclaimable = (int) $retention->purgeableAssets($project)->sum('bytes');

            if ($dryRun) {
                $this->line("  <fg=cyan>would purge</> #{$project->id} {$project->title} — ".$retention->humanBytes($reclaimable));
                $totalReclaimed += $reclaimable;

                continue;
            }

            $reclaimed = $retention->purge($project);
            $totalReclaimed += $reclaimed;
            $purged++;

            $this->line("  <fg=green>purged</> #{$project->id} {$project->title} — ".$retention->humanBytes($reclaimed));
        }

        $verb = $dryRun ? 'Would reclaim' : 'Reclaimed';
        $this->newLine();
        $this->info("{$verb} ".$retention->humanBytes($totalReclaimed).($dryRun ? '.' : " across {$purged} project(s)."));

        if (! $dryRun && $purged > 0) {
            $this->comment('Purged shots must be re-rendered before those projects can be exported again.');
        }

        return self::SUCCESS;
    }

    /**
     * @return Collection<int, Project>
     */
    protected function targets()
    {
        $query = Project::query()
            ->where('status', ProjectStatus::ExportReady)
            ->whereNotNull('exported_at');

        if ($id = $this->argument('project')) {
            // An explicit id bypasses the status filter so the owner can see the
            // real reason it is skipped rather than an empty list.
            $query = Project::query()->whereKey($id);
        }

        if (($days = (int) $this->option('days')) > 0) {
            $query->where('exported_at', '<=', now()->subDays($days));
        }

        return $query->get();
    }
}
