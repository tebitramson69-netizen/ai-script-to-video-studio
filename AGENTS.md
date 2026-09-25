# AI Script-to-Video Studio — working notes for agents

Implements `AI_Script_to_Video_Studio_PRD_v1.0_FINAL`: a script goes in, a
narrated multi-scene `.mp4` comes out, with human review checkpoints between
stages.

**This is an orchestration layer, not a video model.** It calls external AI
services for each media type and stitches the results with FFmpeg. The hard
parts are timing, cost control and not paying twice for the same work — not
generation.

Read `README.md` for the feature map, `docs/BUILD-PLAN.md` for sequencing, and
`docs/PROVIDER-RESEARCH.md` before touching anything that costs money.

---

## Orientation

The Laravel app **is** the repository — it lives at the root. (It previously sat
in a `studio/` subfolder of an unrelated repository, because the session that
built it could not create a new repo; history was extracted with
`git subtree split`, so the commits are the same ones.)

```sh
composer install
cp .env.example .env && php artisan key:generate   # tests fail without APP_KEY
php artisan migrate
php artisan test
vendor/bin/pint --dirty
```

Tests use SQLite in memory (`phpunit.xml`). Several skip themselves when
`ffmpeg` is absent, including the end-to-end test that asserts the export is a
playable h264+aac file — so a green run on a machine without FFmpeg is weaker
than it looks. Install it before trusting a pass.

**PHP 8.3+.** `composer.json` pins `config.platform.php` to `8.3.0`. Do not
remove that pin: the lock was once generated on 8.4 and pulled Symfony
components requiring 8.4.1, which broke CI on 8.3 — and XAMPP, the target local
environment, commonly ships 8.3. Run `composer update` with the pin in place.

---

## Rules that exist because breaking them costs real money

These are not style preferences. Each one is load-bearing and has a test.

1. **`ProjectStateMachine` is the only writer of `projects.status`.** It owns
   the PRD §8 downstream-invalidation rules: editing scenes stales all shots,
   re-locking a character stales only that character's shots, a single shot
   regeneration invalidates assembly alone. Stale assets are marked, never
   deleted, and export stays blocked while any shot is stale.

2. **Idempotency is a UNIQUE index, not a lookup.** `GenerationLedger::claim()`
   inserts and catches the constraint violation. Never replace it with
   "check whether a row exists, then insert" — two workers pass that check
   simultaneously and the provider bills twice.

3. **Claim before you submit.** `ShotSubmitter` takes the claim first. A
   submission that succeeds without a claim is work nobody can find again, and
   fal is already charging for it.

4. **`AssetRecorder` is the only way to store an asset.** It makes it
   impossible to save generated media without a matching usage record (FR-11).

5. **An unreported provider cost is `null`, never `0.0`.** "Free" and "not
   reported" bill very differently; coercing the second into the first records
   a spend of nothing and the budget cap stops protecting anything.

6. **Prices come from the model registry, never a literal.** `ModelCapabilities`
   throws on an unpriced resolution rather than returning `0.0`, because a
   silent zero lets an unbounded run past the cap.

7. **The budget cap is enforced before anything is queued**, not after.

---

## `config/studio.php` is the single source of truth

Every model fact — endpoints, clip lengths, resolutions, aspect ratios,
prices, which optional parameters the model accepts — lives there and nowhere
else. The timing engine, the cost estimator and the adapters all read it. That
is PRD NFR-6 stated as code: swapping models is a config edit.

**Model keys must not contain dots.** `config()` resolves dot notation as a
nested path, so `veo-3.1` silently resolves to `null` and every price and limit
for it disappears without an error. `ModelCapabilitiesTest` guards this with
`test_no_model_key_contains_a_dot`.

**Evidence tiers, and the over-estimate rule.** Comments in that file mark
what is owner-verified against a live account versus `VERIFY_IN_DASHBOARD`.
Where a price is unverified it is deliberately set to the **higher** known
rate: over-estimating makes the cap refuse a run, under-estimating lets it
overspend. Err toward refusing. Never replace a `VERIFY_IN_DASHBOARD` marker
with a number you found in a blog post — `docs/PROVIDER-RESEARCH.md` explains
the three evidence tiers and why third-party pricing figures for these models
span a 7x range.

---

## Providers

Six focused interfaces in `app/Contracts/`, one per capability, rather than one
fat `MediaProvider`. A provider that only does speech implements only
`SpeechSynthesizer`; capabilities can be mixed across vendors.

`fake` drivers render real, playable files locally at zero cost, so the whole
pipeline runs end to end offline. They are the default everywhere.

**`app/Integrations/Fal/` is the one real adapter.** Its structure is the
pattern to copy, and the reason for that structure matters:

- `FalPayloadBuilder` — the only place a fal request is shaped. Optional
  parameters are opt-in per model via `payload_parameters`, because sending
  one the model does not accept returns a 4xx that reads like a wrong URL.
- `FalResponseMapper` — **the only place that knows fal's field names.** None
  of them has been observed: this codebase cannot reach fal.ai. Keeping them
  in one class means correcting them is one file and one test file. The mapper
  is written to tolerate being wrong (it finds the output by structure as well
  as by name); preserve that property.
- `FalClient` — transport, retries and HTTP-status-to-taxonomy classification.
  Knows nothing about video. It takes its credential as a plain `string`, so the
  container **cannot** autowire it — `StudioServiceProvider` binds it explicitly.
  Do not remove that line: without it every fal driver throws an Unresolvable
  dependency error before a single request is made, and no unit test catches it
  because they all construct the adapters by hand.
- `FalVideoGenerator`, `FalSpeechSynthesizer`, `FalImageGenerator`,
  `FalMusicGenerator`, `FalSoundEffectGenerator` — the pipeline-facing adapters,
  one per capability, each reading its own registry in `config/studio.php`.

`ProviderFailureReason` ties retryability to the reason so the two cannot
disagree. Retrying a 402 waits for money that will not appear; not retrying a
429 throws away work that would have succeeded a second later.

**Never invent a provider fact.** If a value is unknown, mark it
`VERIFY_IN_DASHBOARD` and make the code refuse rather than guess.

`php artisan studio:capture-fal-shapes --dry-run` prints the exact request and
costs nothing. Without `--dry-run` it makes one real generation. Always dry-run
first, and never run the spending version without asking.

---

## A project renders on one or two models

`projects.video_model` is the primary; `projects.video_model_i2v` is an
optional companion for shots that have a locked character reference. Null
companion means one model renders everything, which is the original behaviour.

The rule that keeps this coherent: **a shot renders on the model it was
planned on.** `PlanShotsJob` resolves the model per scene — before durations
are computed, because clip-length ladders are per model — and stamps
`shots.model`. `RenderShotJob` and `CostEstimator` both read that stamp back
through `ModelRegistry::forShot()`. Re-resolving at render time would let a
shot planned at 8 seconds against Veo be handed to Kling, whose ladder is 5
or 10.

`forShot()` honours `shots.model` **only when it is one of the models the
project chose.** Anything else is a stale row, not a third model — honouring
it would let a shot carrying `fake` be costed at zero on a project pinned to
something expensive, which is the exact hole the budget cap exists to close.
`CostEstimator::capabilitiesFor()` applies the same rule; keep them in step.

## Scene cast attribution is data, not a text search

`ScriptStructurer` decides which characters belong to which scene,
`StructureScriptJob` persists that to the `character_scene` pivot, and
`PlanShotsJob` reads it back. Do not reintroduce a narration text search as the
primary path: dialogue cues are stripped from narration so the single narrator
voice does not read out "Ada colon" (FR-14), which removes the very name such a
search matched. The regex in `charactersInScene()` is a legacy fallback for
scenes created before the pivot existed, and nothing new should rely on it.

The structurer is deterministic and local by design — no LLM, no key, no cost.
Its contract is `docs/SCRIPT-STRUCTURER.md`; read it before changing any
heuristic, because it states what the schema guarantees (enforced by
`ScriptBreakdownValidator`, which runs on the structurer's own output) versus
what is merely best-effort.

## A character reference's shape is the video's shape

`image_models.*.image_sizes` maps an aspect ratio onto the provider's size
preset (FLUX takes `landscape_16_9`, not width and height). Treat it as
load-bearing, not cosmetic: Kling's image-to-video endpoint takes no
`aspect_ratio` and inherits the framing of its starting frame, and the locked
character reference *is* that frame. A reference generated in the wrong shape
mis-frames every character shot in the finished video, at full price, with
nothing saying so. A ratio a model does not declare is refused, never
substituted.

## Speech models are chosen by language

`studio.speech_models` is the sibling of `video_models`, and one entry may
carry an endpoint *map* rather than a single endpoint: Kokoro ships a separate
model id per language, so choosing French chooses a different URL. A language
the configured model cannot speak is refused before any request is sent —
narration in the wrong language is a wrong result, not a degraded one, and a
silent fallback to English would be paid for before anyone noticed.

`FalSpeechSynthesizer` **measures** the returned audio with ffprobe and ignores
any duration the provider reports. See the next section for why.

## Music is a bed, and it must not sing

`studio.music_models` is the fourth registry. Two rules, and the first is not
about money.

**A vocal line is a ruined video, not a degraded one.** Music is mixed under the
narration and ducked against it (FR-13, FR-20), so a sung lyric competes with the
one voice the video is built around (FR-14) — and ducking makes it *quieter*, not
less distracting. `FalMusicGenerator` refuses to call a model whose entry sets
`generates_vocals` unless `payload_defaults` carries `instrumental` or `lyrics`,
before spending anything. A prompt asking for "no vocals" does not count: that is
a hope, and this needs to be a guarantee. The default model cannot sing at all.

**This is the one registry where price moves the budget.** The spread is ~125×
for the same job — a 64-second bed is $0.013 on ACE-Step and $1.60 on MiniMax
Music, which bills per output minute *rounded up*. Speech is under 2% of a run
and images are cents, so both were chosen purely on quality. Do not treat music
the same way. `MusicGenerator::costForSeconds()` takes a duration rather than
returning a rate, because some models bill flat per request and a per-minute rate
cannot express that.

**Clamping here, refusing everywhere else.** The assembler loops the music input
(`-stream_loop -1`), so a bed shorter than the timeline repeats rather than
leaving silence — a quality compromise, not a wrong result. A timeline past the
model's ceiling is therefore clamped and flagged on the asset
(`looped_to_cover_timeline`), not refused. This is the only place in the codebase
where an unsupported capability is quietly accommodated, and that is deliberate.

Field *names* are config, not constants (`prompt_parameter`,
`duration_parameter`): audio models disagree about them, and
`duration_parameter: null` means the model takes no length and none is invented.

## Sound effects: the cue is the expensive part

`studio.sfx_models` is the fifth registry, and SFX inverts the cost reasoning used
everywhere else in this codebase. Effects are billed **per effect** ($0.0194 on the
default), so the bill is *how many scenes carry a cue* — not how long anything is.
The expensive failure is therefore not an expensive model, it is a **false
positive**: a cue in a scene that names no sound buys something that does not
belong in the finished video, and that is worse than silence because it sounds
deliberate and someone has to ask for the render again.

So the cue never falls back. `HeuristicScriptStructurer::SFX_CUES` is a closed,
whole-word-matched map and a scene with no match gets `null` — which is the common
case. `ScriptBreakdownValidator` rejects a *blank* cue for the same reason: `''`
would reach the provider as a prompt. Do not add a default, and do not add a
one-shot (a gunshot, a slammed door) to that map — every entry must be **sustained
ambience**, because the mix loops an effect across its whole scene and a looped
one-shot is a woodpecker.

The other constraint is length: the default model caps at 22 seconds against scenes
that run longer, so `loop: true` is sent **only when the effect will actually
repeat**. An effect not generated to loop seams every time it wraps, right under
the narration.

An effect is also the first asset here whose place on the timeline is not "the
whole video". `VideoAssembler::positionedEffects()` accumulates offsets from the
**rendered shots**, never the scene list — one unrendered shot would otherwise
drift every effect after it into the wrong scene.

`buildAudioBed()` is compositional on purpose: one filter chain and one label per
source, mixed, then ducked if there is narration to duck against. It used to be a
branch per combination, and effects would have made that eight branches. Two
ffmpeg details to preserve: an `asplit` whose second output goes nowhere fails the
whole graph (so narration is only split when there is a bed), and the beds mix uses
`duration=longest` because an effect in the last scene starts late.

## Bed levels are RELATIVE to narration, and that has to be enforced

`music_bed_db` (-6) and `sfx_bed_db` (-12) are levels **under the narration**,
not attenuations of whatever a provider returned. Those are the same thing only
when every stem arrives at the same loudness, and providers do not agree: the
local drivers alone differ by 15 dB, and a TTS service returning -16 dBFS beside
a music model returning -39 makes "6 dB under the voice" meaningless.

So `VideoAssembler` measures each stem with `FfmpegRunner::meanVolumeDb()` and
normalises it to `narration_target_db` plus its offset. An unmeasurable stem is
mixed at its own level rather than guessed at, a stem below the silence floor is
never boosted (that amplifies an encoder's noise floor, not a signal), and gains
are clamped both ways.

This shipped broken: the mix measured **-37 dB mean** — every stem present,
correctly timed, and inaudible on a laptop speaker. The tests did not catch it
because they asserted the export *contains an audio stream* and *is the right
length*, both of which were true. `EndToEndPipelineTest` now asserts the mix is
above -30 dB. **An audio assertion that does not measure loudness does not test
audio.**

## Timing: narration is the master clock

FR-16/17/18. `ShotPlanner` reads the model's supported clip lengths as data,
rounds each scene **up** — never down — to a renderable length, and splits a
scene exceeding the longest clip on sentence boundaries. The assembler trims or
holds each clip to its *measured* narration, not its requested duration.

A `SpeechSynthesizer` must return the MEASURED duration — from ffprobe on the
downloaded file, never from what the provider says. A reported figure a tenth
of a second out desynchronises every shot after it, and the error accumulates
down the timeline rather than staying local.

---

## Live progress is polled, and that was a decision

`GET /projects/{project}/status` returns a `ProgressSnapshot` as JSON; the page
renders the same snapshot server-side and `public/js/studio-progress.js` keeps it
current. **Not websockets and not SSE**, for a reason worth not re-litigating:
this deploys on XAMPP and Apache, every open SSE stream holds one Apache worker
and one PHP session file lock for its whole life, and — decisively — the stages
here take *minutes*, so sub-second delivery buys a human watching them nothing.
See `docs/PROVIDER-RESEARCH.md` §14.

Four invariants hold this together. Breaking any of them is a regression that
tests will catch, but it is cheaper to know why they exist:

- **`fingerprint` must never contain a clock.** Structural change triggers a page
  reload, so a snapshot that changed every second would refresh the page every
  three seconds, forever.
- **The endpoint must stay read-only.** It is a plain GET with no CSRF token, and
  that exemption is only safe while it mutates nothing.
- **The poller must not re-render the page.** It updates a strip in place and
  reloads on structural change. Rebuilding shot cards or cost tables in
  JavaScript would create a second rendering path that drifts from Blade's.
- **`busy` is the on/off switch**, and `Stale` is deliberately not busy — stale
  means "waiting for a decision", not "running".

The endpoint is polled, so it is also the one place where PHP's session file lock
matters: every poll holds it for the request's duration, and a form submission in
another tab waits behind it. Keep it to grouped counts and a hash.

## Security baseline (PRD §14) — preserve in every change

- Provider keys are server-side only, read from `.env`, never on a command line
  (shell history) and never in a captured file (the capture command redacts).
- Generated media lives on the private disk and is served through an authorised
  controller — never a public URL.
- Every money-spending route is POST/PATCH/DELETE inside the CSRF-protected
  `web` group. A GET that spends money is a bug.
- Eloquent throughout, escaped Blade throughout, rate-limited login, no public
  registration.
- Scripts are **data, never instructions**. Pass user script text to an LLM in a
  delimited block; never concatenate it into a prompt.
- `ffmpeg` is invoked with argument arrays, never an interpolated shell string.

---

## Conventions

- Explain *why* in comments, not *what*. The codebase documents reasoning —
  especially where a decision protects against a cost or a race.
- Write the failing test first for anything involving money or state.
- Commit messages state the root cause before the fix.
- Run `vendor/bin/pint --dirty` before committing. Note that Pint rewrites
  fully-qualified names to imports, which has silently broken later scripted
  find-and-replace edits — verify such edits actually matched.

## Do not

- **Do not install `laravel/boost`.** This file replaced the stock bootstrap
  that asked for it. Adding a dev dependency is the user's call.
- Do not weaken a test to make a suite pass.
- Do not add a framework or dependency where the existing stack suffices.
- Do not assume the `"Ai"` Laravel project (PRD D1/A5) exists — it was never
  reachable, and nothing here may depend on its schema.
