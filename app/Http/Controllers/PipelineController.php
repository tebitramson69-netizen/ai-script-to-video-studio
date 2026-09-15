<?php

namespace App\Http\Controllers;

use App\Exceptions\BudgetExceededException;
use App\Models\Project;
use App\Services\Pipeline\PipelineRunner;
use App\Services\Retention\RetentionManager;
use Illuminate\Http\RedirectResponse;

/**
 * The owner's "run this stage" buttons.
 *
 * Every action is POST and therefore CSRF-protected (§14) — generation costs
 * money, so it must never be reachable by a GET a browser can be tricked into
 * making.
 */
class PipelineController extends Controller
{
    public function __construct(protected PipelineRunner $runner) {}

    public function parseScript(Project $project): RedirectResponse
    {
        $this->authorize('generate', $project);

        $this->runner->parseScript($project);

        return back()->with('status', 'Re-parsing the script.');
    }

    public function generateCharacters(Project $project): RedirectResponse
    {
        $this->authorize('generate', $project);

        return $this->guarded(
            fn () => $this->runner->generateCharacterCandidates($project),
            'Generating character reference candidates.',
        );
    }

    public function planShots(Project $project): RedirectResponse
    {
        $this->authorize('generate', $project);

        $this->runner->planShots($project);

        return back()->with('status', 'Planning shots from the scene list.');
    }

    public function renderShots(Project $project): RedirectResponse
    {
        $this->authorize('generate', $project);

        return $this->guarded(function () use ($project) {
            $count = $this->runner->renderShots($project);

            return $count === 0
                ? 'Nothing to render — every shot is already done.'
                : "Queued {$count} shot(s) for rendering.";
        });
    }

    public function generateAudio(Project $project): RedirectResponse
    {
        $this->authorize('generate', $project);

        return $this->guarded(
            fn () => $this->runner->generateAudio($project),
            'Generating narration and music.',
        );
    }

    public function export(Project $project): RedirectResponse
    {
        $this->authorize('generate', $project);

        $this->runner->export($project);

        return back()->with('status', 'Assembling the final video.');
    }

    /** FR-15: regenerate narration on its own. */
    public function regenerateNarration(Project $project): RedirectResponse
    {
        $this->authorize('generate', $project);

        return $this->guarded(
            fn () => $this->runner->regenerateNarration($project),
            'Regenerating narration.',
        );
    }

    /** FR-15: regenerate music on its own. */
    public function regenerateMusic(Project $project): RedirectResponse
    {
        $this->authorize('generate', $project);

        return $this->guarded(
            fn () => $this->runner->regenerateMusic($project),
            'Regenerating music.',
        );
    }

    /** NFR-7: reclaim the disk used by intermediate shot clips. */
    public function purgeIntermediates(Project $project, RetentionManager $retention): RedirectResponse
    {
        $this->authorize('generate', $project);

        if (($reason = $retention->purgeBlockedReason($project)) !== null) {
            return back()->with('budget_error', $reason);
        }

        $reclaimed = $retention->purge($project);

        return back()->with('status', 'Reclaimed '.$retention->humanBytes($reclaimed)
            .'. Those shots must be re-rendered before this project can be exported again.');
    }

    /**
     * FR-11 / NFR-4: the cap is hard. A run that would breach it never reaches
     * the queue, and the owner is told the numbers rather than a generic error.
     *
     * @param  \Closure():(string|null)  $action
     */
    protected function guarded(\Closure $action, ?string $successMessage = null): RedirectResponse
    {
        try {
            $result = $action();
        } catch (BudgetExceededException $e) {
            return back()->with('budget_error', $e->getMessage());
        }

        return back()->with('status', is_string($result) ? $result : $successMessage);
    }
}
