<?php

namespace Database\Factories;

use App\Enums\ShotStatus;
use App\Models\Project;
use App\Models\Scene;
use App\Models\Shot;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Shot>
 */
class ShotFactory extends Factory
{
    protected $model = Shot::class;

    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'scene_id' => Scene::factory(),
            'sequence' => 1,
            'prompt' => 'a riverbank. cinematic.',
            'narration_segment' => 'The river was calm that morning.',
            'model' => 'fake',
            'seed' => 1234,
            'target_duration_seconds' => 8.0,
            'narration_duration_seconds' => 5.0,
            'status' => ShotStatus::Pending,
        ];
    }

    public function rendered(): static
    {
        return $this->state(fn () => [
            'status' => ShotStatus::Rendered,
            'rendered_at' => now(),
        ]);
    }
}
