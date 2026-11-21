<?php

namespace App\Domain\Agents\Connector\Queries;

use App\Domain\Agents\Connector\ConnectorQuery;
use App\Domain\Agents\Connector\ConnectorReads;
use App\Domain\Organisation\Models\Branch;

/** `system_health`: counts only (errors since a time, failed scheduled runs, pending migrations, failed queue jobs). */
final class SystemHealthQuery implements ConnectorQuery
{
    public function name(): string
    {
        return 'system_health';
    }

    public function sources(): array
    {
        return ['system.health'];
    }

    public function rules(): array
    {
        return ['since' => ['sometimes', 'date_format:Y-m-d']];
    }

    public function definitions(): string
    {
        return 'Counts only, for the whole KPOne server: error-level log lines since the given date (default the last 24 hours; at most the last 8 MB of each log file is read, and "logReadLimited" says when that cut it), failed scheduled agent runs, database changes not yet applied and failed queue jobs. A missing or unreadable log is never "all clear".';
    }

    public function organisationWide(): bool
    {
        return true;
    }

    public function run(ConnectorReads $reads, ?Branch $branch, array $input): array
    {
        return ['health' => $reads->call('system.health', null, array_intersect_key($input, ['since' => 1]))];
    }
}
