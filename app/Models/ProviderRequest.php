<?php

namespace App\Models;

use App\Enums\ProviderFailureReason;
use App\Enums\ProviderRequestStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProviderRequest extends Model
{
    use HasFactory;

    protected $fillable = [
        'project_id', 'capability', 'provider', 'provider_model',
        'provider_request_id', 'fingerprint', 'status', 'asset_id',
        'estimated_cost_usd', 'actual_cost_usd', 'request_payload',
        'provider_response', 'output_url', 'failure_reason', 'error_message',
        'attempts', 'submitted_at', 'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => ProviderRequestStatus::class,
            'failure_reason' => ProviderFailureReason::class,
            'request_payload' => 'array',
            'provider_response' => 'array',
            'estimated_cost_usd' => 'decimal:4',
            'actual_cost_usd' => 'decimal:4',
            'submitted_at' => 'datetime',
            'completed_at' => 'datetime',
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

    /**
     * What this request cost, preferring the provider's own figure.
     *
     * The estimate is a planning number; only the provider knows the charge.
     * Falling back to the estimate keeps project spend approximately right
     * while reconciliation is still pending.
     */
    public function effectiveCostUsd(): float
    {
        return (float) ($this->actual_cost_usd ?? $this->estimated_cost_usd);
    }

    /**
     * Difference between what we predicted and what we were charged. Persistent
     * drift here means the rates in config/studio.php are wrong.
     */
    public function costVarianceUsd(): ?float
    {
        if ($this->actual_cost_usd === null) {
            return null;
        }

        return round((float) $this->actual_cost_usd - (float) $this->estimated_cost_usd, 4);
    }

    public function isActive(): bool
    {
        return $this->status->isActive();
    }

    public function isRetryable(): bool
    {
        return $this->failure_reason?->isRetryable() ?? false;
    }

    /**
     * @param  Builder<self>  $query
     */
    public function scopeOutstanding($query)
    {
        return $query->whereIn('status', [
            ProviderRequestStatus::Pending->value,
            ProviderRequestStatus::InQueue->value,
            ProviderRequestStatus::InProgress->value,
        ]);
    }
}
