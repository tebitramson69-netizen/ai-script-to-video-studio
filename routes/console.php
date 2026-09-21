<?php

use App\Jobs\ReconcileProviderRequestsJob;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
|--------------------------------------------------------------------------
| Provider reconciliation
|--------------------------------------------------------------------------
|
| Collects generations that have finished at the provider. This is the primary
| completion path — webhooks, once their signature scheme is verified, are an
| optimisation on top of it, never a replacement. Webhooks get lost; a sweep
| does not.
|
| withoutOverlapping: a sweep that outlives its interval must not start a second
| one on top of itself. Video renders are slow, so that is a realistic case
| rather than a theoretical one.
|
*/
Schedule::job(new ReconcileProviderRequestsJob)
    ->everyMinute()
    ->withoutOverlapping()
    ->name('studio:reconcile-provider-requests');
