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
}
