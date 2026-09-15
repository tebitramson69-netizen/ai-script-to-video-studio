<?php

namespace App\Http\Controllers;

use App\Exceptions\BudgetExceededException;
use App\Models\Project;
use App\Models\Shot;
use App\Services\Pipeline\PipelineRunner;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * FR-10: preview each shot and regenerate it independently.
 *
 * This is the review loop the PRD treats as a feature, not a workaround (NG1) —
 * regenerating one weak shot must never mean re-rendering the whole video.
 */
class ShotController extends Controller
{
    public function __construct(protected PipelineRunner $runner) {}

    public function regenerate(Request $request, Project $project, Shot $shot): RedirectResponse
    {
        $this->authorize('generate', $project);
        $this->assertBelongs($project, $shot);

        $prompt = $request->validate([
            'prompt' => ['nullable', 'string', 'max:2000'],
        ])['prompt'] ?? null;

        try {
            $this->runner->regenerateShot($project, $shot, $prompt);
        } catch (BudgetExceededException $e) {
            return back()->with('budget_error', $e->getMessage());
        }

        return back()->with('status', "Re-rendering shot #{$shot->sequence}.");
    }

    protected function assertBelongs(Project $project, Shot $shot): void
    {
        abort_unless($shot->project_id === $project->getKey(), 404);
    }
}
