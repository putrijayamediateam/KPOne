<?php

declare(strict_types=1);
use App\Domain\Clinical\Documents\BranchDocumentNumberAllocator;
use App\Domain\Organisation\Models\Branch;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! app()->environment('testing') || ! preg_match('/(?:^|_)(test|testing)(?:_|$)/i', DB::connection()->getDatabaseName())) {
    exit(65);
}
Carbon::setTestNow('2038-01-01 01:00:00 UTC');
DB::transaction(function () use ($argv): void {
    $branch = Branch::findOrFail((int) $argv[1]);
    $number = app(BranchDocumentNumberAllocator::class)->allocate($branch, 'referral', 'REF', now()->utc()->toImmutable());
    fwrite(STDOUT, $number.PHP_EOL);
    fflush(STDOUT);
    if (($argv[2] ?? '') === 'hold' && trim((string) fgets(STDIN)) !== 'COMMIT') {
        throw new RuntimeException('Missing commit instruction');
    }
});
