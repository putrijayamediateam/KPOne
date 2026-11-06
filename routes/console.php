<?php

use App\Console\Commands\CleanupPublicPatientIntakes;
use App\Console\Commands\PurgeLeaveEvidence;
use App\Console\Commands\PurgePanelStatements;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command(CleanupPublicPatientIntakes::class)
    ->daily()
    ->withoutOverlapping()
    ->onOneServer();

// PNL-7: uploaded Panel statement files are deleted 30 days after upload.
Schedule::command(PurgePanelStatements::class)
    ->daily()
    ->withoutOverlapping()
    ->onOneServer();

// HR-2b: leave evidence files are deleted after their leave type's retention (owner, 2026-11-06).
Schedule::command(PurgeLeaveEvidence::class)
    ->daily()
    ->withoutOverlapping()
    ->onOneServer();
