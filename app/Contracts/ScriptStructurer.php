<?php

namespace App\Contracts;

use App\Contracts\Data\ScriptBreakdown;

/**
 * Turns a free-form script into an ordered scene list and a character list
 * (FR-1, FR-3, FR-4).
 *
 * Security note (§14): the script is user content. An LLM-backed implementation
 * MUST pass it as data inside a delimited block and must not concatenate it into
 * the instruction portion of the prompt.
 */
interface ScriptStructurer
{
    public function structure(string $script, string $language = 'en'): ScriptBreakdown;

    public function providerName(): string;
}
