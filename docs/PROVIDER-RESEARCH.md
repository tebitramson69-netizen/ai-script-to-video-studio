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

   Note also: the entry declares **image-to-video only**. Shots with no locked
   character cannot render on it, which `ModelRegistry::degradationWarnings()`
   now counts and reports on the project page before anything is spent. Pinning
   a whole project to it is therefore only right when every shot has a locked
   character. Per-shot model selection — text-to-video for uncharactered shots,
   image-to-video for the rest — is the real answer and is not built.
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

Cameroon payments:
- https://www.businessincameroon.com/finance/2412-15553-mtn-launches-mastercard-backed-virtual-momo-prepaid-card-in-cameroon *(blocked)*
- https://fonyamandpartners.com/comprehensive-legal-and-regulatory-analysis-launch-of-the-mtn-momo-mastercard-prepaid-virtual-card-in-cameroon/
- https://swychr.com/how-to-get-a-free-virtual-card-in-cameroon-using-the-swychr-app/ *(blocked)*
- https://kangcard.com/
- https://www.beonweb.cm/en/blog/paiement-en-ligne-cameroun-mtn-orange-money-2026
