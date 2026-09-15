<?php

namespace App\Http\Controllers;

use App\Enums\AssetType;
use App\Models\Asset;
use App\Models\Character;
use App\Models\Project;
use App\Services\Pipeline\ProjectStateMachine;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * FR-5 / FR-6: the owner locks ONE canonical reference per character, and that
 * image is reused for every shot. This is the consistency mechanism, so locking
 * is an explicit owner decision — never automatic.
 */
class CharacterController extends Controller
{
    public function __construct(protected ProjectStateMachine $stateMachine) {}

    public function update(Request $request, Project $project, Character $character): RedirectResponse
    {
        $this->authorize('update', $project);
        $this->assertBelongs($project, $character);

        $character->update($request->validate([
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:1000'],
        ]));

        return back()->with('status', "Updated {$character->name}.");
    }

    public function lock(Request $request, Project $project, Character $character): RedirectResponse
    {
        $this->authorize('update', $project);
        $this->assertBelongs($project, $character);

        $assetId = (int) $request->validate([
            'asset_id' => ['required', 'integer'],
        ])['asset_id'];

        /** @var Asset|null $asset */
        $asset = $project->assets()
            ->where('type', AssetType::CharacterReference)
            ->whereKey($assetId)
            ->first();

        abort_if($asset === null, 404, 'That reference image does not belong to this project.');

        $character->forceFill([
            'canonical_reference_asset_id' => $asset->getKey(),
            'locked_at' => now(),
        ])->save();

        // §8: re-locking marks affected shots stale and pulls the project back to
        // SCENES_READY. The state machine decides that, not this controller.
        $this->stateMachine->characterReferenceLocked($project, $character);

        return back()->with('status', "Locked a canonical reference for {$character->name}.");
    }

    public function destroy(Project $project, Character $character): RedirectResponse
    {
        $this->authorize('update', $project);
        $this->assertBelongs($project, $character);

        $character->delete();

        return back()->with('status', 'Character removed.');
    }

    protected function assertBelongs(Project $project, Character $character): void
    {
        abort_unless($character->project_id === $project->getKey(), 404);
    }
}
