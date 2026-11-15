<?php

namespace App\Console\Commands;

use App\Domain\HR\Records\Services\DocumentService;
use Illuminate\Console\Command;

/** HR-4b: deletes staff documents whose clinic-entered retention has passed. The rows and history stay. */
class PurgeHrDocuments extends Command
{
    protected $signature = 'hr-documents:purge';

    protected $description = 'Delete staff document files past their category retention, counted from the end of the worker\'s latest employment period.';

    public function handle(DocumentService $documents): int
    {
        $this->components->info('Deleted '.$documents->purgeExpired().' staff document files.');

        return self::SUCCESS;
    }
}
