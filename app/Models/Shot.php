<?php

namespace App\Models;

use App\Enums\ShotStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Shot extends Model
{
    use HasFactory;

    protected $fillable = [
        'project_id', 'scene_id', 'sequence', 'prompt', 'narration_segment',
        'model', 'seed', 'target_duration_seconds', 'narration_duration_seconds',
        'asset_id', 'narration_asset_id', 'status', 'cost_usd', 'attempts', 'error', 'rendered_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => ShotStatus::class,
            'target_duration_seconds' => 'float',
            'narration_duration_seconds' => 'float',
            'cost_usd' => 'decimal:4',
            'rendered_at' => 'datetime',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function scene(): BelongsTo
    {
        return $this->belongsTo(Scene::class);
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    public function narrationAsset(): BelongsTo
    {
        return $this->belongsTo(Asset::class, 'narration_asset_id');
    }

    public function characters(): BelongsToMany
    {
        return $this->belongsToMany(Character::class);
    }

    /**
     * Whether this shot has a character reference to start an image-to-video
     * render from (FR-6).
     *
     * Attached-but-unlocked is the same as absent: a character with no
     * canonical reference has no frame to hand the model. This is what decides
     * which model renders the shot, so the distinction has to be exact.
     */
    public function hasLockedReference(): bool
    {
        return $this->characters
            ->contains(fn (Character $character) => $character->canonical_reference_asset_id !== null);
    }

    /**
     * How long this shot occupies in the final timeline. Narration is the master
     * clock (FR-16), so the clip is trimmed or held to it (FR-18); we fall back
     * to the target length only if narration duration is not yet known.
     */
    public function timelineDurationSeconds(): float
    {
        return (float) ($this->narration_duration_seconds ?: $this->target_duration_seconds);
    }

    /**
     * Seconds of clip bought beyond what this shot's narration needs, and
     * therefore trimmed off at assembly (FR-18).
     *
     * Mirrors ShotPlan::slackSeconds() on the persisted row. Clamped at zero per
     * shot, which is the whole point: measured narration can OVERRUN its target,
     * because the target was rounded up from an estimate. Netting an overrun
     * against another shot's surplus understates the money actually spent on
     * frames nobody sees - the one thing the figure exists to show.
     */
    public function slackSeconds(): float
    {
        return round(max(0.0, (float) $this->target_duration_seconds - $this->timelineDurationSeconds()), 2);
    }

    public function isRendered(): bool
    {
        return $this->status === ShotStatus::Rendered;
    }
}
