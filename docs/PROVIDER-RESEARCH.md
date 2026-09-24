# Provider research — resolving PRD D4

Research date: **15 September 2026**. Decision owner: Tebit Ramson Titih.

## Read this first: three tiers of evidence

Claims in this document fall into three classes. Keeping them apart is the whole
point of the document — the worst failure mode here is a third-party blog price
hardening into a config constant nobody re-checks.

### Tier A — Read from a live fal.ai account by the project owner

The only primary-source evidence in this project. fal.ai is blocked by the
build environment's egress policy, so none of it is verified by this codebase —
but it outranks every third-party figure below, and it is what
`config/studio.php` is built from.

**Kling 2.5 Turbo Pro — read from the model page, 22 Sep 2026**

| Fact | Value |
|---|---|
| Endpoint | `fal-ai/kling-video/v2.5-turbo/pro/text-to-video` |
| Price | **$0.35 for 5 seconds**, then **$0.07 per additional second** — a flat $0.07/s |
| Durations | **5 or 10 seconds only** — not a range |
| Audio | **No `generate_audio` or equivalent in the schema.** Video-only. |
| Conditioning | **Text-to-video only.** This endpoint takes no starting image. |

**Account state, 22 Sep 2026**

| Fact | Value |
|---|---|
| Credit balance | **$0.00** |
| Requests made | 0 |
| Payment method | none added |
| Minimum top-up | **Not verified.** Public docs say no minimum spend, but paid Model API use requires prepaid credits. |

**Veo 3.1 — read from fal's model pages, 21 Sep 2026**

| Fact | Value |
|---|---|
| Endpoints | `fal-ai/veo3.1`, `fal-ai/veo3.1/fast` |
| Price, 720p/1080p | $0.20/s without audio, $0.40/s with |
| Price, 4K | $0.40/s without audio, $0.60/s with |
| Audio control | `generate_audio` parameter |
| Durations | 5–8 seconds |
| Aspect ratios | 16:9 and 9:16 — **not 1:1** |
| Image-to-video | accepts an image **URL** plus a motion prompt |
| Safety filtering | applies to **input images as well as output** |

### Three corrections this evidence forces

**1. There is no free signup credit.** Tier B reported that new fal accounts
receive free credits and that $20/$50 coupons circulate — enough, I argued, that
Phase 1 might be completable before any payment. The live account shows
**$0.00 and zero requests**. Whatever those articles described does not apply
here. Payment is a real prerequisite again, not something that might be dodged.

**2. "~$5 minimum" was never fal's number.** It is Replicate's prepaid minimum
and was always labelled so, but it sat close enough to the fal discussion to be
misread. **fal's minimum top-up is unverified.** Do not plan around $5.

**3. Kling's real price beats every third-party estimate of it.** Tier B put
Kling on fal anywhere between $0.029/s and $0.20/s — a 7× spread I refused to
pick from. The measured figure is **$0.07/s**, which happens to match the
corroborated Kling figure exactly, and is a third of Veo 3.1 Standard's $0.20/s
without audio.

### The consequence that matters most

The verified endpoint is **text-to-video only**, and Phase 1's character
consistency (PRD **G2**, FR-6) depends on feeding a locked reference image into
every shot. On this endpoint that is impossible.

The pipeline does not break — `RenderShotJob` degrades gracefully by dropping the
reference and rendering from the prompt alone. That is correct for one odd shot
and wrong for every shot in a video: characters drift, at full price, with
nothing saying so. `ModelRegistry::degradationWarnings()` now surfaces it on the
project page before anything is rendered.

**Kling's image-to-video endpoint is a different model id and needs its own
registry entry.** Getting FR-6 working on Kling means reading that endpoint's
page too — its price, its durations, and how it takes the reference image.

### Tier B — Third-party corroboration

Search-indexed secondary pages, much of it SEO content written to rank for
pricing queries. **No primary source in this tier was read directly** — the
egress policy blocked every vendor and reference site attempted
(`replicate.com`, `fal.ai`, `developer.puter.com`, `swychr.com`,
`businessincameroon.com`). Tagged inline as:

| Tag | Meaning |
|---|---|
| **[Corroborated]** | Two or more independent secondary sources agree |
| **[Single source]** | One secondary source only |
| **[Unconfirmed]** | Referenced but no figure found; treat as unknown |
| **[Conflicting]** | Sources disagree materially — do not plan around either number |

Useful for shape and for comparison. Never load-bearing.

### Tier C — Account facts, still unverified

Only visible from inside the live fal account, and **not to be guessed**. These
are marked `VERIFY_IN_DASHBOARD` in `config/studio.php` where they touch code:

1. Current promotional / free-credit balance, and its expiry.
2. Whether that credit is usable on the **specific models** we intend to call.
3. **The Veo 3.1 Fast per-second rate** — not supplied, and currently set to the
   Standard rate on purpose (see the over-estimate rule below). This is the
   single number with the most leverage over cost in the whole system.
4. Minimum top-up, and the payment methods the account is actually offered.
5. Whether the intended card is accepted.
6. Exact current model ids as shown in the dashboard.
7. Account rate and concurrency limits.
8. Whether billing exposes a per-request charge we can reconcile `actual_cost`
   against, or only an account-level total.
9. The webhook signature scheme, which decides whether webhooks can be trusted
   at all (see `BUILD-PLAN.md` Step 6).

**The over-estimate rule.** Where a price is unverified, config is set to the
higher known rate. Over-estimating makes the budget cap refuse a run;
under-estimating lets it overspend. Err toward refusing. `ModelCapabilities`
throws rather than returning `0.0` for an unpriced resolution, for the same
reason — a silent zero would let an unbounded run past the cap for free.

Prices moved monthly through 2026 and the PRD says so itself (§10:
*"Re-verify before committing to any provider."*).

## 1. The finding that should change your sequencing

**You may not need to solve the payment problem to finish Phase 1.**

- fal.ai: new accounts receive free signup credits, and $20/$50 promotional
  credit coupons circulate. Signup reportedly requires **no credit card**;
  payment details are added later, for paid usage. **[Corroborated]** The
  specific "$25" figure I first saw is **[Single source]** and did *not*
  corroborate — treat the amount as unknown.
- Replicate: a 7-day trial with **no credit card required**, plus a small free
  credit grant on signup. **[Single source]**

Our reference workload is a ~60-second video at roughly **$2–7 of clean run**
(costed in §5). If either platform's free grant is $10 or more, you can build
and validate the **entire** adapter chain — Steps 1 through 4 of
`BUILD-PLAN.md`, including a real exported video — before a single card is ever
charged.

That inverts the plan. Do not treat payment as a blocker to starting; treat it
as a blocker to *scaling*. **Sign up first, read the actual credit balance on
the billing page, and tell me the number.** That single fact decides how much of
this matters.

---

## 2. The candidates

I evaluated against what this system actually needs — video clips with
image-to-video reference conditioning, TTS with reliable duration reporting,
music, and image generation — not general popularity.

### fal.ai

| Criterion | Finding |
|---|---|
| Billing model | Prepaid credits, drawn down as used. Charged **only for successful outputs** — not for server errors or queue time. **[Corroborated]** |
| Credit expiry | Purchased credits expire after ~365 days; promotional credits ~90 days. **[Corroborated]** |
| Minimum top-up | **[Unconfirmed]** — described as "a minimum credit purchase is required", amount never stated |
| Payment methods | Card or ACH for US customers. Uses third-party processors. Non-US methods **[Unconfirmed]** |
| Country restrictions | Only mainland China noted (needs a proxy). Nothing found on Cameroon specifically. **[Single source]** |
| Video models | Kling, Veo, Wan and others |
| **Audio models** | **ElevenLabs TTS (v3), Gemini TTS, xAI TTS, F5-TTS, MiniMax voice clone, CassetteAI music + SFX** **[Corroborated]** |
| Catalog size | ~600+ models; the popular ones, not the long tail |
| Developer experience | Optimised for latency and DX; single `@fal-ai/client` pattern across endpoints |
| Cost vs Replicate | 30–50% cheaper, "sometimes up to 80% for video" **[Single source]** |

### Replicate

| Criterion | Finding |
|---|---|
| Billing model | Prepaid credit for all new accounts since ~July 2025 (previously arrears). **[Corroborated]** |
| Minimum top-up | **~$5 minimum, ~$15 minimum auto-reload.** **[Corroborated]** — the firmest number in this document |
| Minimum spend | None; no monthly fee, no annual contract. **[Corroborated]** |
| Payment methods | "credit card, debit card or bank transfer" per their terms. **[Single source]** |
| Country restrictions | Nothing found either way. **[Unconfirmed]** |
| Video models | Kling, Veo, Hailuo/MiniMax and others |
| Audio models | Stable Audio and open TTS models. **No commercial ElevenLabs-grade TTS found.** |
| Catalog size | Largest public catalog; widest non-image model range **[Corroborated]** |
| Billing unit | Bills by **compute time**, not per prediction **[Single source]** — a worse fit for our cost model than per-second output pricing |
| Developer experience | Built for stability and breadth; higher queue latency than fal |

### Google Gemini API / Vertex AI (Veo direct)

| Criterion | Finding |
|---|---|
| Access | No free tier for Veo 3.1; requires Gemini API paid tier or Google Cloud Billing **[Corroborated]** |
| Pricing | Per successful output second. Veo 3.1 Lite ~$0.05/s (720p), Fast ~$0.10/s, Standard ~$0.40/s **[Corroborated]** |
| **Audio-off discount** | On Vertex, **disabling audio halves Veo 3.1 Standard from $0.40 to $0.20/s** **[Single source]** — see §6, this matters to us specifically |
| Billing safety | Bills only for successfully generated seconds; safety-filter blocks are not charged **[Single source]** |
| Verdict | Strong *model*, wrong *first* relationship. Google Cloud Billing is a heavier onboarding path than a prepaid credit balance, and it is postpaid — see §4. Worth adding later as a second driver for hero shots. |

### ElevenLabs (direct)

| Criterion | Finding |
|---|---|
| TTS | $0.10 / 1k chars (v3, Multilingual v2); **$0.05 / 1k chars (Flash, Turbo)** **[Corroborated]** |
| Music | **$0.15 / minute** **[Corroborated]** — note the PRD's §10.3 figure of ~$0.30/min now looks **stale by 2×** |
| SFX | $0.12 / minute, ~200 credits per effect **[Corroborated]** |
| Verdict | Best-in-class for our narration needs, and the PRD already assumes it. But **reachable through fal.ai**, which avoids a second billing relationship. Go direct only if fal's ElevenLabs endpoint proves limiting. |

### Considered and set aside

- **kie.ai** — reported materially cheaper than fal for identical weights (Wan 2.7 at $0.08/s vs $0.10/s; ~25% spread) **[Single source]**. Worth revisiting as a cost optimisation once the pipeline works; too little known about reliability to build on first.
- **Runware** — one endpoint for speech, music and SFX, pay-per-generation **[Single source]**. A possible audio fallback.
- **Crypto/USDT gateways** (MixRoute and similar) — accept stablecoin, no KYC to start, no card needed. Genuinely relevant to the Cameroon constraint, **but** the ones I found route *LLM* traffic (Claude/GPT/Gemini), not video generation. Not a fit for our primary need. **[Single source]**
- **Suno** — leading music model, **no public API** as of early 2026; partner access only **[Single source]**. Rules it out.
- **Self-hosted Wan** — PRD Phase 4. Needs a GPU host; adds infrastructure you do not want while the pipeline is still young.

---

## 3. Can someone in Cameroon actually pay?

This is the binding constraint, and it is **not** usually the AI vendor's doing.
Locally-issued cards are frequently declined for international charges because
of FX controls and international spending limits set by the bank or the central
bank. **[Corroborated]**

Options found that are specifically Cameroonian — a meaningful improvement on
the Nigeria-focused advice I gave earlier:

| Option | What it is | Confidence |
|---|---|---|
| **MTN MoMo Mastercard virtual prepaid card** | Launched with Mobile Money Corporation in partnership with **Access Bank Cameroon**; explicitly marketed for international platforms (Netflix, Amazon, Google Play, SaaS tools) | **[Corroborated]** — reported by a Cameroonian business publication and a Cameroonian law firm's regulatory analysis, which is better sourcing than vendor marketing |
| **SwyChr** | Cameroon-registered company (reg. TPPRR/RC/BUA/2022/B/099). Free USD virtual card funded by MTN MoMo or Orange Money, no bank account required. Claims acceptance on Netflix, Amazon, Meta Ads, Shopify, Google | **[Single source, and it is the vendor's own marketing]** — treat claims with caution |
| **Kang Card** | Virtual cards for Cameroon, topped up with MTN or Orange Mobile Money | **[Single source, vendor's own site]** |

**What I could not confirm, and you must test:** whether any of these clears a
**US SaaS recurring/API charge** specifically. Netflix and Amazon acceptance is
weak evidence — those are consumer merchants. Nothing I found addresses Stripe-
processed developer API billing, which is what fal and Replicate use. **This is
the single most important unknown in the entire decision.**

Do not take a marketing claim of "works internationally" as an answer. The test
below is the answer.

---

## 4. Financial risk if a bug causes runaway generation

A finding that matters more than it first appears:

> Most API spending limits — including "budget" or "usage cap" settings on major
> AI platforms — are **notify-only**: they email you once spend crosses a
> threshold, but the key keeps accepting requests and the bill keeps growing.
> **[Corroborated]**

Neither fal's nor Replicate's spend-limit behaviour could be confirmed.
**[Unconfirmed]**

So the only dependable hard stop is **a prepaid balance**. When the balance hits
zero, calls fail — there is no invoice to argue about afterwards. Both fal and
Replicate are prepaid; Google Cloud Billing is not. That is a real reason to
prefer an aggregator over Vertex for your *first* relationship.

Our own defences stack on top of that, and they are already built and tested:

- `CostEstimator::assertWithinBudget()` refuses a run before any job is queued
- a hard per-project `budget_cap_usd`, default $15
- `assertCanSpend()` guards every individual charge
- `AssetRecorder` makes it impossible to generate without recording the cost

Three independent layers: our pre-flight estimate, our per-project cap, and the
provider's prepaid floor. **Keep the prepaid balance small — fund one video's
worth at a time** until you trust the numbers.

---

## 5. What our actual workflow costs

Our reference case, from PRD §13: a 60-second narrated folk tale — 8 scenes ×
~8s = **64 seconds of clip**, 3 character reference images, ~900 characters of
narration, 1 minute of music.

| Line item | Basis | Cost |
|---|---|---|
| Video, Kling 3.0 | 64s × $0.07/s **[Corroborated]** | **$4.48** |
| Video, Kling 3.0 (low estimate) | 64s × $0.029/s **[Conflicting]** | $1.86 |
| Video, Kling 3.0 (high estimate) | 64s × $0.10/s **[Conflicting]** | $6.40 |
| Character references | 3 images, PRD §13 figure | ~$0.20 |
| Narration | 900 chars, ElevenLabs Flash @ $0.05/1k | ~$0.05 |
| Music | 1 min, ElevenLabs Music @ $0.15/min | $0.15 |
| **Clean run total** | | **~$2.3 – $6.8** |
| With regenerations | PRD's realistic +50–100% | **~$3.5 – $13.6** |

**Sources disagree about Kling on fal by roughly 3–5× ($0.029 vs $0.07 vs
$0.10–0.20/s).** I will not pick one. Read the real per-second rate off the
model page before you budget, then put it in `config/studio.php`.

Veo 3.1 Standard at $0.40/s would cost **$25.60** for the same 64 seconds —
above our default $15 cap. This confirms the PRD's judgement that Veo is for
hero shots, not the default engine.

**The PRD's ~$7.10 clean-run estimate holds up** — it sits at the conservative
end of this range, which is the right place for an estimate to sit.

Not in any of these numbers: **your bank's FX spread and foreign-transaction
fee.** Measure them on the first real charge and fold them into the config
constants.

---

## 6. Two findings that affect the code

**a) Ask the provider to disable audio, don't just strip it.** Veo 3.1 Standard
reportedly drops from $0.40/s to $0.20/s on Vertex when audio generation is
disabled **[Single source]**. Our FR-14 already mutes native audio — but we
currently enforce it with `-an` in FFmpeg, *after* paying for it. `ClipRequest`
already carries `muteNativeAudio`, so the adapter must forward it as a **provider
request parameter**, not merely post-process. On an audio-capable model that is
potentially a **50% saving on the largest line item in the whole system.** Worth
checking for Kling too.

**b) Per-second output pricing fits our cost model; compute-time pricing does
not.** `VideoGenerator::costPerSecondUsd()` assumes we can price a clip from its
duration. Replicate reportedly bills by **compute time** **[Single source]** —
which varies per run and cannot be known before dispatch, so our pre-run
estimate (FR-11/NFR-4) would be an approximation there rather than a real
figure. fal's "pay only for successful outputs" model matches our interface
directly. **This is an architectural argument, not a price argument, and it is
the strongest single point in fal's favour after the audio catalogue.**

---

## 7. Recommendation

### Start with fal.ai.

Three reasons, in order of weight:

1. **One billing relationship covers all four capabilities.** fal hosts video,
   image, **ElevenLabs TTS**, and CassetteAI music/SFX. Replicate's catalogue is
   larger overall but I found no commercial ElevenLabs-grade TTS on it, which
   would mean a second vendor and a second card that has to clear. When your
   binding constraint is *getting one payment method to work at all*, going from
   two relationships to one is worth more than any price difference. This is
   also exactly what PRD §10 asks for.
2. **Its billing model matches our interfaces.** Per-second, pay-only-for-
   successful-output pricing is what `costPerSecondUsd()` and the pre-run
   estimate were designed around. Compute-time billing would weaken the budget
   cap into a guess.
3. **Cheaper for video**, by 30–50% on the comparisons found.

### Keep Replicate as the fallback, and know your trigger

Switch if **either** happens: fal's minimum top-up turns out to be high (it is
still unverified — the ~$5 figure in this document is Replicate's, not fal's), or
fal's payment page rejects your card and Replicate's does not. The architecture makes this a config change
plus one adapter class — that is the whole point of NFR-6, and it is why this
decision is reversible and not worth agonising over.

### Payment method: test in this order

1. **MTN MoMo Mastercard virtual prepaid card** — first choice. Mastercard-
   backed through a licensed Cameroonian bank, best-sourced of the three, and
   funded from money you already hold in mobile money.
2. **SwyChr** — second. Cameroon-registered, free card, MoMo/Orange funded. But
   every claim I found is its own marketing, so verify before relying on it.
3. **Kang Card** — third, same caveat.

Prepaid in every case: a card that cannot be overdrawn is a fourth layer of
protection under the three we already have.

---

## 8. What to actually do next

Updated 22 Sep 2026. Steps 1 and 2 are done; what is left needs money.

**Done**

1. ~~Create a fal.ai account.~~ Created. **No free credit** — the balance is
   $0.00 and there have been zero requests, which contradicts the third-party
   reports that suggested Phase 1 might be finished before paying anything.
2. ~~Read the model's real figures.~~ Done for **Kling 2.5 Turbo Pro**
   text-to-video: $0.07/s, 5 or 10 second clips, no audio toggle, text-to-video
   only. All four are in `config/studio.php` and pinned by `KlingModelTest`.

**Next**

3. **Read the Kling image-to-video model page** and correct four values.
   A registry entry now exists — `kling-2-5-turbo-pro-i2v` — but **nothing in
   it was read from the page.** It is there so the pipeline's image-to-video
   path is built, tested and reachable; it is not there because the facts are
   known. The four `VERIFY_IN_DASHBOARD` markers in `config/studio.php` are:

   | # | Value | Currently | How it was arrived at |
   |---|---|---|---|
   | 1 | endpoint | `fal-ai/kling-video/v2.5-turbo/pro/image-to-video` | last segment of the verified t2v id, substituted |
   | 2 | clip lengths | `[5, 10]` | inherited from the verified t2v sibling |
   | 3 | resolutions / ratios | as t2v | same unverified state as t2v |
   | 4 | **price** | **$0.20/s** | over-estimate: top of the third-party range (§2) |

   Shipping it unverified is safe because the failure modes are asymmetric:
   **a wrong endpoint or parameter name returns a 4xx and is not billed, while
   a wrong price is billed.** So the endpoint is a structural guess and the
   price errs high.

   The price is the one to fix first. At $0.20/s — 2.9x the verified
   text-to-video rate — a 64-second video is $12.80 against the $15 default
   cap, leaving no room to regenerate a shot. The estimator will refuse work
   that may well be affordable. That is the correct direction to be wrong in,
   and one number fixes it.

   Note also: the entry declares **image-to-video only**, so it is meant to be
   used as a *companion* rather than a project's only model. A project now pins
   a primary and an optional companion, and each shot renders on whichever one
   suits it — so this entry handles the character shots while a text-to-video
   model handles the establishing ones. Choosing it alone still works and still
   warns, because shots with no locked character cannot render on it.
4. **Get a payment method that clears a USD charge from Cameroon.** The MTN
   MoMo Mastercard virtual card is the best-sourced option (§3). Fund the
   smallest top-up fal allows — that figure is still unverified, so read it off
   their billing page rather than assuming the ~$5 quoted elsewhere in this
   document, which is Replicate's.
5. **Capture the queue payload shapes.**
   `php artisan studio:capture-fal-shapes` makes one small real generation and
   records the submit response, a status response caught mid-flight, and the
   completed result. The payload is built by `FalPayloadBuilder` — the same
   class the adapter uses — so what is captured is byte for byte what a real
   render will send. On Kling that is `prompt`, `duration` and `aspect_ratio`:
   no `generate_audio`, which the schema does not expose, and no `resolution`
   or `seed`, whose names are unconfirmed there.

   If it comes back 4xx, `--minimal` re-probes with `prompt` and `duration`
   alone. That tells an unaccepted optional parameter apart from a wrong URL,
   which otherwise look identical.

   `--dry-run` prints the exact request and costs nothing; the real run asks
   before spending and redacts the key from everything it writes.
6. **Read the invoice.** Confirm the charge, the FX rate and any
   foreign-transaction fee. Those are part of the true cost per video and
   belong in the config constants.

### What changed on 22 Sep: the adapter was written anyway

`FalClient`, `FalPayloadBuilder`, `FalResponseMapper` and `FalVideoGenerator`
now exist, and `STUDIO_VIDEO_DRIVER=fal` binds them. That was not a decision to
stop waiting for step 5 — it was a decision about *where* the unverified part
should live.

The reasoning, because it is the kind of call worth being able to re-examine:

- The facts that are owner-verified are also the facts that cost money to get
  wrong. Kling's 5-or-10-second ladder, its missing audio toggle, its
  text-to-video-only mode: each of those is now refused **before** any HTTP call
  and pinned by a test. None of that needed a captured payload, and every day it
  did not exist was a day a bug in the timing engine could have bought a 4xx.
- The facts that are *not* verified are all response-shape facts, and they are
  now confined to one class, `FalResponseMapper`. Correcting them when the
  capture arrives is a change to one file with one test file pinning it, not an
  archaeology exercise across an adapter.
- The mapper is written to tolerate being wrong. It looks for the output at
  fal's documented path first, then falls back to finding any URL in the
  response that looks like a video file. A renamed key degrades into a slower
  lookup rather than a pipeline that cannot collect work it has already paid
  for. The same applies to the request URL: fal's own `status_url` and
  `response_url` are followed when the submit response carries them, and only a
  cold cache falls back to a constructed URL — which tries both the full model
  path and the two-segment form before giving up.

So step 5 is still worth doing, and it is still the thing that turns an
assumption into a fixture. What it no longer blocks is everything else.

Steps 3 and 5 remain independent — the payload shapes can be captured on the
text-to-video endpoint, with image-to-video added afterwards as a second
registry entry.

---

## 9. Deep search, 23 Sep 2026 — the image-to-video entry

fal.ai is still **unreachable from this codebase**: every `fal.ai` fetch returns
`EGRESS_BLOCKED` from the build environment's proxy, as do `atlascloud.ai`,
`agent-skills.md` and other aggregators. Web *search* works and returns fal's
own pages in its index, so the findings below come from search summaries of
those pages plus third-party mirrors — **Tier B throughout**. Nothing here was
read off the page by the account owner, which is what Tier A requires.

That matters for the price above all, so read the caveat on each row.

### What was corroborated

| Value | Finding | Strength |
|---|---|---|
| Endpoint | `fal-ai/kling-video/v2.5-turbo/pro/image-to-video` | **The guess was right.** fal's own model page for it is indexed at exactly that path, and a third-party reference quotes `POST https://fal.run/fal-ai/kling-video/v2.5-turbo/pro/image-to-video`. |
| Price | $0.35 for 5s, $0.07 per additional second — flat **$0.07/s** | Three independent searches agree, and it equals the owner-verified text-to-video rate. |
| Durations | `duration` enum, **5 or 10**, default 5 | Matches the sibling, as expected for a ladder belonging to the model rather than the conditioning. |
| Input schema | `prompt` (required), `image_url` (required), `duration`, `negative_prompt`, `cfg_scale`, `last_image` | Consistent across the v1, v1.6, v2.1 and v2.5 image-to-video pages. |
| Aspect ratio | **Not a parameter on image-to-video.** | Absent from every image-to-video schema found. One search that mixed text-to-video and image-to-video reported `16:9 / 9:16 / 1:1`; the version-specific results do not. Framing comes from the starting image. |

### The price correction, and why the over-estimate rule did not hold

The entry shipped at **$0.20/s** — the top of the third-party range, chosen
under the over-estimate rule because no image-to-video figure existed at all.
It is now **$0.07/s**.

The rule governs an *unknown* price. It is not a licence to keep a number three
sources contradict: an estimate everyone knows is wrong is an estimate nobody
reads, and a cap calibrated on it refuses work that is affordable. The exposure
if this is still wrong is bounded and small — Kling **v3** turbo pro
image-to-video is $0.14/s, so the worst plausible error is 2x, not unbounded.

### The consequence for framing

Because the endpoint takes no `aspect_ratio`, the output follows the starting
image. That is safe here only because `GenerateCharacterCandidatesJob` already
generates every character reference at `$project->aspect_ratio` — so character
shots come back in the project's shape without being asked. A test pins that
property, because if reference generation ever stops honouring the project
ratio, character shots start arriving in the wrong shape and the assembler
letterboxes them.

### The queue protocol, confirmed

The shapes `FalClient` was written against are corroborated by fal's own queue
documentation:

- submit returns `{request_id, response_url, status_url, cancel_url, queue_position}`
- status values are `IN_QUEUE`, `IN_PROGRESS`, `COMPLETED`
- status sits at `https://queue.fal.run/{model_id}/requests/{request_id}/status`
- the video URL sits at `video.url` in the result

Two things stayed ambiguous and the adapter now covers both:

1. **The result URL.** fal's queue docs show the bare
   `/requests/{request_id}`; one documented submit response carries a
   `response_url` ending `/response`. Both are now tried, bare first.
2. **The model id in a request URL.** fal documents a model id as
   `namespace/model` — two segments. Kling's is five. Both forms were already
   tried; the docs now explain why.

Neither costs anything: a wrong candidate is one 404 on a cold cache, and when
the submit response supplies the URL it is followed verbatim.

### Still Tier C — only the account can answer

- The minimum top-up, and whether a Cameroonian card clears it.
- Whether a `resolution` parameter exists on either endpoint. No source
  mentions one, which is why none is sent.
- Whether Kling 2.5 accepts `1:1`. Evidence conflicts, and it is moot for
  image-to-video, which takes no ratio at all.

### Worth knowing for later

Newer Kling models are on fal: **v2.6 pro image-to-video** (advertised with
native audio, which FR-14 would want switched off) and **v3 turbo pro
image-to-video at $0.14/s**. There is also a **standard** tier of 2.5 turbo
alongside pro. None is registered; 2.5 turbo pro remains the only model with
any owner-verified figure behind it.

---

## 10. Choosing a narration provider, 23 Sep 2026

Narration was the largest remaining gap, and not a close call: it is the master
clock (FR-16/17/18). Shot durations round up to it, the assembler trims or
holds every clip to it, and the export's runtime *is* it. The fake driver emits
a tone of the right length — enough to exercise every timing rule, and no use
at all as a video.

### The number that settled it

Narration for a 60-second video is roughly **900 characters**.

| Option | Rate / 1k chars | 60s video | Share of a ~$4.48 run |
|---|---|---|---|
| Inworld TTS-1.5 Max (fal) | $0.010 | $0.009 | 0.2% |
| xAI TTS v1 (fal) | $0.015 | $0.014 | 0.3% |
| **Kokoro (fal)** | **$0.020** | **$0.018** | **0.4%** |
| Chatterbox (fal) | $0.025 | $0.023 | 0.5% |
| Dia / Orpheus / F5 (fal) | $0.040–0.050 | $0.036–0.045 | ~1% |
| **ElevenLabs Eleven v3 (fal)** | **$0.100** | **$0.090** | **2%** |
| ElevenLabs direct | $0.100 | $0.090 | 2% + a second account |

**Speech is at most 2% of a run.** Choosing the cheapest option over the
dearest saves about seven cents on a 60-second video. So price is noise, and
the decision belongs to voice quality, language, and operations.

### Why fal rather than ElevenLabs directly

fal *hosts* ElevenLabs, at the same $0.10/1k. So going direct buys nothing on
price and costs a second account, a second API key, and — the part that
actually matters here — **a second payment method that clears a USD charge from
Cameroon**. That is the project's real blocker (PRD A2), and solving it once is
worth more than any rate in the table.

It also means one adapter: `FalClient`, its error taxonomy and its queue
handling were already written and tested for video.

### Why Kokoro is the default

- **The product is narration.** Explainers, folk tales and adverts — a narrator
  explaining, not a character emoting. Comparisons put ElevenLabs ahead on
  prosody and emotional range, and Kokoro ahead on clear professional
  narration, which is the job here.
- **French.** Cameroon is officially bilingual and Kokoro ships a dedicated
  French model. Benchmarks put it at the lowest word error rate in 6 of 10
  languages tested against ElevenLabs Multilingual v2.
- **Switching costs one config line.** `STUDIO_SPEECH_MODEL=elevenlabs-v3` when
  expression matters more than clarity. Given the table above, switch freely.

### The structural finding

**Language selection is endpoint selection.** Kokoro ships a separate model id
per language (`fal-ai/kokoro/american-english`, `fal-ai/kokoro/french`) while
ElevenLabs takes one endpoint for all of them. Speech models therefore carry an
endpoint *map*, not a single endpoint — which makes a bilingual project a config
entry rather than a special case in the adapter, and makes PRD Phase 3
multilingual work mostly already done.

A language the configured model cannot speak is refused **before** any request
is sent. Narration in the wrong language is a wrong result, not a degraded one,
and a silent fallback to English would be paid for before anyone noticed.

### Tier

All Tier B: search summaries of fal's own model pages, 23 Sep 2026. fal.ai
remains `EGRESS_BLOCKED` from this codebase. The rates and the two endpoint ids
need confirming on a live account, and the voice parameter is unconfirmed
enough that nothing optional is sent at all — the models' own default voices are
used, which is a usable narration rather than an error.

---

## 11. Character reference images, 23 Sep 2026

The last piece of PRD goal G2. Per-shot model selection and the image-to-video
entry both terminate in a locked character reference — which until now was
generated by a fake. The chain was built and dead-ended.

### Price is not the constraint

Three candidates per character, so a three-character project is nine images.
FLUX.1 [schnell] is the cheapest of the line; sources disagree between
$0.003/megapixel and $0.008 for a 1024×1024, which across nine images is $0.027
against $0.072. Neither can threaten a run next to a ~$4.48 video, so the
higher figure is used under the over-estimate rule and the decision is made on
two other things.

### Requirement 1 — the seed

A locked reference has to be reproducible (PRD §10.2, NFR-5). A model that
ignores the seed produces a reference that can never be regenerated, which
quietly turns "locked" into "lucky". FLUX honours `seed` and echoes the one it
used when none was supplied — so the adapter records **fal's answer** in
preference to ours, or it would store a null against an image that can in fact
be reproduced.

### Requirement 2 — the shape, and why it is the important one

**FLUX does not take width and height. It takes a preset name** from a fixed
set: `square_hd`, `square`, `portrait_4_3`, `portrait_16_9`, `landscape_4_3`,
`landscape_16_9`.

So the project's aspect ratio has to be translated, and that translation is
load-bearing rather than cosmetic:

> Kling's image-to-video endpoint takes **no `aspect_ratio`** (§9). It inherits
> the framing of its starting frame. The locked character reference *is* that
> starting frame.

A reference generated square on a 16:9 project therefore mis-frames every
character shot in the finished video, at full price, with nothing saying so.
The mapping lives in `image_models.*.image_sizes`, each entry is pinned by a
test, and a ratio the model does not declare is refused rather than
substituted.

`ModelRegistry::incompatibilityReason()` now checks the image model alongside
the video models, because making image ratios model-specific reopened the same
gap the video companion had: a project could otherwise be created at a ratio
one of its models cannot produce and only fail once the run was under way.

### Tier

Tier B — search summaries of fal's model pages, 23 Sep 2026. fal.ai remains
`EGRESS_BLOCKED`. The per-image price is the one value worth confirming first,
and it is the one that matters least.

## 12. The background music bed, 24 Sep 2026

The last piece of the audio mix. Music is the first capability in this pipeline
where **the choice of model is not a free one** — and the money turns out to be
the second reason, not the first.

### Price finally matters here, unlike speech and images

The spread across fal's music models is roughly **125×** for the same job.

| Model | Rate | 64-second bed | Share of a ~$4.48 run |
| --- | --- | --- | --- |
| ACE-Step | $0.0002/s | **$0.013** | 0.3% |
| Stable Audio 3 Medium | $0.0417 flat per request | **$0.042** | 0.9% |
| DiffRhythm | $0.001/s | $0.064 | 1.4% |
| MiniMax Music | $0.80 per output minute, **rounded up to the next minute** | **$1.60** | **36%** |

Narration is under 2% of a run and nine reference images are cents, so both were
chosen purely on quality. MiniMax at 36% of a run is a different kind of
decision — a careless default there genuinely moves the budget, and the
rounding-up makes a 61-second video cost the same as a 120-second one.

### But vocals disqualify the expensive options anyway

MiniMax and DiffRhythm generate **songs** — vocals and lyrics. This project's
music is a *bed* under narration (FR-13), mixed with `sidechaincompress` keyed on
the narrator's voice (FR-20). A sung line competes with the one voice the whole
video is built around (FR-14), and **ducking cannot fix that** — ducking makes it
quieter, not less distracting.

So the requirement is not "cheap music". It is:

1. **instrumental, guaranteed** — not "instrumental if the prompt asks nicely";
2. duration control, so the bed covers the timeline;
3. cheap enough not to dominate the budget;
4. licensed for commercial use.

### Why Stable Audio 3 Medium is the default

It satisfies (1) **structurally rather than by parameter**: Stable Audio 3 is
instrumental and sound-design only. It cannot sing. That is a property of the
model, not a field name we would be guessing at from search summaries.

It also settles (4), which is the finding worth keeping. fal claims no rights in
outputs, but a music model's **training data** is the live question for anything
meant to be published:

> Stable Audio 3 was trained on 806,284 AudioSparx-licensed and 472,618
> Freesound Creative Commons recordings, and the Stability AI Community License
> grants commercial use of outputs for organisations under $1M annual revenue
> (Enterprise License above it).

For a product meant to be deployed and used by real people, provenance is a
larger risk than a few cents. Separately, the US Copyright Office's position is
that purely AI-generated work may not be copyrightable — so an output can be
used and sold but is not necessarily defensible against a third party who copies
it. That is worth knowing before a client is told the soundtrack is "theirs".

Its **flat pricing** then makes it the cheapest option in the registry above
about three minutes: $0.0417 whether the track is 30 seconds or 380.

### ACE-Step is registered as the worked example of suppression

Four times cheaper on a one-minute video, and it **can** sing — so it is only
safe here because `payload_defaults` sends `instrumental: true`. The adapter
refuses to call a vocal-capable model that has neither `instrumental` nor
`lyrics` configured, before spending anything: an unusable track paid for in full
is worse than a refusal that names the config line to change.

Not the default for two reasons beyond provenance: the `prompt-to-audio` variant
expands the prompt with a **provider-side LLM**, so the same prompt need not give
the same brief twice (NFR-5), and the base `fal-ai/ace-step` endpoint takes
comma-separated genre `tags` plus `lyrics` rather than natural language.

### What this forced in the code

**The cost contract changed shape.** `MusicGenerator::costPerMinuteUsd()` cannot
express a flat per-request charge without knowing the length, so it became
`costForSeconds(float $seconds)`. `MusicModelCapabilities` then carries both
`cost_per_request_usd` and `cost_per_second_usd` and the total is
`flat + per_second × seconds` — one formula for both billing models.

**Field names became config, not constants.** Audio models disagree about them:
ACE-Step's style input is `tags` and its length is `duration`; Stable Audio
Open's length is `seconds_total`. So `prompt_parameter` and `duration_parameter`
are registry entries. `duration_parameter: null` means the model takes no length
at all, and nothing is invented — one unaccepted field fails the whole request,
and fal's 422 reads like a wrong URL.

**Clamping, uniquely, rather than refusing.** Everywhere else in this codebase an
unsupported capability is refused. Here the assembler already loops the music
input (`-stream_loop -1`), so a bed shorter than the video *repeats* rather than
leaving silence — a quality compromise, not a wrong result. A 500-second timeline
on a 380-second ceiling is clamped, and `looped_to_cover_timeline` is recorded on
the asset so an audible repeat has a traceable cause.

**The mood is not a prompt.** Scenes carry a mood from a closed enum, and "tense"
alone is a poor prompt for a text-to-audio model. `FalMusicGenerator::MOOD_PROMPTS`
translates each one into an instrumentation brief that names instruments, a tempo,
and the fact that the track sits under a spoken voiceover. That phrasing is
provider-side, so it lives in the adapter next to the fake driver's
mood-to-frequency table — not in the enum.

### The bug this turned up on the way in

`FalClient` takes its credential as a plain `string`, which the container cannot
autowire, and **nothing bound it**. So `STUDIO_VIDEO_DRIVER=fal` threw
`Unresolvable dependency resolving [Parameter #0 [ <required> string $apiKey ]]`
before a single request was made — every fal adapter in the project was
unreachable in production. The unit tests never saw it because they all construct
the adapters by hand.

One line in `StudioServiceProvider` fixes it, and
`test_every_fal_driver_resolves_through_the_container` now asserts all three
resolve, so it cannot come back for one of them.

### Tier

Tier B — search summaries of fal's and Stability's model pages, 24 Sep 2026.
fal.ai remains `EGRESS_BLOCKED` (re-verified, not recalled). Two values are worth
confirming first because both cost a 422 mid-run rather than money:
`duration_parameter` on Stable Audio 3, and ACE-Step's actual maximum length
(registered conservatively at 240s).

---

## 13. Per-scene sound effects, 24 Sep 2026

The last capability, and the one where the research changed what got built.

### What the code said before the models did

`SoundEffectGenerator` existed, was bound in `StudioServiceProvider`, and was
called **by nothing**. No job, no cost line, no cue source, and `assets` had no
`scene_id` to position an effect with. Building only the adapter would have
produced the exact failure this project has already corrected twice — a signal
computed and then discarded (`SceneDraft::characterNames`, then the structurer's
`warnings`). It would have looked finished and been inert.

So the deliverable was the vertical slice: cue → effect → position on the
timeline. The PRD's "Phase 2" on SFX is a sequencing note, and everything ahead
of it in the sequence is now built.

### The models

| Model | Price | Length | Notable |
| --- | --- | --- | --- |
| **ElevenLabs Sound Effects V2** | **$0.0194 per effect**, flat | `duration_seconds` 0.5–22, null lets the model choose | **a real `loop` flag**; `prompt_influence` 0–1 (default 0.3) |
| Stable Audio 3 Small SFX | unpublished on fal | variable | 459M params, on-device oriented, same licensed corpus as the music default |

ElevenLabs is the default on **schema clarity, not price**. At $0.0194 an effect
the price cannot decide anything — a six-scene video is 12 cents against a ~$4.48
run. What it wins on is that its input schema is documented and specific, and
that it has a genuine loop flag.

### The 22-second ceiling is the design constraint

Scenes routinely run 30–40 seconds; the default model generates at most 22. So an
effect under a whole scene **has to repeat**, and an effect that was not
*generated* to loop has an audible seam every time it wraps — under the
narration, at a predictable interval, which is exactly where a listener notices
it.

`loop: true` is therefore sent **only when the effect is actually going to
repeat**. Sent unconditionally it would constrain the sound the model produces
for a one-shot that fits its scene, buying nothing.

### The expensive failure is a false positive, not an expensive model

This inverts the usual cost reasoning. Effects are billed per effect, so the bill
is *the number of scenes with cues* — and a cue that should not have been there is
money spent on a sound that does not belong in the video. That is worse than
silence: it sounds deliberate, so someone has to notice it, diagnose it, and ask
for the render again.

Three consequences, all of them refusals:

- Cue detection is a **closed keyword map** (`HeuristicScriptStructurer::SFX_CUES`,
  whole-word matched) and **never falls back**. Unlike `setting`, which returns
  "Scene 3" because a scene must render somewhere, a cue returns `null` — and
  `null` is the common case.
- Every entry in the map is **sustained ambience**. A one-shot there (a gunshot, a
  slammed door) would buy 22 seconds of it looped under the narration: the
  woodpecker failure. One-shots placed at a moment *inside* a scene stay Phase 2,
  because they need positioning within a scene rather than at it.
- `ScriptBreakdownValidator` rejects a **blank** cue. The difference between
  `null` and `''` is the difference between "this scene has no ambience" and "buy
  me whatever you imagine", and only one of them is free.

### Offsets come from the rendered shots, not the scene list

An effect is the first asset in this pipeline whose place on the timeline is not
"the whole video", so the assembler has to know where its scene starts. It
accumulates offsets from the **rendered shots it is actually laying down**. Taking
the scene list instead would drift every effect after any unrendered or stale shot
— and an effect landing in the wrong scene is worse than no effect, because it
sounds intentional.

The spans come out shorter than the planned shot lengths, which is correct and
worth stating: narration is the master clock, so the assembler trims to it
(FR-18). An ambience sized to the *planned* length overruns the cut it belongs to.

### The audio graph had to stop being a branch per combination

`buildAudioBed()` had three explicit paths (music only, narration only, both).
Adding effects would have made it eight, and the eighth would have been the one
nobody tested. It is now compositional: each source contributes one filter chain
and one label, the labels are mixed, and the mix is ducked if there is a narration
to duck against. Two sources or ten, same code.

Two details that only show up when you run it:

- **`asplit` with an unused output fails the whole graph.** ffmpeg refuses an
  unconnected pad, so the narration is split into a mix copy and a sidechain key
  *only* when there is actually a bed to duck.
- **`amix=duration=longest` for the beds**, not `first`. An effect belonging to the
  last scene starts late, and `first` would cut the mix at whichever bed happened
  to be listed first.

Effects sit at `sfx_bed_db` (−12 dB) — below the music bed at −6, both ducked by
the same sidechain. Ambience should be noticed only if you listen for it; music
carries the mood.

### A Laravel testing trap worth recording

`Http::fake()` **merges** stubs rather than replacing them, and a faked response
body is a stream that can only be read once. A test that generates twice hits the
spent stream of the first stub and sees an empty download that looks like a
provider bug. Stub with closures — `fn () => Http::response(...)` — and each call
gets a fresh body.

### Tier

Tier B — search summaries of fal's and ElevenLabs' model pages, 24 Sep 2026.
fal.ai remains `EGRESS_BLOCKED`. Worth confirming first: Stable Audio 3 Small
SFX's price and field names (all three assumed from the medium text-to-audio
model, which is why it is not the default), and whether `loop` behaves as
documented on a 22-second generation.

---

## 14. Live progress: why polling, 24 Sep 2026

The last real gap, and the one where the research mattered most for what NOT to
build.

### The problem is worse than "you have to refresh"

The pipeline runs on a queue, so a page served mid-render shows whatever was true
when it was served. That is not merely inconvenient — it is **misleading**. An
owner who refreshes at the wrong moment sees "0 of 6 shots rendered" and
reasonably concludes the stage failed, then presses the button again. On real
providers that second press is money.

### The three options, against this deployment

| Approach | Verdict |
| --- | --- |
| **Laravel Reverb** (websockets) | Right on a VPS, wrong here. It is a long-running PHP process on its own port; Laravel's own docs note it needs server tuning for connection counts. This deploys on **XAMPP and Apache**. |
| **Server-sent events** | Laravel 13 genuinely ships `response()->eventStream()`, so the framework side is a one-liner. The *deployment* side is not: every open stream holds **one Apache worker and one PHP session file lock** for its entire life. PHP locks the session file for the duration of a request, and an SSE request never ends — so a second tab, or any form POST, blocks until the stream closes. The documented mitigation is `session_write_close()`, which in a Laravel app means giving up the session mid-request. |
| **Polling** | Chosen. |

### But the decisive reason is not infrastructure

**The stages here take minutes.** A clip is ~30 seconds of provider time,
narration is seconds, assembly is an ffmpeg pass. Sub-second delivery buys a human
watching that *nothing at all*. Three seconds is indistinguishable from instant at
this timescale, and it costs one cheap GET.

Picking websockets here would have been choosing the more impressive answer over
the correct one, and paying for it in a deployment that cannot host it.

### What makes polling acceptable rather than merely simple

Naive `setInterval` polling is genuinely bad — it runs in background tabs, burns
mobile data and drains batteries, and mobile radios are power-hungry enough that
this is measurable. So:

- **Pause on `document.hidden`**, and poll immediately on becoming visible rather
  than waiting out a stale interval. This is the single biggest saving available,
  and it also makes the loop *more* predictable, because browsers throttle
  background timers unpredictably anyway.
- **Geometric backoff** on a quiet project: 3s → 30s over about six polls, then
  stop entirely. An idle project costs one request and then nothing.
- **`busy` comes from the server**, computed from shots not yet terminal and
  outstanding provider requests. It is the poller's on/off switch.
- **One request at a time**, via `AbortController`, with a 10s timeout. Without
  that, an endpoint that starts responding slowly accumulates overlapping requests
  — each holding the session lock — until the app appears to hang.
- **`ETag` via Laravel's `cache.headers` middleware.** Most polls return exactly
  the bytes of the last one; a 304 sends none of them. On a metered mobile
  connection, which is the normal case in Cameroon, that is the difference between
  paying for those bytes hundreds of times and not at all.
- **Honour `Retry-After` on 429** rather than guessing, and stop with a visible
  message after five consecutive failures. Claiming to be live while silently
  dead is worse than admitting it stopped — which is also why a 401/403/419 stops
  the loop and says "session expired" instead of polling a login redirect forever.

### The design decision worth defending

**The poller does not re-render the page.** It updates a small strip in place, and
when a stage *finishes* it triggers a full reload.

Rebuilding shot cards, cost tables and warning banners in JavaScript would mean
two rendering paths for the same data, and the one nobody looks at drifts from the
one they do. So Blade stays the single source of truth, and the reload is the
mechanism that keeps it true.

Reloads are limited to **structural** change — the project status moved, the export
landed, or `busy` went true→false. Reloading on every fingerprint change would
refresh the page each time one shot of six finished, throwing away the owner's
scroll position and any half-typed scene edit.

### Two things the snapshot must not do

**`fingerprint` must not contain a clock.** A snapshot that changed every second
would make every poll look like progress, and since structural change triggers a
reload, the page would refresh itself every three seconds forever. A test asserts
two consecutive snapshots of an unchanged project are identical.

**The endpoint must stay read-only.** It is reachable by a plain GET with no CSRF
token, which is only safe while that holds — so a test polls three times and
asserts status, spend, asset count and usage records are all unmoved.

`Stale` deliberately does not count as busy: stale means "waiting for a decision",
not "running", so counting it would poll forever on a project whose owner has gone
to lunch.

### Tier

Not a provider question — no Tier applies. Sources are Laravel's own
documentation for `eventStream`, Reverb and `cache.headers`, plus the PHP session
locking literature.

---

---

## Sources

All secondary. None read directly; all via search-engine summaries.

Pricing and platform comparison:
- https://fluxnote.io/guides/ai-video-model-pricing-comparison-2026
- https://invideo.io/blog/ai-video-model-pricing/
- https://www.buildmvpfast.com/api-costs/ai-video
- https://nodetool.ai/blog/ai-video-generation-cost
- https://www.atlascloud.ai/blog/guides/cheapest-ai-video-generation-api-2026
- https://ofox.ai/blog/fal-ai-alternatives-video-generation-api-2026/
- https://www.gmicloud.ai/en/blog/fal-ai-vs-replicate
- https://www.scopeful.org/blog/fal-vs-replicate
- https://www.teamday.ai/blog/fal-ai-vs-replicate-comparison

Billing and credits:
- https://replicate.com/docs/topics/billing/prepaid-credit *(blocked; search summary only)*
- https://replicate.com/docs/topics/billing *(blocked)*
- https://replicate.com/terms *(blocked)*
- https://fal.ai/docs/documentation/model-apis/pricing *(blocked)*
- https://costbench.com/software/ai-ml-platforms/fal/free-plan/
- https://yangmao.ai/en/providers/fal/no-credit-card/
- https://yangmao.ai/en/providers/replicate/no-credit-card/

Audio:
- https://elevenlabs.io/pricing/api
- https://unifically.com/blogs/elevenlabs
- https://developer.puter.com/tutorials/elevenlabs-api-pricing/ *(blocked)*
- https://fal.ai/explore/text-to-speech-apis *(blocked)*

Google Veo:
- https://ai.google.dev/gemini-api/docs/pricing
- https://www.atlascloud.ai/blog/tips/veo-3.1-api-pricing

Spend-limit risk:
- https://blog.redhub.ai/api-spending-limits

Deep search, 23 Sep 2026 — all via search summaries; fal.ai itself is
egress-blocked from this codebase:
- https://fal.ai/models/fal-ai/kling-video/v2.5-turbo/pro/image-to-video *(blocked; indexed)*
- https://fal.ai/models/fal-ai/kling-video/v2.5-turbo/pro/image-to-video/api *(blocked; indexed)*
- https://fal.ai/docs/model-api-reference/video-generation-api/kling-video-v2.5-turbo-pro *(blocked)*
- https://fal.ai/docs/model-endpoints/queue *(blocked)*
- https://docs.fal.ai/model-apis/model-endpoints/queue *(blocked)*
- https://fal.ai/models/fal-ai/kling-video/v3/turbo/pro/image-to-video *(blocked; indexed)*
- https://www.atlascloud.ai/models/kwaivgi/kling-v2.5-turbo-pro/image-to-video *(blocked)*
- https://vercel.com/ai-gateway/models/kling-v2.5-turbo-i2v
- https://github.com/hosmelq/falai-php *(PHP client; confirms the three status strings)*
- https://www.mux.com/blog/build-a-generative-video-app-with-fal-ai-and-mux

Narration providers, 23 Sep 2026 — search summaries; fal.ai is egress-blocked:
- https://fal.ai/models/fal-ai/kokoro/french *(blocked; indexed)*
- https://fal.ai/models/fal-ai/kokoro/american-english *(blocked; indexed)*
- https://fal.ai/models/fal-ai/elevenlabs/tts/eleven-v3 *(blocked; indexed)*
- https://fal.ai/explore/text-to-speech-apis *(blocked; indexed)*
- https://fal.ai/learn/tools/best-text-to-speech-apis *(blocked; indexed)*
- https://elevenlabs.io/pricing
- https://elevenlabs.io/blog/weve-lowered-api-agents-pricing-and-introduced-payg
- https://reviewnexa.com/kokoro-tts-review/
- https://texttolab.com/blog/open-source-text-to-speech

Character reference images, 23 Sep 2026 — search summaries; fal.ai is blocked:
- https://fal.ai/models/fal-ai/flux/schnell *(blocked; indexed)*
- https://fal.ai/models/fal-ai/flux/schnell/api *(blocked; indexed)*
- https://fal.ai/docs/model-api-reference/image-generation-api/flux-schnell *(blocked)*
- https://pricepertoken.com/image
- https://nodetool.ai/blog/ai-image-generation-cost

Cameroon payments:
- https://www.businessincameroon.com/finance/2412-15553-mtn-launches-mastercard-backed-virtual-momo-prepaid-card-in-cameroon *(blocked)*
- https://fonyamandpartners.com/comprehensive-legal-and-regulatory-analysis-launch-of-the-mtn-momo-mastercard-prepaid-virtual-card-in-cameroon/
- https://swychr.com/how-to-get-a-free-virtual-card-in-cameroon-using-the-swychr-app/ *(blocked)*
- https://kangcard.com/
- https://www.beonweb.cm/en/blog/paiement-en-ligne-cameroun-mtn-orange-money-2026

Music models, 24 Sep 2026 — search summaries; fal.ai is egress-blocked:
- https://fal.ai/models/fal-ai/stable-audio-3/medium/text-to-audio *(blocked; indexed)*
- https://fal.ai/models/fal-ai/stable-audio-3/medium/text-to-audio/api *(blocked; indexed)*
- https://fal.ai/models/fal-ai/ace-step/api *(blocked; indexed)*
- https://fal.ai/models/fal-ai/ace-step/prompt-to-audio/api *(blocked; indexed)*
- https://fal.ai/models/fal-ai/ace-step/llms.txt *(blocked)*
- https://fal.ai/models/fal-ai/stable-audio/api *(blocked; indexed)*
- https://fal.ai/learn/tools/ai-music-generators *(blocked; indexed)*
- https://stability.ai/news-updates/meet-stable-audio-3-the-model-family-built-for-artistic-experimentation-with-open-weight-models
- https://huggingface.co/stabilityai/stable-audio-3-medium
- https://www.therundown.ai/tools/stable-audio-3-0
- https://blog.dubspot.com/stable-audio-3-review
- https://byteiota.com/stable-audio-3-developer-guide/
- https://docs.comfy.org/tutorials/audio/stable-audio/stable-audio-3

Sound effect models, 24 Sep 2026 — search summaries; fal.ai is egress-blocked:
- https://fal.ai/models/fal-ai/elevenlabs/sound-effects/v2 *(blocked; indexed)*
- https://fal.ai/models/fal-ai/elevenlabs/sound-effects/v2/api *(blocked; indexed)*
- https://fal.ai/models/fal-ai/stable-audio-3/small/sfx/text-to-audio *(blocked; indexed)*
- https://fal.ai/explore/elevenlabs *(blocked; indexed)*
- https://huggingface.co/stabilityai/stable-audio-3-small-sfx
- https://elevenlabs.io/docs/api-reference/text-to-sound-effects/convert
- https://elevenlabs.io/docs/overview/capabilities/sound-effects
- https://unifically.com/blogs/elevenlabs
- https://layer.ai/models/elevenlabs-sound-effects

Live progress approach, 24 Sep 2026 — framework and platform docs, not providers:
- https://laravel.com/docs/13.x/responses *(eventStream / SSE)*
- https://laravel.com/docs/13.x/reverb
- https://laravel.com/framework/docs/broadcasting
- https://www.highperformancelaravel.com/tutorials/series/writing-efficient-applications/caching-responses-with-the-cache-headers-middleware/
- https://hergen.nl/caching-your-laravel-api-with-etag-and-conditional-requests
- https://ma.ttias.be/php-session-locking-prevent-sessions-blocking-in-requests/
- https://kevinchoppin.dev/blog/server-sent-events-in-php
- https://www.php.net/manual/en/function.session-write-close.php
- https://github.com/marmelab/battery-friendly-timer
- https://www.w3.org/2012/10/Qualcomm-paper.pdf *(mobile radio power and polling)*
