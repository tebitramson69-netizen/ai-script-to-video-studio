<?php

namespace App\Services\Media;

use App\Models\Project;
use App\Models\Shot;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use RuntimeException;

/**
 * Assembles the finished .mp4 (FR-19, FR-20, FR-21).
 *
 * Deliberately staged rather than built as one enormous filter_complex:
 *
 *   1. normalise each clip to its exact timeline length and a common format
 *   2. concatenate the normalised clips (stream copy — fast, lossless)
 *   3. build the audio bed: narration at full level, music ducked beneath it
 *   4. mux video + audio into the final file
 *
 * Every stage leaves an inspectable intermediate in a scratch directory, so when
 * a render looks wrong you can play the intermediate and see which stage did it.
 * A single monolithic filter graph is faster to run and far slower to debug.
 */
class VideoAssembler
{
    public function __construct(protected FfmpegRunner $ffmpeg) {}

    /**
     * @return string absolute path to the assembled mp4 (in a temp directory;
     *                the caller stores it and creates the Asset row)
     */
    public function assemble(Project $project): string
    {
        $shots = $project->shots()->with(['asset', 'narrationAsset'])->get()
            ->filter(fn (Shot $s) => $s->isRendered() && $s->asset !== null)
            ->values();

        if ($shots->isEmpty()) {
            throw new RuntimeException('Cannot assemble: the project has no rendered shots.');
        }

        $workDir = $this->makeWorkDir($project);

        try {
            $normalised = $this->normaliseClips($shots, $project, $workDir);
            $silentVideo = $this->concatenate($normalised, $workDir);
            $videoDuration = $this->ffmpeg->durationSeconds($silentVideo);

            $audio = $this->buildAudioBed($project, $workDir, $videoDuration);

            $output = $workDir.'/final.mp4';
            $this->mux($silentVideo, $audio, $output);

            // Move the result out of the scratch directory before it is cleaned up.
            $delivered = tempnam(sys_get_temp_dir(), 'studio_final_').'.mp4';
            File::move($output, $delivered);

            return $delivered;
        } finally {
            File::deleteDirectory($workDir);
        }
    }

    /**
     * FR-18: trim a clip that outruns its narration, hold the final frame when
     * narration outruns the clip.
     *
     * Holding matters: real TTS occasionally runs longer than the estimate that
     * bought the clip, and without a hold the video would end before the
     * sentence does.
     *
     * @param  Collection<int, Shot>  $shots
     * @return list<string>
     */
    protected function normaliseClips($shots, Project $project, string $workDir): array
    {
        [$width, $height] = $project->aspect_ratio->dimensions();
        $paths = [];

        foreach ($shots as $index => $shot) {
            $source = $shot->asset->absolutePath();

            if (! is_file($source)) {
                throw new RuntimeException(
                    "Shot #{$shot->sequence} points at a missing clip file: {$shot->asset->path}"
                );
            }

            $target = round($shot->timelineDurationSeconds(), 3);
            $output = sprintf('%s/clip_%03d.mp4', $workDir, $index);

            $this->ffmpeg->run([
                '-i', $source,

                // tpad clones the last frame if the clip is short; -t then cuts
                // at the exact target. One filter chain covers both directions.
                '-vf', sprintf(
                    'scale=%d:%d:force_original_aspect_ratio=decrease,'.
                    'pad=%d:%d:(ow-iw)/2:(oh-ih)/2,'.
                    'tpad=stop_mode=clone:stop_duration=%.3f,'.
                    'fps=25,format=yuv420p',
                    $width, $height, $width, $height, $target,
                ),
                '-t', (string) $target,

                // FR-14 enforced again at assembly: whatever the model returned,
                // the stitched video carries no audio of its own. The narration
                // track added in buildAudioBed() is the only voice.
                '-an',

                '-c:v', 'libx264',
                '-preset', 'veryfast',
                '-crf', '20',
                '-pix_fmt', 'yuv420p',
                $output,
            ]);

            $paths[] = $output;
        }

        return $paths;
    }

    /**
     * FR-19: stitch the clips in scene order.
     *
     * The concat *demuxer* (not the filter) is used because every clip has
     * already been normalised to identical codec/size/fps, so this is a stream
     * copy — no second re-encode and no generation loss.
     *
     * @param  list<string>  $clips
     */
    protected function concatenate(array $clips, string $workDir): string
    {
        $listFile = $workDir.'/concat.txt';

        File::put($listFile, implode("\n", array_map(
            // Single-quote and escape: a path is data, never shell syntax.
            fn (string $p) => "file '".str_replace("'", "'\\''", $p)."'",
            $clips,
        ))."\n");

        $output = $workDir.'/stitched.mp4';

        $this->ffmpeg->run([
            '-f', 'concat',
            '-safe', '0',
            '-i', $listFile,
            '-c', 'copy',
            $output,
        ]);

        return $output;
    }

    /**
     * FR-20: narration and music on one timeline, music ducked under narration.
     *
     * Ducking uses sidechaincompress keyed on the narration track rather than a
     * fixed volume envelope. It costs nothing extra here and it keeps working in
     * Phase 2/3 when narration stops having gapless coverage — a hard-coded
     * envelope would have to be rewritten then.
     */
    protected function buildAudioBed(Project $project, string $workDir, float $videoDuration): ?string
    {
        $narration = $project->narrationAsset();
        $music = $project->musicAsset();

        if ($narration === null && $music === null) {
            return null;
        }

        $output = $workDir.'/audio.wav';
        $bedGain = $this->dbToLinear((float) config('studio.audio.music_bed_db', -6.0));
        $duckRatio = $this->duckRatio((float) config('studio.audio.music_duck_db', -12.0));

        // Music only.
        if ($narration === null) {
            $this->ffmpeg->run([
                '-stream_loop', '-1', '-i', $music->absolutePath(),
                '-t', (string) round($videoDuration, 3),
                '-af', sprintf('volume=%.4f,afade=t=out:st=%.3f:d=1', $bedGain, max(0.0, $videoDuration - 1)),
                '-ac', '2', '-ar', '44100',
                $output,
            ]);

            return $output;
        }

        // Narration only: pad with silence so the audio spans the whole video.
        if ($music === null) {
            $this->ffmpeg->run([
                '-i', $narration->absolutePath(),
                '-af', 'apad',
                '-t', (string) round($videoDuration, 3),
                '-ac', '2', '-ar', '44100',
                $output,
            ]);

            return $output;
        }

        // Both: duck the music under the narration.
        $filter = sprintf(
            // Narration: to stereo, padded to the full length, and split — one
            // copy is mixed, the other is the sidechain key.
            '[0:a]aformat=channel_layouts=stereo,aresample=44100,apad,atrim=0:%1$.3f,asplit=2[vo][key];'.
            // Music: looped to length, dropped to the bed level.
            '[1:a]aformat=channel_layouts=stereo,aresample=44100,atrim=0:%1$.3f,volume=%2$.4f[bed];'.
            // Duck the bed whenever the key is loud.
            '[bed][key]sidechaincompress=threshold=0.03:ratio=%3$.1f:attack=20:release=400:makeup=1[ducked];'.
            // normalize=0: keep narration at unity instead of halving both.
            '[vo][ducked]amix=inputs=2:duration=first:normalize=0,'.
            'afade=t=out:st=%4$.3f:d=1,alimiter=limit=0.95[out]',
            round($videoDuration, 3),
            $bedGain,
            $duckRatio,
            max(0.0, $videoDuration - 1),
        );

        $this->ffmpeg->run([
            '-i', $narration->absolutePath(),
            '-stream_loop', '-1', '-i', $music->absolutePath(),
            '-filter_complex', $filter,
            '-map', '[out]',
            '-t', (string) round($videoDuration, 3),
            '-ac', '2', '-ar', '44100',
            $output,
        ]);

        return $output;
    }

    /**
     * FR-21: one .mp4, video + mixed audio, web-playable.
     */
    protected function mux(string $video, ?string $audio, string $output): void
    {
        if ($audio === null) {
            $this->ffmpeg->run([
                '-i', $video,
                '-c', 'copy',
                '-movflags', '+faststart',
                $output,
            ]);

            return;
        }

        $this->ffmpeg->run([
            '-i', $video,
            '-i', $audio,
            '-map', '0:v:0',
            '-map', '1:a:0',
            '-c:v', 'copy',
            '-c:a', 'aac',
            '-b:a', '192k',
            '-shortest',

            // faststart moves the index to the front so the file starts playing
            // before it has fully downloaded.
            '-movflags', '+faststart',

            $output,
        ]);
    }

    protected function makeWorkDir(Project $project): string
    {
        $dir = sys_get_temp_dir().'/studio_assembly_'.$project->getKey().'_'.bin2hex(random_bytes(4));
        File::ensureDirectoryExists($dir);

        return $dir;
    }

    protected function dbToLinear(float $db): float
    {
        return round(10 ** ($db / 20), 4);
    }

    /**
     * Turn a desired duck depth in dB into a compressor ratio.
     *
     * Approximate by design: sidechaincompress reduces gain by roughly
     * (1 - 1/ratio) of the amount the signal exceeds the threshold, so there is
     * no exact dB-to-ratio identity. Deeper duck => higher ratio, clamped to the
     * range where the filter still sounds like ducking rather than pumping.
     */
    protected function duckRatio(float $duckDb): float
    {
        $depth = abs($duckDb);

        return max(2.0, min(20.0, round($depth / 1.5, 1)));
    }
}
