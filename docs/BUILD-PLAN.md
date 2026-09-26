# Build plan

Maps the PRD's milestones (§17) onto what exists, and what to do next in order.

**Last verified against the code on 26 Sep 2026** — 368 tests, 1124 assertions,
CI green on PHP 8.3 and 8.4.

---

## Where the milestones stand

| | PRD deliverable | State |
|---|---|---|
| **M0** | Decisions D1–D5; payment/billing (A2); repo + queue + aggregator key | **Partial.** D1–D5 resolved. Repo, queue and all five adapters done. **A2 unsolved — no key funded, so no request has ever left.** |
| **M1** | Script → structured, editable scene list | **Done** |
| **M2** | Character extraction + canonical reference lock | **Done** |
| **M3** | Single shot → single clip via aggregator | **Done in code.** Proven against the fake driver; unproven against fal. |
| **M4** | Narration (TTS) + one music track | **Done in code**, plus per-scene sound effects, which the PRD deferred to Phase 2 |
| **M5** | FFmpeg assembly → first exported `.mp4` | **Done** — genuinely produces a playable file |
| **M6** | Review loop + budget cap + cost display | **Done** |
| **M7+** | Phase 2 consistency/control → Phase 3 dialogue/multilingual | **Partially started** — see below |

**The orchestration layer is complete.** Every FR-1 → FR-21 and NFR-1 → NFR-7 is
implemented and referenced in code. What is missing is not structure — it is
provider access.

### What exists that this plan used to list as "next"

All five capabilities have real fal adapters, sharing one client, one response
mapper and one payload builder:

| Capability | Adapter | Default model |
|---|---|---|
| Video | `FalVideoGenerator` | Kling 2.5 Turbo Pro (t2v + i2v), Veo 3.1 / 3.1 Fast registered |
| Images | `FalImageGenerator` | FLUX.1 [schnell] |
| Narration | `FalSpeechSynthesizer` | Kokoro (EN / FR), ElevenLabs v3 registered |
| Music | `FalMusicGenerator` | Stable Audio 3 Medium, ACE-Step registered |
| Sound effects | `FalSoundEffectGenerator` | ElevenLabs Sound Effects V2, Stable Audio 3 Small SFX registered |

Also done since this plan was written: the script structurer was promoted from a
stub to a specified, validated, deterministic v1 (`docs/SCRIPT-STRUCTURER.md`);
per-scene sound effects are generated, positioned and mixed; and queued stages
report live progress by polling rather than needing a refresh.

**Every adapter is Tier B.** They are written from documented API shapes and
tested only against simulated HTTP, because fal.ai is unreachable from the
environment they were built in. "Should work" is not "does work" — which is what
Step 2 below exists to settle.

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

> **Provider comparison: see `docs/PROVIDER-RESEARCH.md`** (15 Sep 2026). It
> evaluates fal.ai, Replicate, Google Vertex/Gemini and ElevenLabs against this
> system's actual requirements, and recommends **starting with fal.ai**. It also
> **Superseded in part on 22 Sep 2026.** The live fal account shows **$0.00 and
> zero requests**, so the third-party "free signup credit" finding below does not
> apply — payment is a real prerequisite again. Kling 2.5 Turbo Pro is verified
> at **$0.07/s** ($0.35 per 5s), and fal's minimum top-up is **unverified**: the
> ~$5 figure in this document is Replicate's.

### Resolving D4: pick the aggregator in two stages, not one

D4 was left open because the binding constraint is **billing, not model
catalogue**. An aggregator with the perfect model list is worthless if your card
is declined at its checkout. So decide it in that order.

**Stage A — which one takes your money.**

Prefer a provider with a **prepaid credit** model over one that bills in
arrears. Prepaid gives you a hard ceiling that no bug, runaway loop or
mis-estimated shot count can exceed — it complements the app's own budget cap
rather than duplicating it. Postpaid means a mistake becomes an invoice.

At the time of writing, from secondary sources only (both providers' own sites
were unreachable from the build environment — **verify these yourself before
relying on them**):

| | Billing model | Minimum | Notes |
|---|---|---|---|
| Replicate | Prepaid credit for new accounts | ~$5 to start, ~$15 auto-reload | No monthly fee, no minimum spend |
| fal.ai | Prepaid credits | not confirmed | Credits expire after ~365 days; charged only for successful outputs |

Try whichever is cheaper to *fail* at. A declined card costs nothing; the point
of Stage A is to find out fast.

**Stage B — which one has the models.**

Only once money has actually cleared. Confirm the aggregator hosts a video model
with the clip lengths and quality Phase 1 needs, then write its **real** clip
lengths into `config/studio.php`. Do not take this from any document, including
this one: model availability across aggregators changes monthly, which is the
entire reason the codebase is provider-agnostic.

### The payment checklist

1. **Get a payment method that works for USD online payments from Cameroon.**
   This is the actual blocker. Locally-issued cards are frequently declined for
   international charges because of FX controls and international spending
   limits set by the bank or central bank, not by the AI provider. The common
   workaround is a virtual USD card from a fintech — but most published guides
   on this are Nigeria-focused, so confirm what actually works for **Cameroon**
   and for **recurring USD API charges** specifically, not just one-off
   e-commerce.
2. **Fund the smallest top-up fal allows.** That figure is unverified — the ~$5
   in the table above is Replicate's, not fal's, so read it off fal's own
   billing page. Do not fund more until a charge has cleared.
3. **Make one real API call by hand** — curl, or the provider's playground —
   before writing a line of adapter code. You are testing the billing
   relationship, not the integration.
4. **Check the invoice.** Confirm the amount charged matches what you expected,
   and note the FX rate and any foreign-transaction fee your bank added. That fee
   is part of your true cost per video and belongs in the numbers in
   `config/studio.php`.

Only once (3) succeeds does the next section become worth starting.

---

> **`docs/IMPLEMENTATION-PLAN.md`** (21 Sep 2026) recorded the adapter strategy:
> fal.ai primary, Replicate the reversible fallback, architecture built before
> payment. **That plan is now fully executed** — all five adapters exist. It is
> kept for the reasoning; the steps below are what remains.

## Next steps, in order

Everything in this list is blocked on the payment checklist above, except where
marked.

### Step 1 — Fund the account
The smallest top-up fal allows. Read that figure off fal's own billing page; it
is still unverified here.

*Done when:* a charge has cleared and the dashboard shows credit.

### Step 2 — Capture the real payload shapes **before rendering anything**
```
php artisan studio:capture-fal-shapes --dry-run   # free, prints the exact request
php artisan studio:capture-fal-shapes             # one small real generation
```
The dry run costs nothing and shows byte-for-byte what a real render would send,
because the probe is built through the same `FalPayloadBuilder` the adapter uses.
The real run turns five assumed response shapes into fixtures.

*Done when:* the captured JSON is committed as fixtures and `FalResponseMapper`
is corrected against it. **This is the cheapest possible way to find out that a
field name is wrong** — the alternative is discovering it four clips into a paid
render.

The values most likely to be wrong, in order of what they cost you:

| Value | Why it matters |
|---|---|
| Stable Audio 3's duration field (`duration` vs `seconds_total`) | 422 mid-run |
| ACE-Step's real maximum length | 422 mid-run |
| Stable Audio 3 Small SFX's field names and price | 422 mid-run; price unpublished |
| Whether ElevenLabs' `loop` behaves as documented at 22s | audible seam |
| Kling i2v $0.07/s, Kokoro endpoint ids, FLUX per-image price | cost estimate drifts |

### Step 3 — One real clip
Flip `STUDIO_VIDEO_DRIVER=fal` only. Render a single 5-second shot.

*Done when:* the clip plays and the recorded `cost_usd` matches the fal
dashboard. If those disagree, fix `config/studio.php` before going further — the
budget cap is only as honest as its rates.

### Step 4 — First real end-to-end video, costed
Flip the remaining four drivers. Run one 60-second folk tale on a **$10 cap**.
Compare actual spend against the ~$4.40 estimate, and correct the price
constants from the real invoice.

*This is the real Phase 1 completion.* Everything before it is rehearsal.

### Step 5 — Re-check retention against real files
`studio:purge-intermediates` works, but the numbers it reports are small because
fake clips are small. One real 8-second 1080p clip is orders of magnitude larger.
Consider scheduling it with `--days=30`.

### Step 6 — Phase 2 remainder *(not blocked on payment)*
Per-shot prompt editing, scene re-ordering, per-project model selection and
per-scene SFX are **already done**. What is left of PRD §7:

- **FR-7 multi-angle character reference sheets.** `GenerationMode::ReferenceToVideo`
  and `ClipRequest::$referenceImagePaths` already exist for this; nothing
  generates the sheet.

### Step 7 — Phase 3 *(not started)*
Lip-sync, caption burn-in, and Pidgin narration. `projects.language` exists and
the speech registry already ships English and French, so multilingual is
partly done — Kokoro would need a Pidgin endpoint, or a different model.

---

## Known rough edges

Neither blocks anything; both are worth knowing.

- **The live progress strip does not cover the character-reference stage.**
  `ProgressSnapshot::busy` is computed from shots in flight and outstanding
  provider requests, and generating candidates is neither, so that one stage
  still needs a manual refresh.
- **XAMPP cannot run this in production.** Fine for building; see below.

---

## Deployment, when you get there

XAMPP is fine for building. It is not enough to run this (PRD A4): queued
generation needs an always-on worker.

- a small VPS with **PHP 8.3 or 8.4** (Laravel 13 dropped 8.2), MySQL, FFmpeg.
  Worth stating plainly because it catches people: XAMPP 8.2.x ships PHP 8.2 and
  **cannot run this app at all**, however it is invoked. Install PHP 8.3+
  alongside it for the CLI, or upgrade XAMPP.
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
