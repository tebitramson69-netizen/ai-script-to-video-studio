# Adding a real provider

Every external capability is an interface in `app/Contracts/`. Going live means
writing one adapter class per capability and changing one line of config.
No pipeline code changes — that is the whole point of PRD NFR-6.

**The video capability is already done for fal.** `app/Integrations/Fal/` holds
a working adapter — read it as the worked example rather than starting from the
sketch below. Its unverified parts are confined to `FalResponseMapper` and
explained in `docs/PROVIDER-RESEARCH.md` §8.

Speech, music and sound effects still have no real adapter. Nothing here
contains a speculative HTTP client for ElevenLabs or Replicate: their request
and response shapes change, and a confidently-wrong payload is harder to debug
than an unwritten one. **Check the provider's current docs, then implement
against the interface below.**

---

## 1. Write the adapter

Example shape for the video capability, written against an imaginary provider
so it does not collide with the real `App\Integrations\Fal` classes. The
interface is the contract; the HTTP detail is yours to fill from the provider's
docs.

```php
namespace App\Integrations\Acme;

use App\Contracts\Data\{ClipRequest, GeneratedMedia};
use App\Contracts\{ProviderException, VideoGenerator};
use Illuminate\Support\Facades\Http;

class AcmeVideoGenerator implements VideoGenerator
{
    public function generateClip(ClipRequest $request): GeneratedMedia
    {
        $response = Http::withToken(config('studio.acme.key'))
            ->timeout(600)
            // Retry here is for connection-level blips only; the queue's
            // backoff (StudioJob) handles the slow, real failures.
            ->retry(2, 2000)
            ->post('<endpoint from the provider docs>', [
                // Map ClipRequest onto the provider's parameters:
                //   $request->prompt
                //   $request->durationSeconds
                //   $request->aspectRatio->value
                //   $request->referenceImagePath   <- upload for image-to-video
                //   $request->seed
            ]);

        if ($response->status() === 429 || $response->serverError()) {
            // Retryable: the queue will back off and try again.
            throw ProviderException::retryable("acme: {$response->status()}", 'acme');
        }

        if ($response->failed()) {
            // Permanent: a rejected prompt fails identically four times, and on
            // a real provider each attempt may still be billed.
            throw ProviderException::permanent("acme: {$response->body()}", 'acme');
        }

        // Download the clip to a LOCAL TEMP PATH and return that path.
        // Do not write to storage and do not touch the database — AssetRecorder
        // owns both, which is what keeps drivers swappable and testable.
        $path = tempnam(sys_get_temp_dir(), 'acme_').'.mp4';
        file_put_contents($path, Http::get($response->json('video.url'))->body());

        // FR-14: if the model emits its own audio, strip it here (or set
        // emitsNativeAudio() and let the assembler handle it). A narrated
        // project must carry exactly one voice.

        return new GeneratedMedia(
            path: $path,
            mime: 'video/mp4',
            model: $this->modelName(),
            costUsd: $request->durationSeconds * $this->costPerSecondUsd(),
            durationSeconds: $request->durationSeconds,
            meta: ['seed' => $request->seed, 'job_id' => $response->json('request_id')],
        );
    }

    /**
     * CRITICAL: these must be the clip lengths the model really renders.
     * ShotPlanner rounds every scene up to one of these values (FR-16) and
     * splits scenes that exceed the longest (FR-17). Wrong values here produce
     * clips that truncate narration.
     */
    public function supportedClipLengths(): array
    {
        return [5.0, 10.0];
    }

    public function costPerSecondUsd(): float { return 0.10; }
    public function emitsNativeAudio(): bool  { return false; }
    public function modelName(): string       { return 'kling-3.0'; }
    public function providerName(): string    { return 'acme'; }
}
```

## 2. Register it

`config/studio.php`:

```php
'drivers' => [
    'video_generator' => [
        'fake' => \App\Integrations\Fake\FakeVideoGenerator::class,
        'fal'  => \App\Integrations\Fal\FalVideoGenerator::class,   // already there
        'acme' => \App\Integrations\Acme\AcmeVideoGenerator::class,  // add
    ],
],

'video_models' => [
    'kling-3.0' => [
        'label' => 'Kling 3.0',
        'clip_lengths' => [5, 10],
        'cost_per_second_usd' => 0.10,
        'emits_native_audio' => false,
    ],
],
```

Provider credentials live in `config/studio.php`, not `config/services.php` —
everything this project needs from a provider is in one file:

```php
'acme' => ['key' => env('ACME_KEY')],
```

## 3. Switch it on

```dotenv
STUDIO_VIDEO_DRIVER=fal
FAL_KEY=your-key-here
```

Keys live in `.env` only. They must never reach the browser (§14) — every
provider call is made server-side from a queued job.

---

## The six interfaces

| Interface | Used for | Must get right |
|---|---|---|
| `ScriptStructurer` | script → scenes + cast | Pass the script as **data** in a delimited block, never concatenated into instructions |
| `ImageGenerator` | character reference candidates | Deterministic given a seed, so a reference can be reproduced |
| `VideoGenerator` | one clip per shot | `supportedClipLengths()` must be truthful — the timing engine depends on it |
| `SpeechSynthesizer` | narration | Return the **measured** duration, not the requested one — it is the master clock |
| `MusicGenerator` | one background track | Must cover the requested duration |
| `SoundEffectGenerator` | per-scene SFX (Phase 2) | Not yet on the assembly timeline |

## Rules every adapter must follow

1. **Return a temp path.** Never write to storage, never create an Asset row.
   `AssetRecorder` does both, and is the reason cost logging cannot be forgotten.
2. **Throw `ProviderException`**, classified `retryable` or `permanent`. The
   queue's backoff depends on that distinction; retrying a permanent failure
   burns money.
3. **Report honest cost** in `GeneratedMedia::costUsd`. It feeds the budget cap.
4. **Report honest duration.** Probe the returned file rather than echoing what
   you asked for — providers do not always give you exactly what you requested.
5. **Mute native audio** for narrated projects (FR-14), or declare
   `emitsNativeAudio()` truthfully so the pipeline strips it.

## Testing an adapter

Keep `fake` as the default. `php artisan test` must keep passing with zero
network access and zero spend — that is what makes the suite runnable while
provider billing is still unresolved (A2).

To try a real provider without risking a large bill, set a small budget cap on a
one-scene project. `CostEstimator` refuses the run before anything is queued.
