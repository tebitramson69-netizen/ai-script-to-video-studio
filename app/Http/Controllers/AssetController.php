<?php

namespace App\Http\Controllers;

use App\Models\Asset;
use App\Models\Project;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Serves generated media.
 *
 * Assets live on the private disk, never under public/, so every byte is served
 * through this controller behind auth and the project policy. Putting generated
 * media in public/ would make every clip and export world-readable to anyone who
 * guessed a filename.
 */
class AssetController extends Controller
{
    public function show(Project $project, Asset $asset): StreamedResponse|Response
    {
        $this->authorize('view', $project);
        abort_unless($asset->project_id === $project->getKey(), 404);
        abort_unless($asset->exists(), 404, 'The file for this asset is missing from storage.');

        // Inline: images and clips are previewed in the page.
        return Storage::disk($asset->disk)->response($asset->path, null, [
            'Content-Type' => $asset->mime ?? 'application/octet-stream',

            // Generated media is immutable — a new generation is a new asset id.
            'Cache-Control' => 'private, max-age=604800',
        ]);
    }

    public function download(Project $project, Asset $asset): StreamedResponse
    {
        $this->authorize('view', $project);
        abort_unless($asset->project_id === $project->getKey(), 404);
        abort_unless($asset->exists(), 404);

        $filename = Str::slug($project->title).'-'.$asset->id.'.'
            .(pathinfo($asset->path, PATHINFO_EXTENSION) ?: 'bin');

        return Storage::disk($asset->disk)->download($asset->path, $filename);
    }
}
