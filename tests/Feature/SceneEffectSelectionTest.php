<?php

namespace Tests\Feature;

use App\Enums\AssetType;
use App\Models\Asset;
use App\Models\Project;
use App\Models\Scene;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Which of a scene's sound effects counts.
 *
 * A regeneration records a new asset rather than overwriting the old one, so a
 * scene can hold two. The assembler lays down the NEWEST — positionedEffects()
 * keyBy()s on scene_id and the last row wins — while soundEffectIsCurrent()
 * read the oldest. So a scene was judged on a sound nobody would hear, and
 * re-bought whenever the stale one happened not to match the cue.
 */
class SceneEffectSelectionTest extends TestCase
{
    use RefreshDatabase;

    protected function sceneWithTwoEffects(string $olderCue, string $newerCue, string $currentCue): Scene
    {
        Storage::fake('local');

        $project = Project::factory()->for(User::factory())->create();
        $scene = Scene::factory()->for($project)->create([
            'sequence' => 1,
            'sfx_cue' => $currentCue,
        ]);

        foreach ([$olderCue, $newerCue] as $description) {
            $asset = Asset::factory()->for($project)->create([
                'scene_id' => $scene->getKey(),
                'type' => AssetType::SoundEffect,
                'meta' => ['description' => $description],
            ]);

            // exists() checks the file, so both have to be real on the fake disk.
            Storage::disk($asset->disk)->put($asset->path, 'effect');
        }

        return $scene->fresh()->load('assets');
    }

    public function test_the_newest_effect_decides_whether_the_scene_is_current(): void
    {
        $scene = $this->sceneWithTwoEffects(
            olderCue: 'a crackling open fire',
            newerCue: 'a flowing river with birdsong',
            currentCue: 'a flowing river with birdsong',
        );

        $this->assertTrue(
            $scene->soundEffectIsCurrent(),
            'The scene was judged on a stale effect, so its current one would be bought again.',
        );
    }

    /**
     * The counterweight. If the newest effect is the stale one, the scene is
     * genuinely out of date however well an older asset matches.
     */
    public function test_a_stale_newest_effect_still_reads_as_out_of_date(): void
    {
        $scene = $this->sceneWithTwoEffects(
            olderCue: 'a flowing river with birdsong',
            newerCue: 'a crackling open fire',
            currentCue: 'a flowing river with birdsong',
        );

        $this->assertFalse($scene->soundEffectIsCurrent());
    }

    /**
     * And the check must not reach for the database when the relation is loaded
     * — GenerateSoundEffectsJob and the estimator both eager load `assets` to
     * avoid a query per scene, and that is the whole reason the check reads the
     * collection rather than querying.
     */
    public function test_the_check_costs_no_query_when_assets_are_loaded(): void
    {
        $scene = $this->sceneWithTwoEffects(
            olderCue: 'a crackling open fire',
            newerCue: 'a flowing river with birdsong',
            currentCue: 'a flowing river with birdsong',
        );

        $queries = 0;
        DB::listen(function () use (&$queries) {
            $queries++;
        });

        $scene->soundEffectIsCurrent();

        $this->assertSame(0, $queries, 'soundEffectIsCurrent() queried despite assets being loaded.');
    }
}
