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
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The owner-facing HTTP surface: the screens render, the forms validate, and
 * script/scene text is escaped rather than executed (§14).
 */
class WebFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_project_list_renders(): void
    {
        $user = User::factory()->create();
        Project::factory()->for($user)->create(['title' => 'My Folk Tale']);

        $this->actingAs($user)
            ->get(route('projects.index'))
            ->assertOk()
            ->assertSee('My Folk Tale');
    }

    public function test_the_dashboard_renders_at_every_pipeline_stage(): void
    {
        $user = User::factory()->create();

        // A dashboard that only renders in the happy state is a dashboard that
        // breaks exactly when the owner needs it.
        foreach (ProjectStatus::cases() as $status) {
            $project = Project::factory()->for($user)->status($status)->create();
            $scene = Scene::factory()->for($project)->create();
            Character::factory()->for($project)->create();
            Shot::factory()->for($project)->for($scene)->create();

            $this->actingAs($user)
                ->get(route('projects.show', $project))
                ->assertOk()
                ->assertSee($status->label());
        }
    }

    public function test_creating_a_project_parses_the_script(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post(route('projects.store'), [
            'title' => 'River Story',
            'aspect_ratio' => '9:16',
            'budget_cap_usd' => '12.50',
            'script' => "Ngozi walked to the river.\n\nTembe watched from the trees.",
        ]);

        $project = Project::where('title', 'River Story')->firstOrFail();

        $response->assertRedirect(route('projects.show', $project));

        // The sync queue means parsing already happened.
        $this->assertSame(ProjectStatus::ScriptReady, $project->status);
        $this->assertSame(2, $project->scenes()->count());
        $this->assertSame('9:16', $project->aspect_ratio->value);
    }

    public function test_a_script_can_be_uploaded_instead_of_pasted(): void
    {
        Storage::fake('local');
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('projects.store'), [
            'title' => 'Uploaded',
            'aspect_ratio' => '16:9',
            'budget_cap_usd' => '10',
            'script_file' => UploadedFile::fake()->createWithContent(
                'story.txt',
                "Ngozi walked to the river.\n\nTembe watched from the trees.",
            ),
        ])->assertSessionHasNoErrors();

        $this->assertSame(2, Project::where('title', 'Uploaded')->firstOrFail()->scenes()->count());
    }

    public function test_a_project_needs_a_script_from_somewhere(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('projects.store'), [
            'title' => 'Empty',
            'aspect_ratio' => '16:9',
            'budget_cap_usd' => '10',
        ])->assertSessionHasErrors('script');

        $this->assertDatabaseMissing('projects', ['title' => 'Empty']);
    }

    public function test_the_budget_cap_cannot_exceed_the_configured_maximum(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('projects.store'), [
            'title' => 'Runaway',
            'aspect_ratio' => '16:9',
            'budget_cap_usd' => (string) (config('studio.budget.max_cap_usd') + 1),
            'script' => 'A script.',
        ])->assertSessionHasErrors('budget_cap_usd');
    }

    public function test_script_and_scene_text_is_escaped_not_executed(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->for($user)->create();

        Scene::factory()->for($project)->create([
            'setting' => '<script>alert(1)</script>',
            'narration' => '<img src=x onerror=alert(2)>',
        ]);

        $response = $this->actingAs($user)->get(route('projects.show', $project));

        $response->assertOk();

        // Blade's {{ }} escapes these. A raw {!! !!} anywhere would fail here.
        $response->assertDontSee('<script>alert(1)</script>', false);
        $response->assertDontSee('<img src=x onerror=alert(2)>', false);
        $response->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false);
    }

    public function test_editing_a_scene_marks_shots_stale_and_blocks_export(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->for($user)->status(ProjectStatus::ExportReady)->create();
        $scene = Scene::factory()->for($project)->create();
        $shot = Shot::factory()->rendered()->for($project)->for($scene)->create();

        $this->actingAs($user)->patch(route('scenes.update', [$project, $scene]), [
            'setting' => 'a different place',
            'narration' => 'Completely new narration.',
            'mood' => 'tense',
        ])->assertRedirect();

        $this->assertSame(ShotStatus::Stale, $shot->fresh()->status);
        $this->assertSame(ProjectStatus::ScriptReady, $project->fresh()->status);
    }

    public function test_locking_a_reference_image_from_another_project_is_rejected(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->for($user)->create();
        $character = Character::factory()->for($project)->create();

        $foreignAsset = Asset::factory()->type(AssetType::CharacterReference)->create();

        $this->actingAs($user)
            ->post(route('characters.lock', [$project, $character]), ['asset_id' => $foreignAsset->id])
            ->assertNotFound();

        $this->assertNull($character->fresh()->canonical_reference_asset_id);
    }

    public function test_scenes_can_be_reordered(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->for($user)->create();

        $first = Scene::factory()->for($project)->create(['sequence' => 1, 'setting' => 'first']);
        $second = Scene::factory()->for($project)->create(['sequence' => 2, 'setting' => 'second']);

        $this->actingAs($user)
            ->post(route('scenes.move', [$project, $second]), ['direction' => 'up'])
            ->assertRedirect();

        $this->assertSame(1, $second->fresh()->sequence);
        $this->assertSame(2, $first->fresh()->sequence);
    }

    public function test_moving_the_first_scene_up_is_a_harmless_no_op(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->for($user)->create();
        $first = Scene::factory()->for($project)->create(['sequence' => 1]);
        Scene::factory()->for($project)->create(['sequence' => 2]);

        $this->actingAs($user)
            ->post(route('scenes.move', [$project, $first]), ['direction' => 'up'])
            ->assertRedirect();

        $this->assertSame(1, $first->fresh()->sequence);
    }

    public function test_the_login_screen_renders_and_offers_no_sign_up(): void
    {
        $this->get(route('login'))
            ->assertOk()
            ->assertSee('Sign in')
            ->assertDontSee('Create an account');
    }
}
