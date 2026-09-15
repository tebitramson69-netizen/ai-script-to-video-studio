<?php

namespace App\Enums;

enum ShotStatus: string
{
    case Pending = 'pending';
    case Queued = 'queued';
    case Rendering = 'rendering';
    case Rendered = 'rendered';
    case Failed = 'failed';

    /**
     * Rendered, but an upstream artifact changed after it was made (PRD §8
     * invalidation rule). The clip is kept, not deleted — but export is blocked
     * while any shot is stale.
     */
    case Stale = 'stale';

    public function isTerminal(): bool
    {
        return in_array($this, [self::Rendered, self::Failed], true);
    }

    /**
     * Does this shot still need work before the project can be exported?
     */
    public function blocksExport(): bool
    {
        return $this !== self::Rendered;
    }

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Not rendered',
            self::Queued => 'Queued',
            self::Rendering => 'Rendering',
            self::Rendered => 'Rendered',
            self::Failed => 'Failed',
            self::Stale => 'Stale — upstream changed',
        };
    }
}
