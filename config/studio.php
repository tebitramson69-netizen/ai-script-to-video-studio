<?php

use App\Integrations\Fake\FakeImageGenerator;
use App\Integrations\Fake\FakeMusicGenerator;
use App\Integrations\Fake\FakeQueueableVideoGenerator;
use App\Integrations\Fake\FakeScriptStructurer;
use App\Integrations\Fake\FakeSoundEffectGenerator;
use App\Integrations\Fake\FakeSpeechSynthesizer;
use App\Integrations\Fake\FakeVideoGenerator;

/**
 * Every third-party capability the pipeline needs is named here and resolved
 * through an interface (PRD §8 design principle, NFR-6). Swapping Kling for Veo,
 * or fal.ai for Replicate, is a change to this file plus one adapter class —
 * never a change to the pipeline.
 */
return [

    /*
    |--------------------------------------------------------------------------
    | Active drivers
    |--------------------------------------------------------------------------
    |
    | Each capability picks one driver from the `drivers` map below. `fake` is
    | the default everywhere: it produces real, playable files locally at zero
    | cost, so the whole pipeline runs end-to-end without provider access
    | (PRD A1/A2 are not yet resolved).
    |
    */

    'script_structurer' => env('STUDIO_SCRIPT_DRIVER', 'fake'),
    'image_generator' => env('STUDIO_IMAGE_DRIVER', 'fake'),
    'video_generator' => env('STUDIO_VIDEO_DRIVER', 'fake'),
    'speech_synthesizer' => env('STUDIO_SPEECH_DRIVER', 'fake'),
    'music_generator' => env('STUDIO_MUSIC_DRIVER', 'fake'),
    'sound_effect_generator' => env('STUDIO_SFX_DRIVER', 'fake'),

    'drivers' => [
        'script_structurer' => [
            'fake' => FakeScriptStructurer::class,
        ],
        'image_generator' => [
            'fake' => FakeImageGenerator::class,
        ],
        'video_generator' => [
            'fake' => FakeVideoGenerator::class,

            // The same local renderer behind a simulated queue, so the
            // submit -> poll -> collect lifecycle is exercised without a
            // provider account.
            'fake-queue' => FakeQueueableVideoGenerator::class,
        ],
        'speech_synthesizer' => [
            'fake' => FakeSpeechSynthesizer::class,
        ],
        'music_generator' => [
            'fake' => FakeMusicGenerator::class,
        ],
        'sound_effect_generator' => [
            'fake' => FakeSoundEffectGenerator::class,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Video models
    |--------------------------------------------------------------------------
    |
    | Model capabilities the timing engine and cost estimator need to know about.
    | `clip_lengths` is the set of clip durations the model will actually render;
    | the timing engine rounds a scene's required length UP to one of these
    | (FR-16) and splits the scene when narration exceeds the longest (FR-17).
    |
    | Prices are indicative (PRD §10, Sept 2026) and move monthly. They drive the
    | pre-run estimate only — actual cost is recorded per generation from the
    | provider response.
    |
    */

    /*
    |--------------------------------------------------------------------------
    | Video model registry
    |--------------------------------------------------------------------------
    |
    | One entry per model the studio can render with. Nothing in the business
    | logic reads a model name or a price directly — everything goes through
    | ModelCapabilities::fromConfig(), so adding or repricing a model is an edit
    | here and nowhere else (NFR-6).
    |
    | `pricing` is per second of OUTPUT, split by resolution and by whether the
    | model is asked to generate its own audio. That split is not cosmetic: on
    | Veo 3.1 turning audio off halves the 720p/1080p rate, and every narrated
    | project turns it off (FR-14).
    |
    | Model keys must not contain dots: config() resolves paths by dot notation,
    | so "veo-3.1" would be read as ["veo-3"]["1"] and silently return null.
    |
    | VERIFY_IN_DASHBOARD marks a value not confirmed against a live fal account.
    | Where a price is unverified it is deliberately set to the HIGHER known
    | rate: over-estimating makes the budget cap refuse a run, while
    | under-estimating lets it overspend. Err toward refusing.
    |
    */

    'video_models' => [

        'fake' => [
            'label' => 'Fake renderer (local, free)',
            'endpoint' => null,
            'modes' => ['text_to_video', 'image_to_video'],
            'clip_lengths' => [5, 8, 10],
            'resolutions' => ['720p', '1080p'],
            'aspect_ratios' => ['16:9', '9:16', '1:1'],
            'default_resolution' => '720p',
            'supports_native_audio_toggle' => true,
            'emits_native_audio' => false,
            'cost_per_second_usd' => 0.0,
        ],

        // Pricing and limits below were read from fal's own model pages by the
        // project owner on 21 Sep 2026. They are owner-verified, not verified by
        // this codebase — re-check before relying on them (PRD §10).
        // Owner-verified against a live fal.ai account on 22 Sep 2026, read from
        // the model page itself. This is the most reliable evidence in the
        // project — it outranks every third-party figure in PROVIDER-RESEARCH.
        'kling-2-5-turbo-pro' => [
            'label' => 'Kling 2.5 Turbo Pro (fal)',
            'endpoint' => env(
                'STUDIO_KLING_ENDPOINT',
                'fal-ai/kling-video/v2.5-turbo/pro/text-to-video',
            ),

            // Owner-verified: this endpoint is text-to-video ONLY. It cannot take
            // a locked character reference as a starting frame, so FR-6 character
            // consistency is not achievable on it — see ModelRegistry's
            // degradation warnings. Kling's image-to-video endpoint is a separate
            // model id and needs its own registry entry.
            'modes' => ['text_to_video'],

            // Owner-verified: 5 or 10 seconds only. NOT a 5-10 range — the
            // timing engine must never ask for 6, 7, 8 or 9.
            'clip_lengths' => [5, 10],

            // VERIFY_IN_DASHBOARD: accepted resolutions were not confirmed.
            // Pricing is flat per second, so this does not affect any estimate.
            'resolutions' => ['720p', '1080p'],
            'default_resolution' => '1080p',

            // VERIFY_IN_DASHBOARD: only 16:9 and 9:16 are declared because they
            // were not confirmed either, and an undeclared ratio is refused at
            // project creation rather than failing after a paid render. Add 1:1
            // if the model page lists it.
            'aspect_ratios' => ['16:9', '9:16'],

            // Owner-verified: the schema exposes no generate_audio or equivalent.
            // Treat as video-only. The adapter must not send an audio parameter.
            'supports_native_audio_toggle' => false,
            'emits_native_audio' => false,

            // Owner-verified: $0.35 for 5 seconds, and $0.07 for each additional
            // second — a flat $0.07/s with no resolution or audio dimension, so
            // the flat form is the honest one here.
            'cost_per_second_usd' => 0.07,
        ],

        'veo-3-1-fast' => [
            'label' => 'Veo 3.1 Fast (fal)',
            'endpoint' => env('STUDIO_VEO_FAST_ENDPOINT', 'fal-ai/veo3.1/fast'),
            'modes' => ['text_to_video', 'image_to_video'],

            // Owner-verified: 5-8 second clips. Modelled as whole seconds so the
            // timing engine can round up to any of them (FR-16).
            'clip_lengths' => [5, 6, 7, 8],

            'resolutions' => ['720p', '1080p'],

            // Owner-verified: 16:9 and 9:16 only. 1:1 is NOT available, so a
            // square project cannot render on this model.
            'aspect_ratios' => ['16:9', '9:16'],

            'default_resolution' => '720p',
            'supports_native_audio_toggle' => true,
            'emits_native_audio' => true,

            // VERIFY_IN_DASHBOARD: the Fast variant's rate was not supplied.
            // Set to the Standard rate on purpose — see the over-estimate rule
            // above. Correct this the moment the real figure is known; it is
            // the single number with the most leverage over cost in the system.
            'pricing' => [
                '720p' => ['no_audio' => 0.20, 'audio' => 0.40],
                '1080p' => ['no_audio' => 0.20, 'audio' => 0.40],
            ],
        ],

        'veo-3-1' => [
            'label' => 'Veo 3.1 Standard (fal)',
            'endpoint' => env('STUDIO_VEO_ENDPOINT', 'fal-ai/veo3.1'),
            'modes' => ['text_to_video', 'image_to_video'],
            'clip_lengths' => [5, 6, 7, 8],
            'resolutions' => ['720p', '1080p', '4k'],
            'aspect_ratios' => ['16:9', '9:16'],
            'default_resolution' => '720p',
            'supports_native_audio_toggle' => true,
            'emits_native_audio' => true,

            // Owner-verified from fal's Veo 3.1 model page, 21 Sep 2026.
            'pricing' => [
                '720p' => ['no_audio' => 0.20, 'audio' => 0.40],
                '1080p' => ['no_audio' => 0.20, 'audio' => 0.40],
                '4k' => ['no_audio' => 0.40, 'audio' => 0.60],
            ],
        ],
    ],

    'default_video_model' => env('STUDIO_VIDEO_MODEL', 'fake'),

    /*
    |--------------------------------------------------------------------------
    | Fake driver pricing
    |--------------------------------------------------------------------------
    |
    | The local fake drivers really are free, so these default to zero. They are
    | configurable so that budget and cost behaviour can be exercised in tests —
    | and so you can dry-run a project's economics against a provider's published
    | rates before committing to that provider.
    |
    */

    /*
    |--------------------------------------------------------------------------
    | Fake queue behaviour
    |--------------------------------------------------------------------------
    |
    | How many status checks the fake queueing driver makes a caller wait before
    | reporting completion. Zero completes on the first poll; higher values let
    | a test assert the pipeline genuinely waits rather than quietly blocking.
    |
    */

    'fake_queue' => [
        'polls_before_complete' => (int) env('STUDIO_FAKE_QUEUE_POLLS', 0),
    ],

    'fake_costs' => [
        'image_usd' => (float) env('STUDIO_FAKE_IMAGE_COST', 0.0),
        'tts_per_1k_chars_usd' => (float) env('STUDIO_FAKE_TTS_COST', 0.0),
        'music_per_minute_usd' => (float) env('STUDIO_FAKE_MUSIC_COST', 0.0),
        'sfx_per_effect_usd' => (float) env('STUDIO_FAKE_SFX_COST', 0.0),
    ],

    /*
    |--------------------------------------------------------------------------
    | Audio
    |--------------------------------------------------------------------------
    */

    'audio' => [
        // Narration pacing used to estimate duration before synthesis (FR-16).
        // Real synthesised duration replaces this estimate once the TTS call
        // returns; the estimate exists so the owner sees a cost and a length
        // before spending anything.
        'words_per_minute' => (int) env('STUDIO_NARRATION_WPM', 150),

        // Music sits this many dB below narration while narration plays (FR-20).
        'music_duck_db' => (float) env('STUDIO_MUSIC_DUCK_DB', -12.0),

        // Steady-state music level relative to narration, in dB.
        'music_bed_db' => (float) env('STUDIO_MUSIC_BED_DB', -6.0),

        'tts_cost_per_1k_chars_usd' => (float) env('STUDIO_TTS_COST_PER_1K', 0.08),
        'music_cost_per_minute_usd' => (float) env('STUDIO_MUSIC_COST_PER_MIN', 0.30),
        'sfx_cost_per_effect_usd' => (float) env('STUDIO_SFX_COST_PER_EFFECT', 0.02),
    ],

    'image' => [
        'cost_per_image_usd' => (float) env('STUDIO_IMAGE_COST', 0.07),

        // How many candidates to generate per character for the owner to
        // choose from before locking one (FR-5).
        'candidates_per_character' => (int) env('STUDIO_IMAGE_CANDIDATES', 3),
    ],

    /*
    |--------------------------------------------------------------------------
    | Budget (FR-11, NFR-4)
    |--------------------------------------------------------------------------
    |
    | A hard cap, enforced server-side before any run is dispatched. The PRD's
    | own worked example lands at ~$7 for a clean 60s run and $10–14 with
    | regenerations, so the default cap is set above that.
    |
    */

    'budget' => [
        'default_cap_usd' => (float) env('STUDIO_DEFAULT_BUDGET_CAP', 15.00),
        'max_cap_usd' => (float) env('STUDIO_MAX_BUDGET_CAP', 100.00),
    ],

    /*
    |--------------------------------------------------------------------------
    | Project limits
    |--------------------------------------------------------------------------
    */

    'limits' => [
        'max_scenes' => (int) env('STUDIO_MAX_SCENES', 40),
        'max_shots' => (int) env('STUDIO_MAX_SHOTS', 60),
        'max_script_characters' => (int) env('STUDIO_MAX_SCRIPT_CHARS', 20000),
        'max_upload_kilobytes' => (int) env('STUDIO_MAX_UPLOAD_KB', 512),
    ],

    /*
    |--------------------------------------------------------------------------
    | Storage
    |--------------------------------------------------------------------------
    */

    'disk' => env('STUDIO_DISK', 'local'),

    /*
    |--------------------------------------------------------------------------
    | fal endpoints
    |--------------------------------------------------------------------------
    |
    | Used only by `studio:capture-fal-shapes`, the diagnostic that discovers
    | fal's real request and response shapes. Nothing in the pipeline calls
    | these yet.
    |
    | UNVERIFIED: these follow fal's documented queue pattern but this codebase
    | has never reached fal.ai — it is blocked by the build environment's egress
    | policy. If the capture 404s, the correct URLs are on the model page's API
    | tab; override them here or in .env rather than guessing again.
    |
    */

    'fal' => [
        'key' => env('FAL_KEY'),
        'queue_url' => env('FAL_QUEUE_URL', 'https://queue.fal.run'),
    ],

    'ffmpeg' => [
        'binary' => env('FFMPEG_BINARY', 'ffmpeg'),
        'probe_binary' => env('FFPROBE_BINARY', 'ffprobe'),
        'timeout_seconds' => (int) env('FFMPEG_TIMEOUT', 900),
    ],
];
