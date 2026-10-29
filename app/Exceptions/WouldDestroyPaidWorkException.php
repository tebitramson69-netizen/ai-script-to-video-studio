<?php

namespace App\Exceptions;

use App\Enums\ShotStatus;
use App\Models\Shot;
use Illuminate\Support\Collection;
use RuntimeException;

/**
 * Thrown when an edit would delete shots whose clips have already been paid for.
 *
 * Two paths could do this without asking. Re-parsing the script runs
 * `$project->scenes()->delete()`, and deleting a scene removes one row — and
 * `shots.scene_id` is cascadeOnDelete(), so either takes every shot of those
 * scenes with it at the database level. Neither fires an Eloquent event, so
 * nothing cleans up and nothing warns.
 *
 * What is lost is not the file. `shots.asset_id` is the only thing pointing at
 * a rendered clip, so the clip survives on disk as an orphan while the project
 * forgets it exists. `usage_records` survive too, which is the worst half: the
 * cap still counts the money, so a re-render is refused part-way through for
 * lack of budget that was spent on clips nobody can reach.
 *
 * Refusing by default is the right trade. Re-parsing after a render is a rare,
 * deliberate act, and the expensive mistake here is the silent one. The code
 * already took this view for characters — StructureScriptJob upserts them so
 * "a re-parse must not destroy a locked canonical reference the owner has
 * already paid for" — while the shots, at forty times the price, were left to
 * a foreign key.
 */
class WouldDestroyPaidWorkException extends RuntimeException
{
    /**
     * @param  Collection<int, Shot>  $shots  shots in ShotStatus::carryingPaidWork()
     */
    public function __construct(public readonly Collection $shots, string $action)
    {
        parent::__construct($action.' '.self::describe($shots));
    }

    /**
     * @param  Collection<int, Shot>  $shots
     */
    protected static function describe(Collection $shots): string
    {
        $rendered = $shots->where('status', ShotStatus::Rendered);
        $inFlight = $shots->where('status', ShotStatus::Rendering);

        $parts = [];

        if ($rendered->isNotEmpty()) {
            $parts[] = sprintf(
                '%d rendered clip(s) costing $%.2f',
                $rendered->count(),
                (float) $rendered->sum('cost_usd'),
            );
        }

        // Deliberately not given a figure. cost_usd is only written when the
        // clip lands, so an in-flight shot reads $0.00 while the provider is
        // already billing for it — quoting that would understate the loss.
        if ($inFlight->isNotEmpty()) {
            $parts[] = sprintf(
                '%d clip(s) still rendering, whose charge is not recorded yet',
                $inFlight->count(),
            );
        }

        return sprintf(
            'would delete %s. The files would stay on disk with nothing pointing at them, '.
            'and the spend stays on this project\'s ledger — so the video would have to be '.
            'rendered again at full price. Do this only if you mean to discard them.',
            implode(' and ', $parts),
        );
    }
}
