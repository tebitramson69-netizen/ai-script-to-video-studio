<?php

namespace Tests\Feature;

use App\Models\Asset;
use App\Models\Project;
use App\Models\Scene;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * §14: authentication even for a single-user tool, and generation endpoints
 * that are never left open.
 */
class AuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_login(): void
    {
        $project = Project::factory()->create();

        $this->get(route('projects.index'))->assertRedirect(route('login'));
        $this->get(route('projects.show', $project))->assertRedirect(route('login'));
    }

    public function test_guests_cannot_trigger_generation(): void
    {
        $project = Project::factory()->create();

        // These spend money. They must not be reachable without a session.
        foreach ([
            route('pipeline.parse', $project),
            route('pipeline.characters', $project),
            route('pipeline.plan', $project),
            route('pipeline.render', $project),
            route('pipeline.audio', $project),
            route('pipeline.export', $project),
        ] as $url) {
            $this->post($url)->assertRedirect(route('login'));
        }
    }

    public function test_a_user_cannot_view_another_users_project(): void
    {
        $intruder = User::factory()->create();
        $project = Project::factory()->create();

        $this->actingAs($intruder)
            ->get(route('projects.show', $project))
            ->assertForbidden();
    }

    public function test_a_user_cannot_spend_another_users_budget(): void
    {
        $intruder = User::factory()->create();
        $project = Project::factory()->create();

        $this->actingAs($intruder)
            ->post(route('pipeline.render', $project))
            ->assertForbidden();
    }

    public function test_a_user_cannot_read_another_users_generated_media(): void
    {
        $intruder = User::factory()->create();
        $project = Project::factory()->create();
        $asset = Asset::factory()->for($project)->create();

        $this->actingAs($intruder)
            ->get(route('assets.show', [$project, $asset]))
            ->assertForbidden();
    }

    public function test_an_asset_from_another_project_cannot_be_read_through_your_own(): void
    {
        $user = User::factory()->create();
        $mine = Project::factory()->for($user)->create();
        $theirs = Project::factory()->create();
        $theirAsset = Asset::factory()->for($theirs)->create();

        // The policy passes (the URL names a project I own) — the ownership
        // check on the asset itself is what stops this.
        $this->actingAs($user)
            ->get(route('assets.show', [$mine, $theirAsset]))
            ->assertNotFound();
    }

    public function test_a_scene_from_another_project_cannot_be_edited_through_your_own(): void
    {
        $user = User::factory()->create();
        $mine = Project::factory()->for($user)->create();
        $theirs = Project::factory()->create();
        $theirScene = Scene::factory()->for($theirs)->create();

        $this->actingAs($user)
            ->patch(route('scenes.update', [$mine, $theirScene]), [
                'setting' => 'hijacked',
                'narration' => 'hijacked',
            ])
            ->assertNotFound();

        $this->assertSame('a riverbank', $theirScene->fresh()->setting);
    }

    public function test_state_changing_routes_sit_in_the_csrf_protected_web_group(): void
    {
        // Laravel short-circuits CSRF validation while running tests, so a 419
        // can never be provoked here. The verifiable invariant — and the one a
        // refactor could silently break — is that these routes stay inside the
        // `web` group, which is what applies ValidateCsrfToken (§14).
        foreach (['pipeline.render', 'pipeline.audio', 'pipeline.export', 'shots.regenerate'] as $name) {
            $route = app('router')->getRoutes()->getByName($name);

            $this->assertNotNull($route, "Route {$name} is missing.");
            $this->assertContains('web', $route->gatherMiddleware(), "Route {$name} escaped the web group.");
            $this->assertContains('auth', $route->gatherMiddleware(), "Route {$name} is not behind auth.");
        }
    }

    public function test_no_generation_route_is_reachable_by_a_get(): void
    {
        // A money-spending endpoint behind GET can be fired by an <img> tag.
        foreach (app('router')->getRoutes() as $route) {
            if (! str_starts_with((string) $route->getName(), 'pipeline.')) {
                continue;
            }

            $this->assertNotContains(
                'GET',
                $route->methods(),
                "Route {$route->getName()} must not be reachable by GET.",
            );
        }
    }

    public function test_login_is_rate_limited(): void
    {
        $user = User::factory()->create(['email' => 'owner@example.test']);

        for ($i = 0; $i < 5; $i++) {
            $this->post(route('login'), [
                'email' => 'owner@example.test',
                'password' => 'wrong-password',
            ]);
        }

        $this->post(route('login'), [
            'email' => 'owner@example.test',
            'password' => 'wrong-password',
        ])->assertSessionHasErrors('email');

        $this->assertStringContainsString(
            'Too many login attempts',
            session('errors')->first('email'),
        );
    }

    public function test_there_is_no_public_registration_route(): void
    {
        // NG2: this is not a multi-user SaaS with public sign-ups.
        $this->assertFalse(
            app('router')->getRoutes()->hasNamedRoute('register'),
            'A public registration route would contradict NG2 and §14.',
        );
    }
}
