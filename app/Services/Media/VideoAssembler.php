<?php

namespace App\Services\Media;

use App\Enums\AssetType;
use App\Models\Asset;
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

            $audio = $this->buildAudioBed($project, $shots, $workDir, $videoDuration);

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
     * FR-20: narration, music and per-scene sound effects on one timeline, with
     * everything ducked under the narration.
     *
     * Built compositionally rather than as a branch per combination. The earlier
     * version had three explicit paths (music only, narration only, both); adding
     * effects would have made it eight, and the eighth would have been the one
     * nobody tested. Instead each source contributes one filter chain and one
     * label, the labels are mixed, and the mix is ducked if there is a narration
     * to duck against. Two sources or ten, it is the same code.
     *
     * Ducking still uses sidechaincompress keyed on the narration rather than a
     * fixed volume envelope: it costs nothing extra and it keeps working when
     * narration stops having gapless coverage.
     *
     * @param  Collection<int, Shot>  $shots  the rendered shots, in timeline order
     */
    protected function buildAudioBed(Project $project, $shots, string $workDir, float $videoDuration): ?string
    {
        $narration = $project->narrationAsset();
        $music = $project->musicAsset();
        $effects = $this->positionedEffects($project, $shots);

        if ($narration === null && $music === null && $effects === []) {
            return null;
        }

        $length = round($videoDuration, 3);
        $fadeFrom = max(0.0, $length - 1);
        $inputs = [];
        $chains = [];
        $bedLabels = [];
        $index = 0;

        // Narration first, so it is input 0 and reads as the spine of the graph
        // that it is.
        if ($narration !== null) {
            $inputs[] = ['-i', $narration->absolutePath()];

            // Split only when there is something to duck. An asplit whose second
            // output goes nowhere is not a harmless extra — ffmpeg refuses the
            // whole graph over an unconnected pad.
            $needsKey = $music !== null || $effects !== [];

            $chains[] = sprintf(
                '[%d:a]aformat=channel_layouts=stereo,aresample=44100,apad,atrim=0:%.3f%s',
                $index,
                $length,
                $needsKey ? ',asplit=2[vo][key]' : '[vo]',
            );
            $index++;
        }

        if ($music !== null) {
            // Looped, because the bed may be shorter than the video: the music
            // model has a ceiling and the timeline does not.
            $inputs[] = ['-stream_loop', '-1', '-i', $music->absolutePath()];
            $chains[] = sprintf(
                '[%d:a]aformat=channel_layouts=stereo,aresample=44100,atrim=0:%.3f,volume=%.4f[bed%d]',
                $index,
                $length,
                $this->dbToLinear((float) config('studio.audio.music_bed_db', -6.0)),
                $index,
            );
            $bedLabels[] = "[bed{$index}]";
            $index++;
        }

        $sfxGain = $this->dbToLinear((float) config('studio.audio.sfx_bed_db', -12.0));

        foreach ($effects as $effect) {
            // Looped for the same reason as the music, and more often: the
            // default model generates at most 22 seconds against scenes that
            // routinely run longer.
            $inputs[] = ['-stream_loop', '-1', '-i', $effect['path']];

            $chains[] = sprintf(
                '[%d:a]aformat=channel_layouts=stereo,aresample=44100,atrim=0:%2$.3f,volume=%3$.4f,'.
                // Short fades at both ends of the scene. Without them an ambience
                // that starts or stops mid-waveform clicks, and a click at every
                // scene boundary is more noticeable than the ambience itself.
                'afade=t=in:d=0.3,afade=t=out:st=%4$.3f:d=0.5,'.
                // The whole reason effects need positioning: this one belongs to
                // one scene, not to the video.
                'adelay=%5$d:all=1[bed%1$d]',
                $index,
                $effect['span'],
                $sfxGain,
                max(0.0, $effect['span'] - 0.5),
                (int) round($effect['offset'] * 1000),
            );
            $bedLabels[] = "[bed{$index}]";
            $index++;
        }

        $bed = match (count($bedLabels)) {
            0 => null,
            1 => $bedLabels[0],
            default => $this->mixBeds($chains, $bedLabels),
        };

        if ($narration !== null && $bed !== null) {
            $chains[] = sprintf(
                '%s[key]sidechaincompress=threshold=0.03:ratio=%.1f:attack=20:release=400:makeup=1[ducked]',
                $bed,
                $this->duckRatio((float) config('studio.audio.music_duck_db', -12.0)),
            );

            // normalize=0: keep narration at unity instead of halving both.
            $chains[] = sprintf(
                '[vo][ducked]amix=inputs=2:duration=first:normalize=0,'.
                'afade=t=out:st=%.3f:d=1,alimiter=limit=0.95[out]',
                $fadeFrom,
            );
        } elseif ($narration !== null) {
            $chains[] = sprintf('[vo]afade=t=out:st=%.3f:d=1,alimiter=limit=0.95[out]', $fadeFrom);
        } else {
            // No voice to duck against, so the beds are the whole track. Padded
            // because a delayed effect need not reach the end of the video.
            $chains[] = sprintf(
                '%sapad,atrim=0:%.3f,afade=t=out:st=%.3f:d=1,alimiter=limit=0.95[out]',
                $bed,
                $length,
                $fadeFrom,
            );
        }

        $output = $workDir.'/audio.wav';

        $this->ffmpeg->run([
            ...array_merge(...$inputs),
            '-filter_complex', implode(';', $chains),
            '-map', '[out]',
            '-t', (string) $length,
            '-ac', '2', '-ar', '44100',
            $output,
        ]);

        return $output;
    }

    /**
     * @param  list<string>  $chains
     * @param  list<string>  $bedLabels
     */
    protected function mixBeds(array &$chains, array $bedLabels): string
    {
        // duration=longest rather than first: an effect belonging to the last
        // scene starts late, and "first" would cut the mix at whichever bed
        // happened to be listed first.
        $chains[] = sprintf(
            '%samix=inputs=%d:duration=longest:normalize=0[beds]',
            implode('', $bedLabels),
            count($bedLabels),
        );

        return '[beds]';
    }

    /**
     * Each scene's sound effect, with where it sits on the finished timeline.
     *
     * Offsets are accumulated from the RENDERED shots the assembler is actually
     * laying down, not from the scene list. Those differ whenever a shot failed
     * or is stale, and taking the scene list would drift every effect after the
     * gap — an effect landing in the wrong scene is worse than no effect, because
     * it sounds deliberate.
     *
     * @param  Collection<int, Shot>  $shots
     * @return list<array{path: string, offset: float, span: float}>
     */
    protected function positionedEffects(Project $project, $shots): array
    {
        $byScene = $project->assets()
            ->where('type', AssetType::SoundEffect)
            ->whereNotNull('scene_id')
            ->get()
            ->keyBy('scene_id');

        if ($byScene->isEmpty()) {
            return [];
        }

        $offsets = [];
        $spans = [];
        $elapsed = 0.0;

        foreach ($shots as $shot) {
            $sceneId = $shot->scene_id;
            $duration = $shot->timelineDurationSeconds();

            $offsets[$sceneId] ??= $elapsed;
            $spans[$sceneId] = ($spans[$sceneId] ?? 0.0) + $duration;
            $elapsed += $duration;
        }

        $effects = [];

        foreach ($spans as $sceneId => $span) {
            $asset = $byScene->get($sceneId);

            if (! $asset instanceof Asset || ! $asset->exists() || $span <= 0) {
                continue;
            }

            $effects[] = [
                'path' => $asset->absolutePath(),
                'offset' => round($offsets[$sceneId], 3),
                'span' => round($span, 3),
            ];
        }

        return $effects;
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
