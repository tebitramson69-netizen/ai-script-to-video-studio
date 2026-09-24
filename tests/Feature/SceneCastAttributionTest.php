<?php

namespace Tests\Feature;

use App\Contracts\ScriptStructurer;
use App\Enums\AspectRatio;
use App\Jobs\PlanShotsJob;
use App\Jobs\StructureScriptJob;
use App\Models\Asset;
use App\Models\Character;
use App\Models\Project;
use App\Models\Scene;
use App\Models\User;
use App\Services\Pipeline\ProjectStateMachine;
use App\Services\Timing\ShotPlanner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * Scene cast attribution, from the structurer through to the shot.
 *
 * This file exists because of one chain that used to break silently:
 *
 *   1. Dialogue cues must be stripped from narration, or the single narrator
 *      voice (FR-14) reads out "Ada colon, where is the boat".
 *   2. PlanShotsJob used to find a scene's cast by searching the narration text
 *      for each character's name.
 *   3. So stripping the cue removed the only evidence the search relied on, and
 *      the scene lost its character reference — breaking PRD G2 character
 *      consistency for exactly the scenes that have characters in them.
 *
 * Attribution is now data: the structurer decides it, StructureScriptJob
 * persists it, PlanShotsJob reads it back.
 */
class SceneCastAttributionTest extends TestCase
{
    use RefreshDatabase;

    protected function project(string $script): Project
    {
        return Project::factory()->for(User::factory())->create([
            'script' => $script,
            'aspect_ratio' => AspectRatio::Landscape->value,
            'video_model' => 'fake',
        ]);
    }

    protected function parse(Project $project): Project
    {
        app(StructureScriptJob::class, ['projectId' => $project->id])
            ->handle(app(ScriptStructurer::class), app(ProjectStateMachine::class));

        return $project->fresh(['scenes.characters', 'characters']);
    }

    public function test_the_structurers_attribution_is_persisted(): void
    {
        $project = $this->parse($this->project(
            "Long ago Ada lived beside the river. The boy Kofi lived upstream.\n\n".
            'The boy Kofi mended his nets while the water rose.'
        ));

        $scenes = $project->scenes;

        $this->assertCount(2, $scenes);
        $this->assertContains('Ada', $scenes[0]->characters->pluck('name')->all());
        $this->assertSame(['Kofi'], $scenes[1]->characters->pluck('name')->all());
    }

    /**
     * The regression this whole mechanism exists for.
     */
    public function test_a_dialogue_scene_keeps_its_cast_although_the_name_is_gone_from_the_narration(): void
    {
        $project = $this->parse($this->project("ADA: Where is the boat?\nKOFI: I moved it before the storm."));

        $scene = $project->scenes->first();

        // The cue is gone from what the narrator reads...
        $this->assertStringNotContainsString('ADA', $scene->narration);
        $this->assertStringNotContainsString('KOFI', $scene->narration);

        // ...and the cast survives anyway, because it is stored rather than
        // re-derived from the text.
        $this->assertEqualsCanonicalizing(
            ['Ada', 'Kofi'],
            $scene->characters->pluck('name')->all(),
        );
    }

    public function test_plan_shots_gives_the_shot_the_scenes_attributed_cast(): void
    {
        $project = $this->parse($this->project('ADA: Where is the boat?'));

        // A locked reference is what makes the attribution matter downstream: the
        // shot needs it to render image-to-video (FR-6).
        $ada = $project->characters->firstWhere('name', 'Ada');
        $ada->forceFill([
            'canonical_reference_asset_id' => Asset::factory()->for($project)->create()->id,
        ])->save();

        app(PlanShotsJob::class, ['projectId' => $project->id])
            ->handle(app(ShotPlanner::class), app(ProjectStateMachine::class));

        $shot = $project->fresh('shots.characters')->shots->first();

        $this->assertNotNull($shot);
        $this->assertSame(['Ada'], $shot->characters->pluck('name')->all());
        $this->assertTrue($shot->hasLockedReference());
    }

    public function test_re_parsing_keeps_a_locked_reference_the_owner_already_paid_for(): void
    {
        $project = $this->parse($this->project('Long ago Ada lived beside the river.'));

        $ada = $project->characters->firstWhere('name', 'Ada');
        $asset = Asset::factory()->for($project)->create();
        $ada->forceFill(['canonical_reference_asset_id' => $asset->id])->save();

        // Characters are upserted, not replaced (PRD §10.2). Losing the reference
        // would mean paying for it again.
        $reparsed = $this->parse($project->fresh());

        $this->assertSame(
            $asset->id,
            $reparsed->characters->firstWhere('name', 'Ada')->canonical_reference_asset_id,
        );
    }

    public function test_a_scene_with_no_attribution_falls_back_to_the_text_for_legacy_rows(): void
    {
        // Scenes created before attribution was persisted have an empty pivot.
        // Re-deriving from the text keeps those projects working until the owner
        // re-parses; new scenes never take this path.
        $project = $this->project('unused');
        $character = Character::factory()->for($project)->create(['name' => 'Ada']);
        $scene = Scene::factory()->for($project)->create([
            'sequence' => 1,
            'narration' => 'Ada waited by the river.',
        ]);

        $this->assertTrue($scene->characters->isEmpty());

        app(PlanShotsJob::class, ['projectId' => $project->id])
            ->handle(app(ShotPlanner::class), app(ProjectStateMachine::class));

        $shot = $project->fresh('shots.characters')->shots->first();

        $this->assertSame(['Ada'], $shot->characters->pluck('name')->all());
        $this->assertSame($character->id, $shot->characters->first()->id);
    }

    public function test_structurer_warnings_reach_the_log_rather_than_being_discarded(): void
    {
        // Computing a warning and dropping it is the same mistake as the
        // discarded scene attribution this whole mechanism fixed.
        Log::spy();

        $this->parse($this->project('Water moves through three states.'));

        Log::shouldHaveReceived('info')
            ->withArgs(fn (string $message, array $context) => $message === 'Script structurer warning'
                && str_contains($context['warning'], 'No characters were detected'))
            ->once();
    }

    public function test_deleting_a_scene_does_not_leave_orphaned_pivot_rows(): void
    {
        $project = $this->parse($this->project('Long ago Ada lived beside the river.'));

        $this->assertDatabaseCount('character_scene', 1);

        $project->scenes->first()->delete();

        $this->assertDatabaseCount('character_scene', 0);
    }
}
