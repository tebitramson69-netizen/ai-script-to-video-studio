<?php

namespace Database\Factories;

use App\Enums\AspectRatio;
use App\Enums\ProjectStatus;
use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Project>
 */
class ProjectFactory extends Factory
{
    protected $model = Project::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'title' => $this->faker->sentence(3),
            'script' => 'A short test script. It has two sentences.',
            'aspect_ratio' => AspectRatio::Landscape,
            'status' => ProjectStatus::Draft,
            'language' => 'en',
            'budget_cap_usd' => 15.00,
        ];
    }

    public function status(ProjectStatus $status): static
    {
        return $this->state(fn () => ['status' => $status]);
    }

    public function budget(float $cap): static
    {
        return $this->state(fn () => ['budget_cap_usd' => $cap]);
    }
}
