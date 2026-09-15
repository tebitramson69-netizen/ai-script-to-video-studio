<?php

namespace App\Http\Controllers;

use App\Enums\AssetType;
use App\Enums\ProjectStatus;
use App\Http\Requests\StoreProjectRequest;
use App\Models\Asset;
use App\Models\Project;
use App\Services\Cost\CostEstimator;
use App\Services\Pipeline\PipelineRunner;
use App\Services\Pipeline\ProjectStateMachine;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\View\View;

class ProjectController extends Controller
{
    public function index(Request $request): View
    {
        return view('projects.index', [
            'projects' => $request->user()->projects()->latest()->paginate(15),
        ]);
    }

    public function create(): View
    {
        return view('projects.create');
    }

    public function store(StoreProjectRequest $request, PipelineRunner $runner): RedirectResponse
    {
        $project = $request->user()->projects()->create([
            'title' => $request->validated('title'),
            'aspect_ratio' => $request->validated('aspect_ratio'),
            'budget_cap_usd' => $request->validated('budget_cap_usd'),
            'script' => $request->scriptText(),
            'status' => ProjectStatus::Draft,
            'language' => 'en',
        ]);

        // Parsing is free and instant, so there is no reason to make the owner
        // press a second button before they can see their scene list.
        $runner->parseScript($project);

        return redirect()
            ->route('projects.show', $project)
            ->with('status', 'Script submitted. Parsing it into scenes now.');
    }

    public function show(Project $project, CostEstimator $costs, ProjectStateMachine $stateMachine): View
    {
        $this->authorize('view', $project);

        $project->load([
            'scenes',
            'characters.canonicalReference',
            'shots.asset',
            'shots.scene',
            'finalAsset',
        ]);

        return view('projects.show', [
            'project' => $project,
            'estimate' => $costs->estimateRemainingRun($project),
            'runtimeSeconds' => $costs->estimatedRuntimeSeconds($project),
            'exportBlockedReason' => $stateMachine->exportBlockedReason($project),
            'candidatesByCharacter' => $this->candidatesByCharacter($project),
        ]);
    }

    public function destroy(Project $project): RedirectResponse
    {
        $this->authorize('delete', $project);

        $project->delete();

        return redirect()
            ->route('projects.index')
            ->with('status', 'Project deleted.');
    }

    /**
     * Group reference candidates by the character they were generated for.
     *
     * @return array<int, Collection<int, Asset>>
     */
    protected function candidatesByCharacter(Project $project): array
    {
        return $project->assets()
            ->where('type', AssetType::CharacterReference)
            ->orderBy('id')
            ->get()
            ->groupBy(fn ($asset) => $asset->meta['character_id'] ?? 0)
            ->all();
    }
}
