<?php

namespace App\Domain\Agents\Connector\Queries;

use App\Domain\Agents\Connector\ConnectorQuery;
use App\Domain\Agents\Connector\ConnectorReads;
use App\Domain\Organisation\Models\Branch;

/** `visits_by_state`: visits registered on a branch day by state, including unfinished. */
final class VisitsByStateQuery implements ConnectorQuery
{
    use ShapesAnswers;

    public function name(): string
    {
        return 'visits_by_state';
    }

    public function sources(): array
    {
        return ['connector.visits_by_state'];
    }

    public function rules(): array
    {
        return ['date' => ['sometimes', 'date_format:Y-m-d']];
    }

    public function definitions(): string
    {
        return 'Visits registered on the branch-local date, by state. Unfinished: registered and not yet completed. Total not cancelled is the figure Insights In-clinic and the clinic day summary call registered; total including cancelled adds cancelled visits.';
    }

    public function organisationWide(): bool
    {
        return false;
    }

    public function run(ConnectorReads $reads, ?Branch $branch, array $input): array
    {
        return $reads->call('connector.visits_by_state', $branch, array_intersect_key($input, ['date' => 1]));
    }
}
