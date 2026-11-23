<?php

declare(strict_types=1);

use App\Domain\Access\BranchAccessService;
use App\Domain\Clinical\Models\EncounterVitalObservation;
use App\Domain\Clinical\Services\ClinicalEncounterService;
use App\Domain\Clinical\Triage\TriageGuidelineService;
use App\Domain\Clinical\Triage\TriageGuidelineVersion;
use App\Domain\Clinical\Triage\TriageService;
use App\Domain\Queue\Services\CallNextService;
use App\Domain\Queue\Services\QueueEntryService;
use App\Domain\Visit\Models\Visit;
use App\Domain\Visit\Services\VisitAdministrationService;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\Support\IsolatedTestStorage;

/** TRI-1a contention worker: one service call per process, started together behind a lock the parent holds. */
require dirname(__DIR__, 2).'/vendor/autoload.php';
IsolatedTestStorage::beforeBootstrap();
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
IsolatedTestStorage::configure($app);
try {
    if (! app()->environment('testing') || DB::getDriverName() !== 'pgsql' || ! preg_match('/(?:^|_)test(?:ing)?(?:_|$)/i', DB::getDatabaseName())) {
        exit(65);
    }
    [, $mode, $actorId, $visitId, $branchId, $payload, $name] = $argv;
    $input = json_decode(base64_decode($payload, true) ?: '[]', true, 16, JSON_THROW_ON_ERROR);
    DB::statement("select set_config('application_name', ?, false)", [$name]);
    fwrite(STDOUT, 'READY '.DB::scalar('select pg_backend_pid()').PHP_EOL);
    fflush(STDOUT);
    if (trim((string) fgets(STDIN)) !== 'GO') {
        exit(66);
    }
    app('session')->start();
    session([BranchAccessService::SESSION_KEY => (int) $branchId]);
    $actor = User::findOrFail((int) $actorId);
    $visit = $visitId === '0' ? null : Visit::findOrFail((int) $visitId);
    try {
        if ($mode === 'triage') {
            $row = app(TriageService::class)->record($actor, $visit, $input);
            fwrite(STDOUT, 'TRIAGE_ID '.$row->id.PHP_EOL);
        } elseif ($mode === 'start') {
            // Stands in for TRI-1c's pre-fill: the latest triage row read inside the encounter-start transaction.
            $seen = DB::transaction(function () use ($actor, $visit, $input): int {
                app(ClinicalEncounterService::class)->start($actor, $visit, $input);

                return (int) (app(TriageService::class)->latest($visit)?->id ?? 0);
            });
            fwrite(STDOUT, 'SEEN '.$seen.PHP_EOL);
        } elseif ($mode === 'startcopy') {
            // TRI-1c: the real copy made inside encounter start; report which triage row the vitals came from.
            $encounter = app(ClinicalEncounterService::class)->start($actor, $visit, $input);
            $source = (int) (EncounterVitalObservation::query()->where('clinical_encounter_id', $encounter->id)->value('source_triage_assessment_id') ?? 0);
            fwrite(STDOUT, 'SOURCE '.$source.PHP_EOL);
        } elseif ($mode === 'call') {
            app(QueueEntryService::class)->call($actor, $visit, $input);
        } elseif ($mode === 'callnext') {
            app(CallNextService::class)->call($actor, $input);
        } elseif ($mode === 'cancel') {
            app(VisitAdministrationService::class)->cancel($visit, $input, $actor);
        } elseif ($mode === 'approve') {
            app(TriageGuidelineService::class)->approve($actor, TriageGuidelineVersion::findOrFail((int) $input['version_id']), $input);
        } else {
            exit(67);
        }
        fwrite(STDOUT, strtoupper($mode).'_OK'.PHP_EOL);
    } catch (ValidationException|AuthorizationException $e) {
        fwrite(STDOUT, strtoupper($mode).'_REFUSED'.PHP_EOL);
    }
} catch (Throwable $e) {
    fwrite(STDERR, $e::class.': '.$e->getMessage().PHP_EOL);
    exit(1);
}
