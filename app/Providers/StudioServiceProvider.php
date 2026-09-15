<?php

namespace App\Providers;

use App\Contracts\ImageGenerator;
use App\Contracts\MusicGenerator;
use App\Contracts\ScriptStructurer;
use App\Contracts\SoundEffectGenerator;
use App\Contracts\SpeechSynthesizer;
use App\Contracts\VideoGenerator;
use App\Services\Media\FfmpegRunner;
use App\Services\Timing\NarrationEstimator;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;

/**
 * Binds every provider interface to the driver named in config/studio.php.
 *
 * This is the whole of PRD NFR-6 in one file: the pipeline depends on
 * interfaces, this provider decides which concrete class satisfies them, and
 * config decides what this provider picks. Adding Kling means writing one
 * adapter class and adding one line to config — no pipeline code changes.
 */
class StudioServiceProvider extends ServiceProvider
{
    /** @var array<class-string, string> interface => config capability key */
    protected const CAPABILITIES = [
        ScriptStructurer::class => 'script_structurer',
        ImageGenerator::class => 'image_generator',
        VideoGenerator::class => 'video_generator',
        SpeechSynthesizer::class => 'speech_synthesizer',
        MusicGenerator::class => 'music_generator',
        SoundEffectGenerator::class => 'sound_effect_generator',
    ];

    public function register(): void
    {
        $this->app->singleton(FfmpegRunner::class, fn () => FfmpegRunner::fromConfig());
        $this->app->singleton(NarrationEstimator::class, fn () => NarrationEstimator::fromConfig());

        foreach (self::CAPABILITIES as $interface => $capability) {
            $this->app->singleton(
                $interface,
                fn ($app) => $app->make($this->resolveDriverClass($capability)),
            );
        }
    }

    /**
     * @param  string  $capability  e.g. 'video_generator'
     * @return class-string
     */
    protected function resolveDriverClass(string $capability): string
    {
        $driver = config("studio.{$capability}");
        $class = config("studio.drivers.{$capability}.{$driver}");

        if (! is_string($class) || ! class_exists($class)) {
            $available = implode(', ', array_keys((array) config("studio.drivers.{$capability}", [])));

            throw new InvalidArgumentException(
                "No driver registered for studio.{$capability} = '{$driver}'. ".
                "Available drivers: {$available}. Check config/studio.php."
            );
        }

        return $class;
    }
}
