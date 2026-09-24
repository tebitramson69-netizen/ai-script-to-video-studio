<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * The script cannot be structured at all.
 *
 * Deliberately distinct from a provider failure: nothing external went wrong and
 * retrying is pointless, because the same input fails identically. The owner has
 * to change the script.
 *
 * Thrown rather than swallowed. The previous behaviour — inventing a scene
 * called "Untitled scene" from an empty script — produced a project that looked
 * parsed and rendered nothing worth watching.
 */
class InvalidScriptException extends RuntimeException
{
    public static function empty(): self
    {
        return new self('The script is empty. Paste or upload some text before parsing.');
    }

    public static function noProse(): self
    {
        return new self(
            'The script contains no words — only punctuation, digits or symbols. '.
            'There is nothing to narrate.'
        );
    }

    public static function tooLong(int $characters, int $limit): self
    {
        return new self(sprintf(
            'The script is %s characters; the limit is %s. Split it into more than one project.',
            number_format($characters),
            number_format($limit),
        ));
    }
}
