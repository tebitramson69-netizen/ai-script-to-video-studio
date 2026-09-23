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

The Laravel app is in **`studio/`**, not the repository root. Every `composer`,
`artisan`, `pint` and `test` command runs from there; CI sets
`working-directory: studio` for the same reason.

```sh
cd studio
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
  Knows nothing about video.
- `FalVideoGenerator` — the pipeline-facing adapter.

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

## Speech models are chosen by language

`studio.speech_models` is the sibling of `video_models`, and one entry may
carry an endpoint *map* rather than a single endpoint: Kokoro ships a separate
model id per language, so choosing French chooses a different URL. A language
the configured model cannot speak is refused before any request is sent —
narration in the wrong language is a wrong result, not a degraded one, and a
silent fallback to English would be paid for before anyone noticed.

`FalSpeechSynthesizer` **measures** the returned audio with ffprobe and ignores
any duration the provider reports. See the next section for why.

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
