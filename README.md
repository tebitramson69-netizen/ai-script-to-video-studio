# AI Script-to-Video Studio

Turns a script into a narrated, multi-scene video with consistent characters.

Built from `AI_Script_to_Video_Studio_PRD_v1.0_FINAL.pdf`. This is **Phase 1** —
script → scene breakdown → per-scene clip → one narration track → one music
track → stitched `.mp4`.

The product is an **orchestration layer, not a video model**. It does not
generate video itself: it turns a script into a structured plan, calls external
services for each media type, keeps characters visually consistent, and
assembles the pieces into one exported file.

---

## Status: what actually works today

The entire pipeline runs end to end **right now**, offline, at zero cost, using
local `fake` drivers that emit real PNG/MP4/WAV files. `php artisan test`
produces a genuine playable `.mp4` on every run.

That is deliberate. PRD **A2** (payment access from Cameroon) is a hard
prerequisite that is not yet solved, and **A1** (funded provider keys) depends on
it. Building the orchestration against interfaces first means the day those are
solved, going live is a `.env` change plus one adapter class per provider — not
a rewrite.

| PRD requirement | State |
|---|---|
| FR-1 – FR-3 script → editable scene list | Done |
| FR-4 – FR-6 character detection, candidates, canonical lock | Done |
| FR-8 – FR-11 shot prompts, per-shot render, regenerate, budget cap | Done |
| FR-12 – FR-15 narration, music, native-audio mute, regenerate | Done |
| FR-16 – FR-18 narration-as-master-clock timing, splitting, trim/hold | Done |
| FR-19 – FR-21 stitch, mix with ducking, export `.mp4` | Done |
| FR-22 persistence and resume | Done |
| NFR-1 – NFR-6 async, resilience, idempotency, cost, logging, portability | Done |
| NFR-7 retention / purge intermediates | Done |
| FR-7 multi-angle reference sheets | Phase 2 |
| FR-13 per-scene SFX | Phase 2 (interface exists, not wired to the timeline) |
| Lip-sync, multilingual (EN/FR/Pidgin), captions | Phase 3 |
| Real provider adapters (fal.ai, ElevenLabs, …) | **Not built** — blocked on A1/A2 |

---

## Requirements

- PHP 8.3+ — and `composer.json` pins `config.platform.php` to 8.3.0, so the
  lockfile stays installable on 8.3 even when generated on a newer PHP. Remove
  that pin only if you decide to drop 8.3 support.
- Composer
- MySQL 8 (or SQLite for local work)
- **FFmpeg and FFprobe on PATH** — assembly will not work without them

## Setup

```bash
composer install
cp .env.example .env
php artisan key:generate

# SQLite (fastest way to try it)
touch database/database.sqlite
php artisan migrate

php artisan studio:create-owner        # there is no public sign-up (NG2)
```

Two processes, because all generation is queued (NFR-1):

```bash
php artisan serve
php artisan queue:work --tries=4       # in a second terminal
```

Open `http://127.0.0.1:8000`, sign in, paste a script.

### Running on XAMPP / MySQL

```dotenv
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=studio
DB_USERNAME=root
DB_PASSWORD=

FFMPEG_BINARY=C:\ffmpeg\bin\ffmpeg.exe
FFPROBE_BINARY=C:\ffmpeg\bin\ffprobe.exe
```

Point Apache's DocumentRoot at `public/`, never at the project root.

**XAMPP is fine for building; it is not enough for real runs.** PRD A4: queued
generation needs an always-on worker process, which Apache does not give you. On
a VPS, run `queue:work` under Supervisor or systemd.

## Tests

```bash
php artisan test
```

112 tests. The end-to-end test renders real media through FFmpeg and takes about
35 seconds; it skips itself if FFmpeg is missing.

---

## Architecture

### The pipeline is a state machine (PRD §8)

```
DRAFT → SCRIPTING → SCRIPT_READY → CHARACTERS_READY → SCENES_READY
      → SHOTS_READY → VOICE_READY → EXPORT_READY
```

`App\Services\Pipeline\ProjectStateMachine` is the **only** thing that writes
`projects.status`, and it owns the invalidation rule:

- editing the scene list → back to `SCRIPT_READY`, all shots marked stale
- re-locking a character → only *that character's* shots go stale, back to `SCENES_READY`
- regenerating one shot → invalidates assembly only
- stale assets are **marked, not deleted**; export is blocked while any shot is stale

Invalidation is easy to get right in one class and impossible to get right spread
across seven controllers.

### Everything external is behind an interface (NFR-6)

```
app/Contracts/          ScriptStructurer, ImageGenerator, VideoGenerator,
                        SpeechSynthesizer, MusicGenerator, SoundEffectGenerator
app/Integrations/Fake/  working local implementations of all six
config/studio.php       which driver satisfies which interface
```

Swapping Kling → Veo, or fal.ai → Replicate, is a config line plus an adapter
class. No pipeline code changes. See `docs/PROVIDERS.md`.

### Timing: narration is the master clock (FR-16 – FR-18)

`App\Services\Timing\ShotPlanner` takes the model's supported clip lengths *as
data*, so the same rules hold for every provider:

1. estimate narration duration (~150 wpm + punctuation pauses)
2. round **up** to the nearest clip length the model will actually render — never
   down, which would cut narration off mid-sentence
3. if a scene's narration exceeds the longest clip, split it on sentence
   boundaries into shots sharing the same cast and setting
4. at assembly, trim a clip that outruns its narration, or hold its last frame if
   real TTS ran longer than the estimate

Narration is synthesised **per shot**, then concatenated into the single
project-level track. That is what makes per-scene timing measurable while still
producing the one continuous voiceover Phase 1 calls for.

### Cost control (FR-11, NFR-4)

Prices come from the drivers, not a table — so a model swap re-prices the
estimate automatically. `CostEstimator::assertWithinBudget()` runs *before* any
job is queued; the cap is hard, and `AssetRecorder` makes it impossible to store
an asset without also writing its usage record.

### Provider adapters

Six focused capability interfaces (`app/Contracts/`), each bound independently
in `config/studio.php` — so video can run on one provider while TTS runs on
another. Shared provider plumbing lives in one client per provider rather than
being repeated per capability.

Model limits and prices live in the `video_models` registry and nowhere else
(`ModelCapabilities`). Price is a function of **resolution and whether native
audio is generated**, because on Veo 3.1 turning audio off halves the rate — and
every narrated project turns it off (FR-14). The adapter forwards that as a
provider parameter rather than generating audio and stripping it afterwards.

Paid calls go through `provider_requests`, whose `fingerprint` column carries a
UNIQUE index. Idempotency is enforced by the database refusing a duplicate
insert, not by a lookup that two workers can both pass.

Long-running generation is **submitted, not awaited**. A provider that queues
implements `QueueableVideoGenerator`; `RenderShotJob` then hands the work over
and releases the worker, and `ReconcileProviderRequestsJob` collects finished
results on a schedule. The request id lives in the ledger, so a worker restart
between submission and collection loses nothing — which matters because the
provider carries on generating, and charging, either way.

Polling is the primary completion path rather than a fallback. Webhooks get
lost, arrive out of order, and mutate billing state; they stay disabled until
their signature scheme is verified against a live account.

Set `STUDIO_VIDEO_DRIVER=fake-queue` to run that whole lifecycle locally, with
`STUDIO_FAKE_QUEUE_POLLS` controlling how long the fake provider makes you wait.

See `docs/IMPLEMENTATION-PLAN.md` for the sequence and the open questions.

### Retention (NFR-7)

Video is measured in gigabytes, so a project's storage is visible on its page and
reclaimable:

```bash
php artisan studio:purge-intermediates --dry-run      # report only
php artisan studio:purge-intermediates                # every exported project
php artisan studio:purge-intermediates 3 --days=30    # one project, aged
```

Only intermediate shot clips are deleted. The exported `.mp4` and every locked
character reference are kept. Purged shots move to a visible `purged` state and
block a re-export until re-rendered — deleting the clips but still reporting the
shots as "rendered" would produce an export that fails deep inside FFmpeg instead
of a clear message up front.

Deleting an asset never erases spend: `usage_records.asset_id` is
`nullOnDelete`, so cost history outlives the files.

### Security (§14)

- API keys are server-side env vars; the browser never holds a credential
- generated media sits on the **private** disk and is served only through
  `AssetController` behind auth and the project policy
- every state-changing route is POST/PATCH/DELETE inside the CSRF-protected `web`
  group — nothing that spends money is reachable by a GET
- Eloquent everywhere (no raw SQL), Blade `{{ }}` everywhere (no `{!! !!}`)
- login is rate-limited; no public registration
- the script is treated as **data**, never as instructions, when building prompts
- FFmpeg is invoked with argument arrays, never a shell string

---

## Known gaps

Stated plainly rather than left for you to discover:

1. **The fal video adapter is written but unproven.** `STUDIO_VIDEO_DRIVER=fal`
   binds it, and it is covered by 36 tests against faked HTTP — but no call has
   ever left this codebase, because the build environment's egress policy blocks
   fal.ai. What is owner-verified (Kling renders 5 or 10 seconds, has no audio
   toggle, is text-to-video only) is enforced in the payload builder. What is
   not (fal's response field names, the request URL shape) lives in
   `FalResponseMapper` alone, is pinned by `FalResponseMapperTest`, and is
   written to tolerate being wrong — it finds the output by structure as well as
   by name. Run `studio:capture-fal-shapes` against a funded account to replace
   the assumption with a fixture.
2. **Narration has a real adapter; music and SFX do not.** `FalSpeechSynthesizer`
   renders narration on any fal-hosted TTS model, defaulting to Kokoro —
   see `docs/PROVIDER-RESEARCH.md` §10 for why fal rather than ElevenLabs
   directly, and why the cheaper model is the default. Like the video adapter
   it is tested only against faked HTTP. Music and SFX are still fakes.
3. **Music and SFX have no real adapter**, and the script structurer is still a
   heuristic rather than an LLM. Both are `docs/PROVIDERS.md` shape-only.
4. **Character consistency (G2/FR-6) is reachable but unproven.** The
   `kling-2-5-turbo-pro-i2v` entry wires the image-to-video path end to end.
   Its endpoint, duration ladder and $0.07/s rate are corroborated by
   third-party sources (`docs/PROVIDER-RESEARCH.md` §9) but none has been read
   off the page by the account owner, so all of it is Tier B. fal.ai is
   unreachable from this codebase — every request is `EGRESS_BLOCKED` — so
   Tier A needs someone with an account.
5. **Per-scene SFX (FR-13, Phase 2)** has an interface and a fake driver, but is
   not placed on the assembly timeline.
6. **No polling/websockets.** Queued stages update on page refresh. This starts
   to matter once real renders take minutes rather than seconds.
7. **The `"Ai"` Laravel project (PRD D1/A5) was never inspected** — it was not
   reachable from the environment this was built in. Nothing here assumes the
   shape of its `usage_records` table; this project defines its own. If you do
   want to merge the two, that reconciliation is still open.

## Open PRD decisions

| | Decision | Resolved as |
|---|---|---|
| D1 | Relationship to `"Ai"` | Standalone project — `"Ai"` was not accessible to verify |
| D2 | Stack | **Laravel** (queues, migrations, policies, CSRF fit the requirements) |
| D3 | Language scope | **English-only v1**; `projects.language` exists for Phase 3 |
| D4 | Aggregator | **Recommended: fal.ai** — one billing relationship covers video, image, TTS and music, and its per-second output pricing matches our cost interfaces. Replicate is the fallback. Evidence and confidence levels in `docs/PROVIDER-RESEARCH.md`. |
| D5 | v1 line | **Phase 1 narrated video, no on-screen lip-sync** |
