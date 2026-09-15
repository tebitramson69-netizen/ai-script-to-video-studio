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
     * How long this shot occupies in the final timeline. Narration is the master
     * clock (FR-16), so the clip is trimmed or held to it (FR-18); we fall back
     * to the target length only if narration duration is not yet known.
     */
    public function timelineDurationSeconds(): float
    {
        return (float) ($this->narration_duration_seconds ?: $this->target_duration_seconds);
    }

    public function isRendered(): bool
    {
        return $this->status === ShotStatus::Rendered;
    }
}
