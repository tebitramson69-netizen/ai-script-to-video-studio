<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Scene extends Model
{
    use HasFactory;

    protected $fillable = ['project_id', 'sequence', 'setting', 'narration', 'mood', 'action'];

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

    public function wordCount(): int
    {
        return str_word_count(strip_tags((string) $this->narration));
    }
}
