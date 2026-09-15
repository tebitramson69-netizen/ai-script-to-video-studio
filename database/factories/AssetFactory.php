<?php

namespace Database\Factories;

use App\Enums\AssetType;
use App\Models\Asset;
use App\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Asset>
 */
class AssetFactory extends Factory
{
    protected $model = Asset::class;

    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'type' => AssetType::ShotClip,
            'disk' => 'local',
            'path' => 'studio/test/'.$this->faker->uuid().'.mp4',
            'mime' => 'video/mp4',
            'duration_seconds' => 8.0,
            'model' => 'fake',
            'cost_usd' => 0,
        ];
    }

    public function type(AssetType $type): static
    {
        return $this->state(fn () => ['type' => $type]);
    }
}
