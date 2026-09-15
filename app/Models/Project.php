<?php

namespace App\Models;

use App\Enums\AspectRatio;
use App\Enums\AssetType;
use App\Enums\ProjectStatus;
use App\Enums\ShotStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

class Project extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id', 'title', 'script', 'aspect_ratio', 'status', 'language',
        'voice_id', 'video_model', 'music_mood', 'budget_cap_usd',
        'final_asset_id', 'exported_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => ProjectStatus::class,
            'aspect_ratio' => AspectRatio::class,
            'budget_cap_usd' => 'decimal:2',
            'exported_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function scenes(): HasMany
    {
        return $this->hasMany(Scene::class)->orderBy('sequence');
    }

    public function characters(): HasMany
    {
        return $this->hasMany(Character::class)->orderBy('name');
    }

    public function shots(): HasMany
    {
        return $this->hasMany(Shot::class)->orderBy('sequence');
    }

    public function assets(): HasMany
    {
        return $this->hasMany(Asset::class);
    }

    public function usageRecords(): HasMany
    {
        return $this->hasMany(UsageRecord::class);
    }

    public function finalAsset(): BelongsTo
    {
        return $this->belongsTo(Asset::class, 'final_asset_id');
    }

    public function narrationAsset(): ?Asset
    {
        return $this->assets()->where('type', AssetType::NarrationTrack)->latest('id')->first();
    }

    public function musicAsset(): ?Asset
    {
        return $this->assets()->where('type', AssetType::Music)->latest('id')->first();
    }

    /**
     * Total actually spent on this project (PRD §11: project cost = sum of its
     * usage records). This is recorded spend, not the pre-run estimate.
     */
    public function spentUsd(): float
    {
        return (float) $this->usageRecords()->sum('cost_usd');
    }

    public function remainingBudgetUsd(): float
    {
        return max(0.0, (float) $this->budget_cap_usd - $this->spentUsd());
    }

    /**
     * PRD §8: export is blocked while any shot is stale — or, more broadly,
     * while any shot is not rendered.
     */
    public function hasStaleShots(): bool
    {
        return $this->shots()->where('status', ShotStatus::Stale)->exists();
    }

    public function unrenderedShotCount(): int
    {
        return $this->shots()->where('status', '!=', ShotStatus::Rendered)->count();
    }

    public function isExportable(): bool
    {
        return $this->status->isAtLeast(ProjectStatus::VoiceReady)
            && $this->shots()->exists()
            && $this->unrenderedShotCount() === 0
            && $this->narrationAsset() !== null;
    }

    public function isAtLeast(ProjectStatus $status): bool
    {
        return $this->status->isAtLeast($status);
    }

    /**
     * Where every asset for this project lives. Kept in one place because both
     * the recorder (writing) and deletion/purge (reclaiming) need to agree on it.
     */
    public function storageDirectory(): string
    {
        return 'studio/projects/'.$this->getKey();
    }

    /**
     * Total bytes this project occupies on disk (NFR-7: "show storage used").
     */
    public function storageBytes(): int
    {
        return (int) $this->assets()->sum('bytes');
    }

    /**
     * Bytes that could be reclaimed by purging intermediate shot clips after a
     * successful export. Final videos and locked references are never counted.
     */
    public function purgeableBytes(): int
    {
        return (int) $this->assets()
            ->where('type', AssetType::ShotClip)
            ->sum('bytes');
    }

    protected static function booted(): void
    {
        // The FK cascade deletes asset ROWS but never fires Eloquent events, so
        // without this every clip, reference image and export would stay on disk
        // forever after the project was deleted.
        static::deleting(function (self $project) {
            Storage::disk(config('studio.disk', 'local'))
                ->deleteDirectory($project->storageDirectory());
        });
    }
}
