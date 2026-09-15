<?php

namespace App\Models;

use App\Enums\AssetType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class Asset extends Model
{
    use HasFactory;

    protected $fillable = [
        'project_id', 'type', 'disk', 'path', 'mime', 'bytes',
        'duration_seconds', 'model', 'cost_usd', 'meta',
    ];

    protected function casts(): array
    {
        return [
            'type' => AssetType::class,
            'meta' => 'array',
            'duration_seconds' => 'float',
            'cost_usd' => 'decimal:4',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * Absolute path on disk. Needed because FFmpeg takes filesystem paths, not
     * stream wrappers.
     */
    public function absolutePath(): string
    {
        return Storage::disk($this->disk)->path($this->path);
    }

    public function exists(): bool
    {
        return Storage::disk($this->disk)->exists($this->path);
    }

    /**
     * Remove the underlying file. Called when an asset is genuinely superseded
     * (a regenerated narration track) or purged (NFR-7).
     *
     * The usage_record that recorded what this asset cost survives, because
     * usage_records.asset_id is nullOnDelete — so reclaiming disk space never
     * erases spend history.
     */
    public function deleteFile(): void
    {
        if ($this->path !== '' && $this->exists()) {
            Storage::disk($this->disk)->delete($this->path);
        }
    }

    protected static function booted(): void
    {
        // Covers single-model deletes. Whole-project deletion is handled on the
        // Project model instead, because a database-level FK cascade does not
        // fire Eloquent events and would otherwise orphan every file.
        static::deleted(fn (self $asset) => $asset->deleteFile());
    }
}
