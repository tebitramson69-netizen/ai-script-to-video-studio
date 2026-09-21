# fal adapter implementation plan

Supersedes the "Next steps" section of `BUILD-PLAN.md` for provider work.
Decision of 21 Sep 2026: **fal.ai primary, Replicate the reversible fallback**,
and the architectural work proceeds now rather than waiting on payment.

---

## Where this plan differs from the brief, and why

Four changes. Each is a judgement call, so the reasoning is here to be argued
with rather than buried.

### 1. Veo 3.1 **Fast** as the default, Standard as a per-shot upgrade

At the documented $0.20/sec without audio, the PRD's 64-second reference video
costs **$12.80** — 88% of our default $15 cap on a *clean* run, leaving room for
about one regenerated shot.

| Model | $/sec | 64s clip | + side costs | % of $15 cap | regens left |
|---|---|---|---|---|---|
| Veo 3.1 Standard, no audio | 0.20 | 12.80 | 13.20 | 88% | 1 |
| Veo 3.1 Standard, with audio | 0.40 | 25.60 | 26.00 | 173% | — |
| Veo 3.1 Fast, no audio *(rate unverified)* | ~0.10 | 6.40 | 6.80 | 45% | 10 |
| Kling 3.0 *(corroborated)* | 0.07 | 4.48 | 4.88 | 33% | 18 |

The review-and-regenerate loop is a deliberate product feature, not an accident
— PRD NG1 says so explicitly ("not a one-click magic button; human checkpoints
are intentional"), and FR-10 builds the UI for it. A default model that can
afford one regeneration per video breaks the thing the product is *for*.

The PRD's own §10.1 table also puts Kling 3.0 as "Primary/default" and Veo 3.1
as "Quality/hero shots". Making Standard the default silently inverts that.

**So:** `default_video_model` stays on the cheaper option; Veo 3.1 Standard is
selectable per project via the existing `projects.video_model` column, for hero
work. `ModelCapabilitiesTest::test_the_default_model_leaves_room_to_regenerate`
fails the build if a default is chosen that cannot afford the loop.

This is reversible and cheap — but it should be a decision, not a side effect.

### 2. Keep the six focused interfaces; do not merge them into one `MediaProvider`

The brief proposes one interface carrying `generateImage()`, `generateVideo()`,
`generateSpeech()`, `generateMusic()` and so on. I think that is a regression
from what is already built, for three reasons:

- **It forces stubs.** A video-only provider (a direct Kling integration, say)
  would have to implement `generateSpeech()` and throw. Six focused interfaces
  let a provider implement exactly what it does.
- **It prevents mixing.** Today each capability binds independently in
  `config/studio.php`, so video can run on fal while TTS runs on ElevenLabs
  direct. That is a live possibility — the research flags fal's ElevenLabs
  endpoint as possibly limiting. A fat interface makes that an all-or-nothing
  switch.
- **It fights the brief's own §12.** The capability matrix exists precisely
  because models differ in schema and billing basis. One interface asserts
  uniformity that the matrix denies.

The brief's underlying instinct is right though: the *plumbing* — auth, queue
submission, status polling, result retrieval, error mapping — genuinely is
shared and must not be written five times.

**So:** keep `ScriptStructurer`, `ImageGenerator`, `VideoGenerator`,
`SpeechSynthesizer`, `MusicGenerator`, `SoundEffectGenerator` as they are, and
put the shared machinery in **one `FalClient`** that every `Fal*` adapter
depends on. Shared plumbing, independent swappability — both, rather than a
trade between them.

### 3. Idempotency by unique index, not by lookup

The brief says: *calculate fingerprint → check for existing generation → submit
only if required.* That is check-then-act. Two queue workers, or one owner
double-clicking, can both pass the check before either inserts, and both then
submit a billable job. The window is small and it will eventually be hit,
because that is what races do.

**So:** `provider_requests.fingerprint` carries a **UNIQUE index**, and
`GenerationLedger::claim()` inserts and catches the constraint violation. The
loser of the race reuses the winner's row. The database refusing the second
insert is the only version of this that holds under concurrency, and every
duplicate here is money.

Related, and fixed while building it: `regenerateShot()` now always assigns a
fresh seed. Re-rolling the same seed through the same model returns the same
clip, so an unedited "regenerate" was paying to reproduce the shot the owner had
just rejected — and it would also have collided with its own fingerprint.

### 4. Polling first, webhooks second

The brief puts webhooks ahead of polling. A webhook endpoint that marks
generations complete is an endpoint that mutates billing state on an unauthen-
ticated POST, and **fal's signature scheme is not something I can verify** —
fal.ai is blocked from this environment. Inventing a verification scheme is
worse than having none, because it looks like security.

**So:** polling is the primary completion path — fully verifiable, no shared
secret, works today. The webhook route ships with signature verification that
**rejects everything until configured**, and is enabled only once the real
scheme is confirmed in the dashboard (Tier C item 9). Polling then stays as the
reconciliation backstop, which it should be regardless: webhooks get lost.

---

## Two constraints the brief did not mention

**Image-to-video needs a publicly fetchable image URL.** Veo's image-to-video
takes a URL, and our character references live on the **private** disk, outside
the webroot, deliberately (§14). So the adapter needs an upload step — fal's own
file storage, most likely — before it can condition a clip. Making our
references publicly reachable to satisfy this would undo a security property we
chose on purpose. This is a real piece of Step 8 work, not a detail.

**Veo does not support 1:1.** Aspect ratios are 16:9 and 9:16 only, but our
project-creation form still offers square (FR-2). A square project would fail at
render time, after money is spent. The capability data is now in the registry;
the validation using it belongs at project creation, so the owner learns it
before choosing.

---

## Sequence

Steps 1–5 are done. They are all provider-agnostic — none of them required
fal's HTTP details, which is why they could be built now.

| Step | State |
|---|---|
| **1. Provider contracts and capability model** | **Done.** `GenerationMode`, `VideoResolution`, `ModelCapabilities`, config-driven registry, extended `ClipRequest`, `VideoGenerator::capabilities()` |
| **2. Error taxonomy** | **Done.** `ProviderFailureReason` with retryable/owner-actionable classification, incl. `ContentRejected` |
| **3. Audio-aware cost estimation** | **Done.** `estimateCostUsd(ClipRequest)`; resolution and audio both move the rate |
| **4. Request ledger and idempotency** | **Done.** `provider_requests` + `GenerationLedger` with the unique-index claim |
| **5. Regeneration correctness** | **Done.** Fresh seed on every regeneration |
| **6. Capability validation** | **Done.** `ModelRegistry`; unsupported aspect ratios refused at project creation and before planning, not at render time |
| **7. Async submit/collect lifecycle** | **Done.** `QueueableVideoGenerator`, `ShotSubmitter`, `GenerationCompleter`; `RenderShotJob` takes the async path when the driver queues |
| **8. Polling** | **Done.** `ReconcileProviderRequestsJob`, scheduled every minute with `withoutOverlapping` |
| **9. `FalClient`** | **Next — blocked on Tier C.** Auth, submit, status, result, error mapping |
| **10. `FalVideoGenerator`** | Implements `QueueableVideoGenerator`; text-to-video first, forwarding `generate_audio=false` |
| **10a. Asset ingestion from URL** | Download provider output into our storage. Partly done: the completer already stores whatever the adapter returns; the HTTP download itself belongs in `FalClient` |
| **11. Webhooks** | Only once the signature scheme is confirmed |
| **12. Image-to-video** | Including the reference-upload step above |
| **13. TTS and music** | After the core lifecycle is stable |
| **14. Replicate fallback** | Same interfaces, no duplicated business logic |

## Why the lifecycle was built before the client

The async machinery is provider-agnostic, so waiting for fal would have been
waiting for nothing. It is also the part most likely to be got wrong under
pressure, and hardest to test against a live provider — a worker dying between
submission and collection is a two-line test against a fake and an expensive
accident against fal.

It is exercised by `FakeQueueableVideoGenerator`, the same local renderer behind
a simulated queue that makes the caller wait a configurable number of polls. A
fake that completed instantly would leave in-queue, in-progress and
worker-restart untested — and those are exactly the states that lose track of
work a provider is already charging for.

`AsyncGenerationLifecycleTest` covers: submission returning without waiting, the
pipeline refusing to complete early, collection into our own storage, cost
reconciliation, double-submission being refused by the claim index, a repeated
completion being idempotent (a webhook and a poll racing is normal), a worker
restart between submit and collect losing nothing, and the reconciler correctly
doing nothing for a synchronous driver.

When `FalVideoGenerator` lands it implements three methods — `submitClip`,
`checkStatus`, `fetchResult` — and inherits all of the above.

## What Step 9 needs from you

Four facts from the dashboard. Three go straight into `config/studio.php`:

1. **The Veo 3.1 Fast per-second rate.** Currently set to the Standard rate as a
   deliberate over-estimate. Highest-leverage number in the system.
2. **Exact model ids** as the dashboard shows them.
3. **The free-credit balance**, and whether it is usable on Veo specifically.
4. **One real queue response** — submit anything and paste back the JSON for
   submit, status and result. That is what lets me write the client against the
   real shape instead of guessing, which is the line I have been holding since
   `PROVIDERS.md` was written.

With those, Steps 6–10 are mechanical.
