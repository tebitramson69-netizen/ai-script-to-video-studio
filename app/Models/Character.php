<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Character extends Model
{
    use HasFactory;

    protected $fillable = [
        'project_id', 'name', 'description', 'canonical_reference_asset_id', 'locked_at',
    ];

    protected function casts(): array
    {
        return ['locked_at' => 'datetime'];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function canonicalReference(): BelongsTo
    {
        return $this->belongsTo(Asset::class, 'canonical_reference_asset_id');
    }

    public function shots(): BelongsToMany
    {
        return $this->belongsToMany(Shot::class);
    }

    public function isLocked(): bool
    {
        return $this->canonical_reference_asset_id !== null;
    }
}
