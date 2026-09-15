<?php

namespace Database\Factories;

use App\Models\Project;
use App\Models\Scene;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Scene>
 */
class SceneFactory extends Factory
{
    protected $model = Scene::class;

    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'sequence' => 1,
            'setting' => 'a riverbank',
            'narration' => 'The river was calm that morning.',
            'mood' => 'neutral',
        ];
    }
}
