<?php

declare(strict_types=1);

use App\Domain\Access\BranchAccessService;
use App\Domain\Visit\Models\Visit;
use App\Domain\Visit\Services\RegisterAndQueueService;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\Support\IsolatedTestStorage;

require dirname(__DIR__, 2).'/vendor/autoload.php';
IsolatedTestStorage::beforeBootstrap();
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
IsolatedTestStorage::configure($app);
try {
    if (! app()->environment('testing') || DB::getDriverName() !== 'pgsql' || ! preg_match('/(?:^|_)test(?:ing)?(?:_|$)/i', DB::getDatabaseName())) {
        exit(65);
    }
    [, $actorId, $branchId, $patientNumber, $reasonPublicId, $key, $frozenAt, $name] = $argv;
    DB::statement("select set_config('application_name', ?, false)", [$name]);
    fwrite(STDOUT, 'READY '.DB::scalar('select pg_backend_pid()').PHP_EOL);
    fflush(STDOUT);
    if (trim((string) fgets(STDIN)) !== 'GO') {
        exit(66);
    }
    Date::setTestNow(CarbonImmutable::parse($frozenAt));
    app('session')->start();
    session([BranchAccessService::SESSION_KEY => (int) $branchId]);
    $actor = User::findOrFail((int) $actorId);
    try {
        $result = app(RegisterAndQueueService::class)->register($actor, [
            'idempotency_key' => $key, 'expected_branch_id' => (int) $branchId, 'patient_number' => $patientNumber,
            'visit_type' => 'consultation', 'assigned_doctor_user_id' => null, 'visit_reason_public_ids' => [$reasonPublicId],
            'priority' => 'normal', 'coverage_type' => 'self_pay', 'panel_id' => null, 'coverage_member_reference' => null, 'confirm_repeat' => false,
        ]);
        /** @var Visit $visit */
        $visit = $result['visit'];
        fwrite(STDOUT, 'AUTO_OK '.$visit->assigned_doctor_user_id.PHP_EOL);
    } catch (ValidationException|AuthorizationException $e) {
        fwrite(STDOUT, 'AUTO_REFUSED '.json_encode($e instanceof ValidationException ? $e->errors() : [])."\n");
    }
} catch (Throwable $e) {
    fwrite(STDERR, $e::class.': '.$e->getMessage().PHP_EOL);
    exit(1);
}
