# Script Structurer — v1 contract

Stage 1 of the pipeline (PRD §8, FR-1/FR-3/FR-4): a free-form script becomes an
ordered scene list plus a detected cast.

**v1 is deterministic and local.** No LLM, no network, no cost, no API key. The
same script always produces the same breakdown, which is what makes it testable
and what makes it impossible to prompt-inject with the script it is parsing
(§14). `ScriptStructurer` remains an interface, so an LLM driver can be added
later as a config line — but nothing in the pipeline requires one.

Driver: `studio.script_structurer = 'heuristic'` →
`App\Integrations\Local\HeuristicScriptStructurer`.

---

## 1. Input

| | |
|---|---|
| `string $script` | UTF-8 plain text, free-form. Screenplay conventions are recognised but not required. |
| `string $language` | Language tag, default `en`. Recorded and passed through. **v1's keyword lists are English only** — see §7. |

Accepted shapes, all valid:

- prose paragraphs separated by blank lines
- screenplay slug lines (`INT. KITCHEN — DAY`)
- dialogue cues (`ADA: Where is the boat?`)
- a single unbroken block of text
- any mixture of the above

## 2. Output

`ScriptBreakdown { scenes, characters, warnings }`.

### Guaranteed by the schema

These hold for **every** breakdown the structurer returns. They are asserted by
`ScriptBreakdownValidator`, which runs inside the structurer before it returns —
so a bug in any heuristic surfaces as a thrown exception here, never as a
malformed breakdown reaching the pipeline.

**Breakdown**
- `1 ≤ count(scenes) ≤ studio.limits.max_scenes`
- `0 ≤ count(characters) ≤ 6`
- character names are unique after canonicalisation
- `warnings` is a list of human-readable strings (may be empty)

**Every `SceneDraft`**
- `setting` — non-empty, trimmed, ≤ 200 characters
- `narration` — non-empty, trimmed, **contains no dialogue cue labels**, no
  blank lines, no leading or trailing whitespace
- `mood` — a `SceneMood` enum case, never null and never a free string
- `action` — non-empty trimmed string, or `null`
- `characterNames` — a list of strings, each one **present in the breakdown's
  `characters`** (referential integrity), no duplicates, in first-appearance
  order
- `sfxCue` — `null`, or a non-blank trimmed string of ≤ 120 characters. **Never
  the empty string**: `null` means "this scene has no ambience" and `''` would
  reach the sound-effect provider as a prompt and be paid for

**Every `CharacterDraft`**
- `name` — non-empty, canonicalised (`Ucfirst` of lowercase), ≤ 40 characters
- `description` — a phrase **describing the person**, or `null`. Never a
  sentence lifted from the script

### Best-effort (heuristic — the owner edits these, FR-3/FR-4)

- **where scene boundaries fall**
- **which words are character names**
- the `mood` label chosen from the closed set
- the `setting` label
- the `action` line
- the character `description`, which is matched against four narrow shapes and
  returns `null` for everything else:

  | shape | example | result |
  | --- | --- | --- |
  | appositive | `Ada, a young trader, walked…` | `a young trader` |
  | copula | `Ada was a tall woman…` | `a tall woman` |
  | role before the name | `Her brother Kofi never waited.` | `Her brother` |
  | introduced by name | `there lived a girl named Ada` | `a girl` |

  Every match must also contain a word from a closed list of people
  (`CastDetector::PERSON_WORDS`). That gate is what stops `Ada was a long way
  from home` — which matches the copula shape perfectly — from becoming a
  description. **`null` is the safe answer and a common one**, because prose
  frequently never says what anyone looks like.

  This field feeds the character reference prompt directly, so a wrong value is
  not cosmetic: it is a portrait generated from it, paid for at full price. It
  previously returned the whole first sentence containing the name, which asked
  the image model for `Character reference portrait of Ada. Thunder rolled
  somewhere behind the hills, and Ada lay awake counting the drops against the
  tin roof.`
- **whether a scene has an `sfxCue` at all, and which one**

`sfxCue` is the one heuristic whose false positives cost money directly: effects
are billed per effect, so every cue is a charge, and a cue in a scene that names
no sound buys something that does not belong in the video. It is therefore the
only label here that **never falls back to a default** — `setting` returns
"Scene 3" because a scene must render somewhere, but a cue returns `null`, and
`null` is the common case. Matching is whole-word against a closed map of
sustained ambience (rain, market, river, fire, crowd…), so "firewood" does not
cue a fire and "brainstorm" does not cue a storm.

The distinction matters: downstream code may rely on the guarantees without
checking. It must not rely on the heuristics being *right* — only on them being
well-formed. That is why the owner reviews the breakdown before anything is
rendered.

## 3. Scene-splitting rules

Applied in order. Earlier rules win.

1. **Normalise.** CRLF → LF, collapse runs of spaces and tabs, collapse three or
   more newlines to two, trim.
2. **Explicit markers start a new scene**, wherever they appear:
   - a slug line: `INT.` / `EXT.` / `INT ` / `EXT ` at line start
   - `SCENE 3`, `Scene 3:` at line start
   - a horizontal rule: a line of three or more `-`, `=` or `*`
3. **Otherwise, blank-line-separated paragraphs are scenes.** A writer's blank
   lines are the strongest scene signal available without understanding the text.
4. **Long scenes are split on sentence boundaries.** Any scene over
   `MAX_WORDS_PER_SCENE` (60) is divided into chunks of about
   `TARGET_WORDS_PER_SCENE` (35), **never mid-sentence** — narration that stops
   mid-sentence sounds broken no matter how good the voice is.
5. **The scene cap never discards words.** If splitting yields more than
   `max_scenes`, the remainder is **merged into the final scene** and a warning
   is recorded. Dropping the tail would silently lose the author's script; a
   long final scene is handled downstream, because `ShotPlanner` splits a scene
   across several shots anyway (FR-17).

## 4. Dialogue handling

A line of the form `NAME:` or `NAME —` followed by speech is a **dialogue cue**.

- The cue is a **strong character signal** (weight 3 against 1 for prose
  capitalisation).
- **The cue label is stripped from `narration`.** This is not cosmetic: there is
  one narrator voice (FR-14), so an unstripped cue is read aloud as *"Ada colon,
  where is the boat"*.
- The speech itself **stays in the narration**, unchanged. v1 does not rewrite
  the author's words — no `"Ada says, ..."` is inserted.
- Consecutive lines under one cue are joined into a single narration sentence
  run.
- A parenthetical on its own line (`(beat)`, `(quietly)`) is removed from
  narration and collected into `action`.

**v1 limitation, stated plainly:** dialogue is *narrated*, not performed. One
voice reads everything. Per-character voices are not Phase 1.

## 5. Character and location handling

**Characters.** Two signals, summed:

| Signal | Weight |
|---|---|
| dialogue cue (`ADA:`) | 3 |
| capitalised word **not** at the start of a sentence | 1 |

Then: a stop-word list removes non-names (articles, pronouns, weekdays, months,
screenplay keywords), names are canonicalised and deduplicated, and the cast is
**capped at 6** by score. The cap is a cost decision, not a parsing one — every
character is three reference images the owner reviews and pays for (FR-5).

`SceneDraft::characterNames` is the structurer's attribution and is
**authoritative**: it is persisted to a `character_scene` pivot and read back by
`PlanShotsJob`. It is not re-derived from the narration text downstream, because
stripping dialogue cues (§4) removes the very name that a text search would have
matched.

**Locations.** v1 has no location entity — the PRD does not define one. Each
scene carries a `setting` string, taken from an explicit slug line when the
writer supplied one, otherwise inferred from keywords, otherwise `Scene N`.

## 6. Validation, failure and ambiguous input

### Rejected — `InvalidScriptException`

| Input | Why |
|---|---|
| empty or whitespace only | there is nothing to structure; better a clear error than a project containing "Untitled scene" |
| no letters at all (`"!!! 123 ???"`) | same |
| longer than `studio.limits.max_script_characters` | the limit is named in the message |

The exception is non-retryable by nature: the same input fails identically.
`StructureScriptJob::failed()` already returns the project to `DRAFT` so the
owner can correct the script and retry.

### Accepted, with a warning

| Input | Behaviour |
|---|---|
| no detectable characters | `characters: []`, every scene `characterNames: []`. **Valid, not an error** — a narration-only explainer legitimately has no cast, and G2 character consistency simply does not apply. A warning says so. |
| more scenes than the cap | remainder merged into the final scene (§3.5), warning records how many |
| one unbroken block | one scene, or several if it exceeds the word ceiling |
| ambiguous capitalisation (place names read as characters) | capped and stop-worded; the owner deletes what is wrong (FR-4). A warning appears when the cast hits the cap, since that is when false positives are most likely. |

## 7. Known v1 limitations

Stated so nobody discovers them by surprise:

- **English keyword lists.** Mood and setting inference, and the stop-word list,
  are English. A French script parses — it splits into scenes and finds dialogue
  cues correctly — but its mood will usually be `neutral` and its setting
  usually `Scene N`. Both are owner-editable fields, so the failure is cosmetic
  rather than structural. The narration itself is untouched, so a French project
  still narrates correctly (`FalSpeechSynthesizer` picks the French endpoint).
- **No scene-level semantic understanding.** The structurer does not know that
  two paragraphs describe the same moment, or that a flashback is not a new
  location.
- **`action` is extracted, not authored.** It collects parentheticals and slug
  line remainders; it does not invent stage direction.
- **Dialogue is narrated, not performed** (§4).
Warnings **are** surfaced: `StructureScriptJob` stores them on
`projects.structurer_warnings` and the project page renders them above the
capability warnings, because editing the scene list or cast is the earliest and
cheapest correction available (FR-3, FR-4). They are replaced on every re-parse
rather than accumulated, since they describe one parse — a project fixed by
editing its script stops showing the warning that prompted the edit. They are
also logged, for whoever reads logs rather than the UI.

Every one of these is a reason to add an LLM driver later. None of them is a
reason to add one now: the owner reviews and edits the breakdown before a single
paid render (FR-3), which is exactly the checkpoint that makes a deterministic
first pass sufficient.
