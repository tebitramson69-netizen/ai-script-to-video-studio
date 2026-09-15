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
