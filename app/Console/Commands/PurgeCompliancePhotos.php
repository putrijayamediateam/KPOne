<?php

namespace App\Console\Commands;

use App\Domain\Organisation\Compliance\Services\CompliancePhotos;
use Illuminate\Console\Command;

/** HR-11c: deletes audit and task photo files past the clinic's own retention (none set means nothing is deleted). The rows stay. */
class PurgeCompliancePhotos extends Command
{
    protected $signature = 'compliance-photos:purge';

    protected $description = 'Delete clinic compliance audit and task photo files past the clinic-entered retention.';

    public function handle(CompliancePhotos $photos): int
    {
        $this->components->info('Deleted '.$photos->purgeExpired().' compliance photo files.');

        return self::SUCCESS;
    }
}
