<?php

namespace App\Services\Script;

use App\Contracts\Data\CharacterDraft;
use App\Contracts\Data\SceneDraft;
use App\Contracts\Data\ScriptBreakdown;
use LogicException;

/**
 * Turns the contract's "guaranteed by the schema" section into something
 * enforced rather than merely documented (docs/SCRIPT-STRUCTURER.md §2).
 *
 * It runs inside the structurer, on the structurer's own output, immediately
 * before returning. That placement is the point: every field here is produced by
 * a heuristic, and a heuristic that goes wrong should fail loudly at its own
 * boundary rather than hand the pipeline a scene with empty narration, a mood
 * the prompt builder will interpolate blindly, or a character name that matches
 * no character.
 *
 * A failure is a LogicException, not a domain exception. Bad input is
 * InvalidScriptException and is the owner's to fix; a breakdown that violates
 * its own schema is our bug and should reach a developer.
 */
class ScriptBreakdownValidator
{
    public const MAX_SETTING_LENGTH = 200;

    public const MAX_NAME_LENGTH = 40;

    public const MAX_CHARACTERS = 6;

    public function validate(ScriptBreakdown $breakdown): ScriptBreakdown
    {
        $this->validateShape($breakdown);
        $names = $this->validateCharacters($breakdown->characters);

        foreach ($breakdown->scenes as $index => $scene) {
            $this->validateScene($scene, $index + 1, $names);
        }

        return $breakdown;
    }

    protected function validateShape(ScriptBreakdown $breakdown): void
    {
        $maxScenes = (int) config('studio.limits.max_scenes', 40);

        if ($breakdown->scenes === []) {
            throw new LogicException(
                'The breakdown has no scenes. An unparseable script must raise '.
                'InvalidScriptException; it must never yield an empty breakdown.'
            );
        }

        if (count($breakdown->scenes) > $maxScenes) {
            throw new LogicException(sprintf(
                'The breakdown has %d scenes; the cap is %d. The splitter must merge '.
                'the remainder into the final scene rather than exceed the cap.',
                count($breakdown->scenes),
                $maxScenes,
            ));
        }

        foreach ($breakdown->warnings as $warning) {
            if (! is_string($warning) || trim($warning) === '') {
                throw new LogicException('Warnings must be non-empty strings.');
            }
        }
    }

    /**
     * @param  list<CharacterDraft>  $characters
     * @return list<string>
     */
    protected function validateCharacters(array $characters): array
    {
        if (count($characters) > self::MAX_CHARACTERS) {
            throw new LogicException(sprintf(
                'The breakdown names %d characters; the cap is %d. Every character is '.
                'reference images the owner pays for (FR-5).',
                count($characters),
                self::MAX_CHARACTERS,
            ));
        }

        $names = [];

        foreach ($characters as $character) {
            $name = $character->name;

            if (trim($name) === '') {
                throw new LogicException('A character has an empty name.');
            }

            if ($name !== trim($name)) {
                throw new LogicException("Character name '{$name}' is not trimmed.");
            }

            if (mb_strlen($name) > self::MAX_NAME_LENGTH) {
                throw new LogicException(
                    "Character name '{$name}' exceeds ".self::MAX_NAME_LENGTH.' characters.'
                );
            }

            if (in_array($name, $names, true)) {
                throw new LogicException(
                    "Character '{$name}' appears twice. Names must be unique after canonicalisation."
                );
            }

            if ($character->description !== null && trim($character->description) === '') {
                throw new LogicException(
                    "Character '{$name}' has a blank description. Use null for absent, not an empty string."
                );
            }

            $names[] = $name;
        }

        return $names;
    }

    /**
     * @param  list<string>  $knownNames
     */
    protected function validateScene(SceneDraft $scene, int $position, array $knownNames): void
    {
        $where = "Scene {$position}";

        if (trim($scene->setting) === '') {
            throw new LogicException("{$where} has an empty setting.");
        }

        if (mb_strlen($scene->setting) > self::MAX_SETTING_LENGTH) {
            throw new LogicException(
                "{$where} setting exceeds ".self::MAX_SETTING_LENGTH.' characters.'
            );
        }

        if (trim($scene->narration) === '') {
            throw new LogicException("{$where} has empty narration; there would be nothing to narrate.");
        }

        if ($scene->narration !== trim($scene->narration)) {
            throw new LogicException("{$where} narration has leading or trailing whitespace.");
        }

        if (preg_match('/\n\s*\n/', $scene->narration)) {
            throw new LogicException(
                "{$where} narration contains a blank line. Scenes are split on blank lines, ".
                'so one surviving inside a scene means the splitter let it through.'
            );
        }

        // The rule that stops the narrator saying "Ada colon, where is the boat".
        if (preg_match('/^\s*\p{Lu}[\p{L}\x27 .-]{0,28}\s*[:—]/mu', $scene->narration, $cue)) {
            throw new LogicException(sprintf(
                '%s narration still carries the dialogue cue "%s". One voice narrates '.
                'everything (FR-14), so an unstripped cue is read aloud.',
                $where,
                trim($cue[0]),
            ));
        }

        if ($scene->action !== null && trim($scene->action) === '') {
            throw new LogicException(
                "{$where} has a blank action. Use null for absent, not an empty string."
            );
        }

        $seen = [];

        foreach ($scene->characterNames as $name) {
            if (! in_array($name, $knownNames, true)) {
                throw new LogicException(sprintf(
                    '%s lists character "%s", which is not in the breakdown\'s cast. '.
                    'Scene attribution must be referentially intact — it is persisted and '.
                    'read back as the shot\'s cast.',
                    $where,
                    $name,
                ));
            }

            if (in_array($name, $seen, true)) {
                throw new LogicException("{$where} lists character \"{$name}\" twice.");
            }

            $seen[] = $name;
        }
    }
}
