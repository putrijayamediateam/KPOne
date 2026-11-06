<?php

declare(strict_types=1);

use App\Domain\HR\Leave\Services\LeaveEntryService;
use App\Domain\HR\Leave\Services\LeaveRequestService;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

/*
 * HR-2a contention worker: one leave write (apply, approve or confirm an adjustment) in its own PostgreSQL session.
 * Prints READY <pid>, waits for GO, runs and prints SUCCESS, STALE <field>: <message> or DENIED.
 */
require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

try {
    $db = DB::connection();
    if (! app()->environment('testing') || $db->getDriverName() !== 'pgsql' || preg_match('/(?:^|_)(?:test|testing)(?:_|$)/i', (string) $db->getDatabaseName()) !== 1) {
        exit(65);
    }
    $db->statement("select set_config('application_name', ?, false)", [(string) end($argv)]);
    fwrite(STDOUT, 'READY '.(int) $db->scalar('select pg_backend_pid()').PHP_EOL);
    fflush(STDOUT);
    if (trim((string) fgets(STDIN)) !== 'GO') {
        exit(66);
    }
    $actor = User::query()->findOrFail((int) $argv[1]);
    $a = json_decode(base64_decode($argv[2], true), true, 64, JSON_THROW_ON_ERROR);
    match ($a['op']) {
        'apply' => app(LeaveRequestService::class)->apply($actor, $a),
        'approve' => app(LeaveRequestService::class)->approve($actor, $a['request'], $a),
        'confirm' => app(LeaveEntryService::class)->decide($actor, 'adjustment', $a['entry'], 'confirmed', $a),
    };
    fwrite(STDOUT, 'SUCCESS '.$a['op'].PHP_EOL);
} catch (ValidationException $e) {
    while (DB::transactionLevel() > 0) {
        DB::rollBack();
    }
    $field = (string) array_key_first($e->errors());
    fwrite(STDOUT, 'STALE '.$field.': '.str_replace(["\r", "\n"], ' ', (string) ($e->errors()[$field][0] ?? '')).PHP_EOL);
} catch (AuthorizationException|ModelNotFoundException|HttpException) {
    while (DB::transactionLevel() > 0) {
        DB::rollBack();
    }
    fwrite(STDOUT, 'DENIED'.PHP_EOL);
} catch (Throwable $e) {
    while (DB::transactionLevel() > 0) {
        DB::rollBack();
    }
    fwrite(STDERR, get_class($e).' SQLSTATE='.$e->getCode().' '.$e->getMessage().PHP_EOL);
    exit(1);
}
