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
use App\Services\Provider\ModelRegistry;
use App\Services\Retention\RetentionManager;
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

    public function create(ModelRegistry $models): View
    {
        $videoModel = $models->defaultVideo();

        return view('projects.create', [
            'videoModel' => $videoModel,
            'aspectRatios' => $models->aspectRatiosFor($videoModel),
        ]);
    }

    public function store(
        StoreProjectRequest $request,
        PipelineRunner $runner,
        ModelRegistry $models,
    ): RedirectResponse {
        $project = $request->user()->projects()->create([
            'title' => $request->validated('title'),
            'aspect_ratio' => $request->validated('aspect_ratio'),
            'budget_cap_usd' => $request->validated('budget_cap_usd'),
            'script' => $request->scriptText(),
            'status' => ProjectStatus::Draft,
            'language' => 'en',

            // Pinned at creation. The aspect ratio was validated against this
            // model, so letting the global default drift later would silently
            // invalidate that check.
            'video_model' => $models->defaultVideo()->key,
        ]);

        // Parsing is free and instant, so there is no reason to make the owner
        // press a second button before they can see their scene list.
        $runner->parseScript($project);

        return redirect()
            ->route('projects.show', $project)
            ->with('status', 'Script submitted. Parsing it into scenes now.');
    }

    public function show(
        Project $project,
        CostEstimator $costs,
        ProjectStateMachine $stateMachine,
        RetentionManager $retention,
    ): View {
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

            // NFR-7: the owner cannot manage storage they cannot see.
            'retention' => $retention,
            'storageUsed' => $retention->humanBytes($project->storageBytes()),
            'purgeable' => $retention->humanBytes($project->purgeableBytes()),
            'purgeBlockedReason' => $retention->purgeBlockedReason($project),
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
