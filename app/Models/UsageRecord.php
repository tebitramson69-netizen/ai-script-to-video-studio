<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UsageRecord extends Model
{
    use HasFactory;

    protected $fillable = [
        'project_id', 'asset_id', 'provider', 'model', 'operation',
        'units', 'unit', 'cost_usd', 'duration_ms', 'outcome', 'meta',
    ];

    protected function casts(): array
    {
        return [
            'meta' => 'array',
            'units' => 'float',
            'cost_usd' => 'decimal:4',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }
}
