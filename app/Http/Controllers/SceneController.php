<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateSceneRequest;
use App\Models\Project;
use App\Models\Scene;
use App\Services\Pipeline\ProjectStateMachine;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * FR-3: the owner edits scene text, order and mood before anything is generated.
 *
 * Every write here goes through ProjectStateMachine::sceneListEdited(), which is
 * what enforces the §8 invalidation rule — editing a scene after shots exist
 * marks them stale rather than silently leaving the video out of sync with its
 * own script.
 */
class SceneController extends Controller
{
    public function __construct(protected ProjectStateMachine $stateMachine) {}

    public function update(UpdateSceneRequest $request, Project $project, Scene $scene): RedirectResponse
    {
        $this->assertBelongs($project, $scene);

        $scene->update($request->validated());

        $this->stateMachine->sceneListEdited($project);

        return back()->with('status', "Scene {$scene->sequence} updated. Downstream shots are now stale.");
    }

    public function destroy(Project $project, Scene $scene): RedirectResponse
    {
        $this->authorize('update', $project);
        $this->assertBelongs($project, $scene);

        DB::transaction(function () use ($project, $scene) {
            $scene->delete();
            $this->resequence($project);
        });

        $this->stateMachine->sceneListEdited($project);

        return back()->with('status', 'Scene deleted.');
    }

    /**
     * Move a scene one position up or down.
     *
     * Swapping two rows would violate the unique (project_id, sequence) index
     * mid-update, so the whole ordering is rewritten inside one transaction.
     */
    public function move(Request $request, Project $project, Scene $scene): RedirectResponse
    {
        $this->authorize('update', $project);
        $this->assertBelongs($project, $scene);

        $direction = $request->validate([
            'direction' => ['required', 'in:up,down'],
        ])['direction'];

        DB::transaction(function () use ($project, $scene, $direction) {
            $ordered = $project->scenes()->orderBy('sequence')->get();
            $index = $ordered->search(fn ($s) => $s->is($scene));

            $target = $direction === 'up' ? $index - 1 : $index + 1;

            if ($index === false || $target < 0 || $target >= $ordered->count()) {
                return;
            }

            $reordered = $ordered->all();
            [$reordered[$index], $reordered[$target]] = [$reordered[$target], $reordered[$index]];

            // Park everything out of the unique index's way, then write the
            // final positions.
            foreach ($reordered as $offset => $item) {
                $item->forceFill(['sequence' => -($offset + 1)])->save();
            }

            foreach ($reordered as $offset => $item) {
                $item->forceFill(['sequence' => $offset + 1])->save();
            }
        });

        $this->stateMachine->sceneListEdited($project);

        return back()->with('status', 'Scene order updated.');
    }

    protected function resequence(Project $project): void
    {
        $project->scenes()->orderBy('sequence')->get()
            ->each(fn ($scene, $i) => $scene->forceFill(['sequence' => -($i + 1)])->save());

        $project->scenes()->orderBy('sequence', 'desc')->get()
            ->each(fn ($scene, $i) => $scene->forceFill(['sequence' => $i + 1])->save());
    }

    /**
     * A scene id from the URL must belong to the project in the URL. Without
     * this, /projects/1/scenes/999 would edit another project's scene.
     */
    protected function assertBelongs(Project $project, Scene $scene): void
    {
        abort_unless($scene->project_id === $project->getKey(), 404);
    }
}
