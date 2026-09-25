<?php

namespace App\Services\Media;

use RuntimeException;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;

/**
 * Thin wrapper around the ffmpeg/ffprobe binaries.
 *
 * Deliberately not a fluent filter-graph builder: the pipeline needs exactly
 * three operations (probe, render, assemble) and a hand-rolled DSL would be more
 * code to maintain than the argument arrays it replaces.
 *
 * Every call passes arguments as an array, never a shell string, so filenames
 * and user-derived text cannot break out into the shell.
 */
class FfmpegRunner
{
    public function __construct(
        protected string $binary,
        protected string $probeBinary,
        protected int $timeoutSeconds = 900,
    ) {}

    public static function fromConfig(): self
    {
        return new self(
            config('studio.ffmpeg.binary', 'ffmpeg'),
            config('studio.ffmpeg.probe_binary', 'ffprobe'),
            (int) config('studio.ffmpeg.timeout_seconds', 900),
        );
    }

    /**
     * @param  list<string>  $arguments  ffmpeg arguments, excluding the binary itself
     */
    public function run(array $arguments): string
    {
        // -nostdin: without it ffmpeg can consume the worker's stdin and hang
        // a queue worker indefinitely.
        $process = new Process([$this->binary, '-hide_banner', '-nostdin', '-y', ...$arguments]);
        $process->setTimeout($this->timeoutSeconds);

        try {
            $process->mustRun();
        } catch (ProcessFailedException $e) {
            throw new RuntimeException(
                'ffmpeg failed: '.$this->tail($process->getErrorOutput()),
                previous: $e,
            );
        }

        return $process->getOutput();
    }

    /**
     * Duration of a media file in seconds.
     *
     * This is how the real narration length reaches the timeline (FR-16/FR-18) —
     * the words-per-minute figure is only ever a planning estimate.
     */
    public function durationSeconds(string $path): float
    {
        if (! is_file($path)) {
            throw new RuntimeException("Cannot probe missing file: {$path}");
        }

        $process = new Process([
            $this->probeBinary, '-v', 'error',
            '-show_entries', 'format=duration',
            '-of', 'default=noprint_wrappers=1:nokey=1',
            $path,
        ]);
        $process->setTimeout(60);

        try {
            $process->mustRun();
        } catch (ProcessFailedException $e) {
            throw new RuntimeException(
                'ffprobe failed: '.$this->tail($process->getErrorOutput()),
                previous: $e,
            );
        }

        $duration = (float) trim($process->getOutput());

        if ($duration <= 0.0) {
            throw new RuntimeException("ffprobe reported a non-positive duration for {$path}");
        }

        return $duration;
    }

    /**
     * Mean volume of an audio file in dBFS, or null if it cannot be measured.
     *
     * The mix needs this because `music_bed_db` and `sfx_bed_db` are documented
     * as levels RELATIVE TO NARRATION, and that is only what they mean if every
     * stem's own loudness is known. Providers do not agree on output level —
     * a TTS service may return -16 dBFS and a music model -39 — so applying a
     * fixed attenuation to whatever arrived silently turns "6 dB under the
     * voice" into "6 dB under something else entirely".
     *
     * Null rather than an exception: a stem that cannot be measured should
     * still be mixed at its unadjusted level, because a video with an
     * imperfect balance beats no video at all.
     */
    public function meanVolumeDb(string $path): ?float
    {
        if (! is_file($path)) {
            return null;
        }

        $process = new Process([
            $this->binary, '-hide_banner', '-nostdin',
            '-i', $path,
            '-af', 'volumedetect',
            '-f', 'null', '-',
        ]);
        $process->setTimeout(120);
        $process->run();

        // volumedetect reports on stderr, and reports nothing useful for a
        // silent file (-inf), which must not be mistaken for a quiet one.
        if (! preg_match('/mean_volume:\s*(-?\d+(?:\.\d+)?) dB/', $process->getErrorOutput(), $match)) {
            return null;
        }

        return (float) $match[1];
    }

    public function isAvailable(): bool
    {
        $process = new Process([$this->binary, '-version']);
        $process->setTimeout(10);
        $process->run();

        return $process->isSuccessful();
    }

    /**
     * ffmpeg writes a lot to stderr. Keep the tail, which is where the actual
     * error is, rather than flooding the log with the banner and stream dump.
     */
    protected function tail(string $output, int $lines = 12): string
    {
        $split = array_filter(explode("\n", trim($output)));

        return implode("\n", array_slice($split, -$lines));
    }
}
