<?php

namespace App\Http\Controllers;

use App\Enums\AspectRatio;
use App\Enums\AssetType;
use App\Enums\GenerationMode;
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
            'videoModels' => $models->allVideo(),

            // Filtered server-side for the model the form opens on, so the
            // page is correct with scripting off — offering a ratio that
            // cannot be rendered is the bug this guards against.
            'aspectRatios' => $models->aspectRatiosFor($videoModel),

            // Every ratio, for the browser to re-narrow from when the model
            // changes. It cannot widen back from the filtered list alone.
            'allAspectRatios' => collect(AspectRatio::cases())
                ->map(fn (AspectRatio $r) => ['value' => $r->value, 'label' => $r->label()]),

            // Which ratios each model supports, for that narrowing.
            'ratiosByModel' => collect($models->allVideo())->map(
                fn ($m) => array_map(fn (AspectRatio $r) => $r->value, $models->aspectRatiosFor($m)),
            ),

            // Only models that can start from a reference image are offerable
            // as companions (FR-6).
            'imageCapableKeys' => collect($models->allVideo())
                ->filter(fn ($m) => $m->supportsMode(GenerationMode::ImageToVideo))
                ->keys(),
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

            // Pinned at creation. The aspect ratio was validated against these
            // models, so letting the global default drift later would silently
            // invalidate that check.
            'video_model' => $request->primaryModelKey(),

            // Optional companion for shots with a locked character reference.
            // Null keeps the single-model behaviour this project had before
            // per-shot selection existed.
            'video_model_i2v' => $request->companionModelKey(),
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
        ModelRegistry $models,
    ): View {
        $this->authorize('view', $project);

        $project->load([
            'scenes',
            'characters.canonicalReference',
            'shots.asset',
            'shots.scene',

            // Needed by forShot(): resolving a shot's model asks whether it has
            // a locked reference, and without this that is a query per shot.
            'shots.characters',

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

            // Capability gaps that change what comes out without stopping the
            // run. The owner needs to see these before paying for the render.
            'degradationWarnings' => $models->degradationWarnings($project),
            'videoModel' => $models->forProject($project),

            // Null unless a companion is doing something. The header shows the
            // pair only when there is a pair worth showing.
            'imageVideoModel' => $models->imageModelForProject($project),

            // Per shot, so the owner can see which model rendered — or will
            // render — each clip, and why two rates appear in the estimate.
            'shotModels' => $project->shots
                ->mapWithKeys(fn ($shot) => [$shot->id => $models->forShot($shot)->label]),
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
