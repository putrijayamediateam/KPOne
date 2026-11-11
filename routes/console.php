<?php

use App\Console\Commands\AgentsDailyRun;
use App\Console\Commands\AutoRejectJobApplications;
use App\Console\Commands\CleanupPublicPatientIntakes;
use App\Console\Commands\PurgeCompliancePhotos;
use App\Console\Commands\PurgeHrDocuments;
use App\Console\Commands\PurgeJobApplications;
use App\Console\Commands\PurgeLeaveEvidence;
use App\Console\Commands\PurgeLeaveRecords;
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

// PDPA 15 (owner, 2026-11-11; the accountant confirms the 7 years before real use): leave years older than 7 years are
// deleted, with their requests, ledger, entitlements and evidence. Pending requests are never deleted.
Schedule::command(PurgeLeaveRecords::class)
    ->daily()
    ->withoutOverlapping()
    ->onOneServer();

// HR-4b: staff document files are deleted after their category's clinic-entered retention.
Schedule::command(PurgeHrDocuments::class)
    ->daily()
    ->withoutOverlapping()
    ->onOneServer();

// HR-13: closed job applications are cleared after the clinic-entered retention (none shipped; nothing until HR sets one).
Schedule::command(PurgeJobApplications::class)
    ->daily()
    ->withoutOverlapping()
    ->onOneServer();

// HR-17b: an application left at New or Phone screening for 3 working days gets one reminder to the hiring mailbox,
// then is closed as Auto-rejected. Each application's branch-local date decides, so hourly is safe and catches up.
Schedule::command(AutoRejectJobApplications::class)
    ->hourlyAt(15)
    ->withoutOverlapping()
    ->onOneServer();

// AG-8: the AI agents' daily summary runs. Each branch runs a slot once its local clock reaches 07:00 or 14:00
// (once per local date), so calling every hour is safe and catches up after a missed hour.
Schedule::command(AgentsDailyRun::class, ['0700'])
    ->hourlyAt(5)
    ->withoutOverlapping()
    ->onOneServer();

Schedule::command(AgentsDailyRun::class, ['1400'])
    ->hourlyAt(10)
    ->withoutOverlapping()
    ->onOneServer();

// HR-11c: audit and task photo files are deleted after the clinic's own retention; nothing is deleted until a number is set.
Schedule::command(PurgeCompliancePhotos::class)
    ->daily()
    ->withoutOverlapping()
    ->onOneServer();
