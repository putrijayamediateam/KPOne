<?php

declare(strict_types=1);

use App\Domain\HR\Applications\Services\InterviewDocuments;
use App\Domain\HR\Applications\Services\InterviewSlots;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

/*
 * HR-17c/HR-17d contention worker: one interview confirmation, or one interview document upload, in its own PostgreSQL session.
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
    if (($a['op'] ?? 'confirm') === 'upload') {
        // HR-17d: a synthetic PDF written to a temporary file, uploaded through the service.
        $tmp = tempnam(sys_get_temp_dir(), 'kpone-doc');
        file_put_contents($tmp, "%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\ntrailer << /Root 1 0 R >>\nSYNTHETIC-".$a['marker']."\n%%EOF\n");
        app(InterviewDocuments::class)->upload($actor, new UploadedFile($tmp, 'doc.pdf', 'application/pdf', null, true), $a);
        @unlink($tmp);
        fwrite(STDOUT, 'SUCCESS upload'.PHP_EOL);
    } else {
        app(InterviewSlots::class)->confirm($actor, (string) $a['application'], $a);
        fwrite(STDOUT, 'SUCCESS confirm'.PHP_EOL);
    }
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
