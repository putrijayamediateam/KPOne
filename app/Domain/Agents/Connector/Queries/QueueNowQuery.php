<?php

namespace App\Domain\Agents\Connector\Queries;

use App\Domain\Agents\Connector\ConnectorQuery;
use App\Domain\Agents\Connector\ConnectorReads;
use App\Domain\Organisation\Models\Branch;

/** `queue_now`: the consultation queue right now at a branch (counts). */
final class QueueNowQuery implements ConnectorQuery
{
    use ShapesAnswers;

    public function name(): string
    {
        return 'queue_now';
    }

    public function sources(): array
    {
        return ['connector.queue_now'];
    }

    public function rules(): array
    {
        return [];
    }

    public function definitions(): string
    {
        return 'Right now, the consultation queue at the branch including patients carried over from earlier days: waiting (including those marked not ready to call, counted again separately; carriedOver is how many of the waiting are from an earlier day), serving, and on hold (a consultation the doctor has put on hold). Counts only.';
    }

    public function organisationWide(): bool
    {
        return false;
    }

    public function run(ConnectorReads $reads, ?Branch $branch, array $input): array
    {
        return $reads->call('connector.queue_now', $branch);
    }
}
