<?php

namespace Tests\Feature;

use App\Enums\AspectRatio;
use App\Models\Project;
use App\Models\Scene;
use App\Models\User;
use App\Services\Pipeline\PipelineRunner;
use App\Services\Provider\ModelRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A project must not be able to reach a paid render on a model that cannot
 * produce it. Veo 3.1 has no 1:1, and our creation form used to offer it.
 */
class ModelCompatibilityTest extends TestCase
{
    use RefreshDatabase;

    protected function onVeo(): void
    {
        config(['studio.default_video_model' => 'veo-3-1-fast']);
    }

    public function test_the_creation_form_offers_only_ratios_the_model_can_produce(): void
    {
        $this->onVeo();
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get(route('projects.create'));

        $response->assertOk();
        $response->assertSee('16:9', false);
        $response->assertSee('9:16', false);

        // Offering a choice that cannot be rendered is the bug.
        $response->assertDontSee('value="1:1"', false);
    }

    public function test_an_unsupported_ratio_is_rejected_at_creation(): void
    {
        $this->onVeo();
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('projects.store'), [
            'title' => 'Square on Veo',
            'aspect_ratio' => '1:1',
            'budget_cap_usd' => '10',
            'script' => 'A short script.',
        ])->assertSessionHasErrors('aspect_ratio');

        $this->assertDatabaseMissing('projects', ['title' => 'Square on Veo']);
    }

    public function test_a_supported_ratio_is_accepted_and_the_model_is_pinned(): void
    {
        $this->onVeo();
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('projects.store'), [
            'title' => 'Portrait on Veo',
            'aspect_ratio' => '9:16',
            'budget_cap_usd' => '10',
            'script' => 'A short script.',
        ])->assertSessionHasNoErrors();

        $project = Project::where('title', 'Portrait on Veo')->firstOrFail();

        // Pinned, so a later change to the global default cannot silently
        // invalidate the ratio check that was made at creation.
        $this->assertSame('veo-3-1-fast', $project->video_model);
    }

    public function test_the_fake_model_still_allows_square(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('projects.store'), [
            'title' => 'Square on fake',
            'aspect_ratio' => '1:1',
            'budget_cap_usd' => '10',
            'script' => 'A short script.',
        ])->assertSessionHasNoErrors();
    }

    public function test_incompatibility_is_reported_with_the_supported_ratios(): void
    {
        $project = Project::factory()->create([
            'aspect_ratio' => AspectRatio::Square,
            'video_model' => 'veo-3-1-fast',
        ]);

        $reason = app(ModelRegistry::class)->incompatibilityReason($project);

        $this->assertNotNull($reason);
        $this->assertStringContainsString('1:1', $reason);
        $this->assertStringContainsString('16:9', $reason);
        $this->assertFalse(app(ModelRegistry::class)->isCompatible($project));
    }

    public function test_planning_refuses_an_incompatible_project(): void
    {
        $project = Project::factory()->create([
            'aspect_ratio' => AspectRatio::Square,
            'video_model' => 'veo-3-1-fast',
        ]);
        Scene::factory()->for($project)->create();

        try {
            app(PipelineRunner::class)->planShots($project);
            $this->fail('Planning should refuse a project the model cannot render.');
        } catch (\Throwable $e) {
            $this->assertStringContainsString('Cannot plan shots', $e->getMessage());
        }

        // Nothing queued means nothing to pay for.
        $this->assertSame(0, $project->shots()->count());
    }

    public function test_planning_proceeds_for_a_compatible_project(): void
    {
        $project = Project::factory()->create([
            'aspect_ratio' => AspectRatio::Landscape,
            'video_model' => 'veo-3-1-fast',
        ]);
        Scene::factory()->for($project)->create();

        app(PipelineRunner::class)->planShots($project);

        $this->assertGreaterThan(0, $project->shots()->count());
    }

    public function test_an_unknown_model_key_fails_loudly(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        app(ModelRegistry::class)->video('no-such-model');
    }

    public function test_the_registry_resolves_every_configured_model(): void
    {
        $registry = app(ModelRegistry::class);

        foreach ($registry->availableKeys() as $key) {
            $this->assertNotEmpty($registry->video($key)->label);
        }
    }
}
