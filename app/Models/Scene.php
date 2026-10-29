<?php

namespace App\Models;

use App\Enums\AssetType;
use App\Enums\ShotStatus;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Scene extends Model
{
    use HasFactory;

    protected $fillable = ['project_id', 'sequence', 'setting', 'narration', 'mood', 'action', 'sfx_cue'];

    /**
     * The cast the structurer attributed to this scene (FR-4).
     *
     * Authoritative. PlanShotsJob reads this rather than searching the narration
     * text, because stripping a dialogue cue removes the very name such a search
     * would have matched.
     */
    public function characters(): BelongsToMany
    {
        return $this->belongsToMany(Character::class)->orderBy('name');
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function shots(): HasMany
    {
        return $this->hasMany(Shot::class)->orderBy('sequence');
    }

    /**
     * Assets that belong to this scene rather than to the whole project.
     *
     * Today that means sound effects (FR-13). Narration and music are
     * project-wide and never appear here.
     */
    public function assets(): HasMany
    {
        return $this->hasMany(Asset::class);
    }

    /**
     * This scene's sound effect: the newest, when more than one exists.
     *
     * Which one is not a detail. A scene can end up holding two effects — a
     * regeneration records a new asset rather than overwriting the old one —
     * and the assembler lays down the newest, because positionedEffects()
     * keyBy()s on scene_id and the last row wins. Anything that asks "is this
     * scene's effect still right?" has to inspect the same asset, or it
     * answers about a sound nobody will hear.
     *
     * Reads the loaded relation when there is one, so a caller that eager loads
     * `assets` pays no query per scene — which is what GenerateSoundEffectsJob
     * and the estimator both rely on.
     */
    public function soundEffectAsset(): ?Asset
    {
        if ($this->relationLoaded('assets')) {
            return $this->assets
                ->where('type', AssetType::SoundEffect)
                ->sortByDesc('id')
                ->first();
        }

        return $this->assets()->where('type', AssetType::SoundEffect)->latest('id')->first();
    }

    /**
     * Scenes the structurer heard ambience in.
     *
     * One definition, because three places need it and they must agree: the job
     * that buys the effects, the estimate that prices what is left, and the gate
     * on a forced regeneration. They were written out separately and had already
     * drifted.
     */
    #[Scope]
    protected function cued(Builder $query): void
    {
        $query->whereNotNull('sfx_cue')->where('sfx_cue', '!=', '');
    }

    /**
     * Does this scene occupy any of the finished timeline?
     *
     * A cued scene with no shots buys nothing, so pricing one would gate a run
     * on money that is never spent.
     */
    public function hasTimeline(): bool
    {
        return $this->timelineDurationSeconds() > 0;
    }

    /**
     * Does this scene already have an effect worth keeping?
     *
     * Deliberately laxer than the music check, which demands the track match the
     * timeline within a second. An effect is LOOPED to cover its scene, so a
     * scene growing by three seconds does not invalidate it — the loop simply
     * runs a little longer. What does invalidate it is the cue changing, because
     * then the sound itself is wrong.
     *
     * Reads through soundEffectAsset(), which uses the loaded relation when
     * there is one — so a caller that eager loads `assets` pays nothing per
     * scene.
     */
    public function soundEffectIsCurrent(): bool
    {
        // soundEffectAsset(), not firstWhere(): this used to read the OLDEST
        // effect while the assembler lays down the newest, so a scene holding
        // two of them was judged on a sound that would never be heard — and
        // re-bought whenever the stale one happened not to match the cue.
        $effect = $this->soundEffectAsset();

        if (! $effect instanceof Asset || ! $effect->exists()) {
            return false;
        }

        return (string) ($effect->meta['description'] ?? '') === (string) $this->sfx_cue;
    }

    /**
     * How long this scene occupies the finished timeline.
     *
     * The sum of its shots' timeline durations, which is what the assembler
     * actually lays down — not the planned lengths, because a clip is trimmed or
     * held to its measured narration (FR-18).
     */
    public function timelineDurationSeconds(): float
    {
        return (float) $this->shots->sum(fn (Shot $shot) => $shot->timelineDurationSeconds());
    }

    protected static function booted(): void
    {
        // Delete a scene's own assets through Eloquent so the Asset model's
        // deleted() hook runs and takes the files with them. The database
        // constraint only nulls the column, and a null column with a file still
        // on disk is a leak NFR-7's purge would never find.
        static::deleting(fn (self $scene) => $scene->assets()->get()->each->delete());
    }

    /**
     * Shots whose clip has been bought, or is being bought right now.
     *
     * Read before anything deletes this scene, because the row is the only
     * link to the clip (ShotStatus::carryingPaidWork()).
     *
     * @return Collection<int, Shot>
     */
    public function paidShots(): EloquentCollection
    {
        return $this->shots()
            ->whereIn('status', ShotStatus::carryingPaidWorkValues())
            ->get();
    }

    public function wordCount(): int
    {
        return str_word_count(strip_tags((string) $this->narration));
    }
}
