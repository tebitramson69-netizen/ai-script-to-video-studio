<?php

namespace App\Models;

use App\Enums\AssetType;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
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

    public function soundEffectAsset(): ?Asset
    {
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
     * Reads the loaded relation rather than querying, so a caller that eager
     * loads `assets` pays nothing per scene.
     */
    public function soundEffectIsCurrent(): bool
    {
        $effect = $this->assets->firstWhere('type', AssetType::SoundEffect);

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

    public function wordCount(): int
    {
        return str_word_count(strip_tags((string) $this->narration));
    }
}
