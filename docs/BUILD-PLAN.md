# Build plan

Maps the PRD's milestones (§17) onto what exists, and what to do next in order.

---

## Where the milestones stand

| | PRD deliverable | State |
|---|---|---|
| **M0** | Decisions D1–D5; payment/billing (A2); repo + queue + aggregator key | **Partial.** D1–D5 resolved (README). Repo and queue done. **A2 unsolved — no key wired.** |
| **M1** | Script → structured, editable scene list | **Done** |
| **M2** | Character extraction + canonical reference lock | **Done** |
| **M3** | Single shot → single clip via aggregator | **Done against the fake driver.** Real aggregator blocked on M0. |
| **M4** | Narration (TTS) + one music track | **Done** (fake driver) |
| **M5** | FFmpeg assembly → first exported `.mp4` | **Done** — genuinely produces a playable file |
| **M6** | Review loop + budget cap + cost display | **Done** |
| **M7+** | Phase 2 consistency/control → Phase 3 dialogue/multilingual | Not started |

The architecture for M1–M6 is complete and tested. What is missing is not
structure — it is provider access.

---

## The critical path is not code

PRD **A2** names this itself: *"This must be solved before M0. This is a hard
prerequisite, not a detail."*

> **Payment access from Cameroon.** The AI services are USD-billed, generally
> require an internationally-accepted card, involve forex, and can be
> geo-restricted.

Nothing about the pipeline changes this, and no amount of further building moves
it forward. Until it is resolved the studio runs on fake drivers, which is
genuinely useful — you can build, demo and test the whole flow — but it produces
placeholder media, not real video.

**Do this first, before writing another line of code:**

1. Establish a payment method that a USD-billed AI API will accept.
2. Fund **one** aggregator account (fal.ai or Replicate). One billing
   relationship, many models — that is the single most important architectural
   choice for a solo build (PRD §10).
3. Verify a **$1 test spend** actually clears end to end before building against
   the API.

Only once (3) succeeds does the next section become worth starting.

---

## Next steps, in order

### Step 1 — Real video adapter (unblocks everything)
Implement `VideoGenerator` against the funded aggregator. Follow
`docs/PROVIDERS.md`. Get `supportedClipLengths()` right — the timing engine
depends on it being truthful.

*Done when:* one shot renders through the real provider, the clip plays, and the
recorded `cost_usd` matches the provider dashboard.

### Step 2 — Real TTS adapter
Implement `SpeechSynthesizer` (ElevenLabs or equivalent). Return the **measured**
duration, not the requested one — it is the master clock.

*Done when:* an exported video has real narration and the runtime still matches
the narration-driven timeline.

### Step 3 — Real image and music adapters
Same pattern. Character reference quality is what P2 consistency work builds on,
so it is worth spending a little time on the reference prompt here.

### Step 4 — First real end-to-end video, costed
Run one 60-second folk tale on a **$10 cap**. Compare actual spend against the
PRD's $7.10 clean-run estimate (§13). Correct the price constants in
`config/studio.php` from the real invoice.

*This is the real Phase 1 completion.* Everything before it is rehearsal.

### Step 5 — ~~NFR-7 retention~~ (done)
`studio:purge-intermediates` plus a storage panel on each project page. Worth
re-checking once real clips exist: the numbers here are small because fake clips
are small, and a single real 8-second 1080p clip is orders of magnitude larger.
Consider running the command on a schedule with `--days=30`.

### Step 6 — Progress visibility
Queued stages currently update on refresh. Once real renders take minutes rather
than seconds, add polling on the project page.

### Step 7 — Phase 2 (PRD §7)
Multi-angle character reference sheets (FR-7), per-shot prompt editing and
re-ordering, per-scene SFX on the timeline, per-video model selection.

### Step 8 — Phase 3
Lip-sync, multilingual narration (EN / FR / Pidgin), caption burn-in.
`projects.language` already exists for this.

---

## Deployment, when you get there

XAMPP is fine for building. It is not enough to run this (PRD A4): queued
generation needs an always-on worker.

- a small VPS with PHP 8.2+, MySQL, FFmpeg
- `queue:work` under Supervisor or systemd, **not** in a browser tab
- Apache/nginx DocumentRoot at `public/`
- `APP_DEBUG=false`, HTTPS, `.env` unreadable from the web
- disk headroom: video is measured in GB, not MB

---

## One open item from the PRD

**D1 / A5 — the relationship to the existing `"Ai"` Laravel project.**

The PRD recommends extending `"Ai"` since the engine is ~80% the same, and A5
warns to confirm the shape of its `usage_records` table before binding cost logic
to it.

That repository was not reachable from the environment this was built in, so
nothing here assumes anything about it — this project defines its own schema. If
merging the two is still the goal, that reconciliation is genuinely open work:
compare the two `usage_records` shapes first, since that is where the PRD
flagged the risk.
