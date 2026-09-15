<?php

use App\Integrations\Fake\FakeImageGenerator;
use App\Integrations\Fake\FakeMusicGenerator;
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

    'video_models' => [
        'fake' => [
            'label' => 'Fake renderer (local, free)',
            'clip_lengths' => [5, 8, 10],
            'cost_per_second_usd' => 0.0,
            'emits_native_audio' => false,
        ],
    ],

    'default_video_model' => env('STUDIO_VIDEO_MODEL', 'fake'),

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

    'ffmpeg' => [
        'binary' => env('FFMPEG_BINARY', 'ffmpeg'),
        'probe_binary' => env('FFPROBE_BINARY', 'ffprobe'),
        'timeout_seconds' => (int) env('FFMPEG_TIMEOUT', 900),
    ],
];
