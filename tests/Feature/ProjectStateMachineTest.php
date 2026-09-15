<?php

namespace Tests\Feature;

use App\Enums\AssetType;
use App\Enums\ProjectStatus;
use App\Enums\ShotStatus;
use App\Models\Asset;
use App\Models\Character;
use App\Models\Project;
use App\Models\Scene;
use App\Models\Shot;
use App\Services\Pipeline\ProjectStateMachine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * PRD §8 invalidation rule:
 *
 *   "Editing the scene list resets to SCRIPT_READY; re-locking a character
 *    reference marks affected shots stale and resets them to SCENES_READY;
 *    regenerating one shot invalidates only assembly. Stale assets are marked,
 *    not deleted, and export is blocked while any stale shot exists."
 */
class ProjectStateMachineTest extends TestCase
{
    use RefreshDatabase;

    protected ProjectStateMachine $stateMachine;

    protected function setUp(): void
    {
        parent::setUp();
        $this->stateMachine = app(ProjectStateMachine::class);
    }

    public function test_advance_never_moves_a_project_backwards(): void
    {
        $project = Project::factory()->status(ProjectStatus::ShotsReady)->create();

        // A job finishing late must not resurrect an earlier stage.
        $this->stateMachine->advanceTo($project, ProjectStatus::ScriptReady);

        $this->assertSame(ProjectStatus::ShotsReady, $project->fresh()->status);
    }

    public function test_demote_never_moves_a_project_forwards(): void
    {
        $project = Project::factory()->status(ProjectStatus::ScriptReady)->create();

        $this->stateMachine->demoteTo($project, ProjectStatus::ExportReady);

        $this->assertSame(ProjectStatus::ScriptReady, $project->fresh()->status);
    }

    public function test_editing_the_scene_list_resets_to_script_ready_and_marks_shots_stale(): void
    {
        $project = Project::factory()->status(ProjectStatus::ExportReady)->create();
        $scene = Scene::factory()->for($project)->create();
        $shot = Shot::factory()->rendered()->for($project)->for($scene)->create();

        $this->stateMachine->sceneListEdited($project);

        $this->assertSame(ProjectStatus::ScriptReady, $project->fresh()->status);
        $this->assertSame(ShotStatus::Stale, $shot->fresh()->status);
    }

    public function test_stale_shots_keep_their_clip_rather_than_losing_it(): void
    {
        $project = Project::factory()->status(ProjectStatus::ExportReady)->create();
        $scene = Scene::factory()->for($project)->create();

        $asset = Asset::factory()->for($project)->create();
        $shot = Shot::factory()->rendered()->for($project)->for($scene)
            ->create(['asset_id' => $asset->id]);

        $this->stateMachine->sceneListEdited($project);

        // "Stale assets are marked, not deleted."
        $this->assertSame($asset->id, $shot->fresh()->asset_id);
        $this->assertDatabaseHas('assets', ['id' => $asset->id]);
    }

    public function test_relocking_a_character_only_stales_shots_that_feature_them(): void
    {
        $project = Project::factory()->status(ProjectStatus::ShotsReady)->create();
        $scene = Scene::factory()->for($project)->create();

        $ngozi = Character::factory()->for($project)->create(['name' => 'Ngozi']);
        $tembe = Character::factory()->for($project)->create(['name' => 'Tembe']);

        $ngoziShot = Shot::factory()->rendered()->for($project)->for($scene)->create(['sequence' => 1]);
        $tembeShot = Shot::factory()->rendered()->for($project)->for($scene)->create(['sequence' => 2]);
        $narratorShot = Shot::factory()->rendered()->for($project)->for($scene)->create(['sequence' => 3]);

        $ngoziShot->characters()->attach($ngozi);
        $tembeShot->characters()->attach($tembe);

        $this->stateMachine->characterReferenceLocked($project, $ngozi);

        $this->assertSame(ShotStatus::Stale, $ngoziShot->fresh()->status);

        // Re-rendering these would be money spent for no reason.
        $this->assertSame(ShotStatus::Rendered, $tembeShot->fresh()->status);
        $this->assertSame(ShotStatus::Rendered, $narratorShot->fresh()->status);

        $this->assertSame(ProjectStatus::ScenesReady, $project->fresh()->status);
    }

    public function test_locking_a_character_before_any_shots_exist_is_forward_progress(): void
    {
        $project = Project::factory()->status(ProjectStatus::ScriptReady)->create();
        $character = Character::factory()->for($project)->create();

        $this->stateMachine->characterReferenceLocked($project, $character);

        $this->assertSame(ProjectStatus::CharactersReady, $project->fresh()->status);
    }

    public function test_regenerating_one_shot_invalidates_assembly_only(): void
    {
        $project = Project::factory()->status(ProjectStatus::ExportReady)->create();
        $scene = Scene::factory()->for($project)->create();

        $narration = Asset::factory()->for($project)
            ->type(AssetType::NarrationTrack)->create();

        $keep = Shot::factory()->rendered()->for($project)->for($scene)->create(['sequence' => 1]);
        $redo = Shot::factory()->rendered()->for($project)->for($scene)->create(['sequence' => 2]);

        $this->stateMachine->shotInvalidated($project, $redo);

        // Audio survives, so the project falls back only as far as VOICE_READY.
        $this->assertSame(ProjectStatus::VoiceReady, $project->fresh()->status);
        $this->assertSame(ShotStatus::Rendered, $keep->fresh()->status);
        $this->assertSame(ShotStatus::Pending, $redo->fresh()->status);
        $this->assertNull($project->fresh()->final_asset_id);
    }

    public function test_export_is_blocked_while_any_shot_is_stale(): void
    {
        $project = Project::factory()->status(ProjectStatus::VoiceReady)->create();
        $scene = Scene::factory()->for($project)->create();

        Asset::factory()->for($project)
            ->type(AssetType::NarrationTrack)->create();

        Shot::factory()->rendered()->for($project)->for($scene)->create(['sequence' => 1]);
        Shot::factory()->for($project)->for($scene)
            ->create(['sequence' => 2, 'status' => ShotStatus::Stale]);

        $reason = $this->stateMachine->exportBlockedReason($project);

        $this->assertNotNull($reason);
        $this->assertStringContainsString('stale', $reason);
        $this->assertFalse($project->isExportable());
    }

    public function test_export_is_allowed_once_every_shot_is_rendered_and_narration_exists(): void
    {
        $project = Project::factory()->status(ProjectStatus::VoiceReady)->create();
        $scene = Scene::factory()->for($project)->create();

        Asset::factory()->for($project)
            ->type(AssetType::NarrationTrack)->create();

        Shot::factory()->rendered()->for($project)->for($scene)->create(['sequence' => 1]);

        $this->assertNull($this->stateMachine->exportBlockedReason($project));
        $this->assertTrue($project->isExportable());
    }

    public function test_export_is_blocked_without_narration(): void
    {
        $project = Project::factory()->status(ProjectStatus::VoiceReady)->create();
        $scene = Scene::factory()->for($project)->create();

        Shot::factory()->rendered()->for($project)->for($scene)->create(['sequence' => 1]);

        $this->assertStringContainsString(
            'Narration',
            (string) $this->stateMachine->exportBlockedReason($project),
        );
    }
}
