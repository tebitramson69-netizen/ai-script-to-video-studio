<?php

use App\Http\Controllers\AssetController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\CharacterController;
use App\Http\Controllers\PipelineController;
use App\Http\Controllers\ProjectController;
use App\Http\Controllers\SceneController;
use App\Http\Controllers\ShotController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Authentication
|--------------------------------------------------------------------------
|
| Login only — there is no public registration (NG2). Create the owner with
| `php artisan studio:create-owner`.
|
*/

Route::middleware('guest')->group(function () {
    Route::get('/login', [LoginController::class, 'create'])->name('login');
    Route::post('/login', [LoginController::class, 'store']);
});

Route::post('/logout', [LoginController::class, 'destroy'])
    ->middleware('auth')
    ->name('logout');

/*
|--------------------------------------------------------------------------
| Studio
|--------------------------------------------------------------------------
|
| Everything below requires authentication. Every route that spends money or
| mutates state is POST/PATCH/DELETE, so Laravel's CSRF middleware covers it
| (§14) — no generation is ever reachable by a plain GET.
|
*/

Route::middleware('auth')->group(function () {
    Route::redirect('/', '/projects');

    Route::get('/projects', [ProjectController::class, 'index'])->name('projects.index');
    Route::get('/projects/create', [ProjectController::class, 'create'])->name('projects.create');
    Route::post('/projects', [ProjectController::class, 'store'])->name('projects.store');
    Route::get('/projects/{project}', [ProjectController::class, 'show'])->name('projects.show');
    Route::delete('/projects/{project}', [ProjectController::class, 'destroy'])->name('projects.destroy');

    Route::prefix('/projects/{project}')->group(function () {
        // The polling endpoint behind the live progress strip. A GET that spends
        // nothing and mutates nothing, so it needs no CSRF token — but it is
        // inside `auth` and behind the same `view` policy as the page.
        //
        // throttle: adaptive polling settles at roughly 20 requests a minute per
        // open tab, so 120 leaves room for several tabs while still stopping a
        // runaway loop — a poller whose backoff broke would otherwise hammer the
        // app until someone noticed.
        //
        // cache.headers with etag: the response is a few hundred bytes and most
        // polls return exactly the bytes of the last one. A 304 sends none of
        // them, which matters on a metered mobile connection far more than it
        // does on the server.
        Route::get('/status', [ProjectController::class, 'status'])
            ->middleware(['throttle:120,1', 'cache.headers:private;max_age=0;etag'])
            ->name('projects.status');

        // Pipeline stages (PRD §8).
        Route::post('/parse', [PipelineController::class, 'parseScript'])->name('pipeline.parse');
        Route::post('/characters/generate', [PipelineController::class, 'generateCharacters'])->name('pipeline.characters');
        Route::post('/shots/plan', [PipelineController::class, 'planShots'])->name('pipeline.plan');
        Route::post('/shots/render', [PipelineController::class, 'renderShots'])->name('pipeline.render');
        Route::post('/audio', [PipelineController::class, 'generateAudio'])->name('pipeline.audio');
        Route::post('/export', [PipelineController::class, 'export'])->name('pipeline.export');
        Route::post('/audio/narration', [PipelineController::class, 'regenerateNarration'])->name('pipeline.narration.regenerate');
        Route::post('/audio/music', [PipelineController::class, 'regenerateMusic'])->name('pipeline.music.regenerate');
        Route::post('/audio/sfx', [PipelineController::class, 'regenerateSoundEffects'])->name('pipeline.sfx.regenerate');

        // NFR-7 retention.
        Route::post('/purge', [PipelineController::class, 'purgeIntermediates'])->name('pipeline.purge');

        // Owner review and editing.
        Route::patch('/scenes/{scene}', [SceneController::class, 'update'])->name('scenes.update');
        Route::post('/scenes/{scene}/move', [SceneController::class, 'move'])->name('scenes.move');
        Route::delete('/scenes/{scene}', [SceneController::class, 'destroy'])->name('scenes.destroy');

        Route::patch('/characters/{character}', [CharacterController::class, 'update'])->name('characters.update');
        Route::post('/characters/{character}/lock', [CharacterController::class, 'lock'])->name('characters.lock');
        Route::delete('/characters/{character}', [CharacterController::class, 'destroy'])->name('characters.destroy');

        Route::post('/shots/{shot}/regenerate', [ShotController::class, 'regenerate'])->name('shots.regenerate');

        // Generated media lives on the private disk and is served only here.
        Route::get('/assets/{asset}', [AssetController::class, 'show'])->name('assets.show');
        Route::get('/assets/{asset}/download', [AssetController::class, 'download'])->name('assets.download');
    });
});
