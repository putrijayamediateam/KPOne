<?php

namespace App\Domain\Agents\Connector\Queries;

use App\Domain\Agents\Connector\ConnectorQuery;
use App\Domain\Agents\Connector\ConnectorReads;
use App\Domain\Organisation\Models\Branch;

/** `clinic_day_summary`: registered, seen, waiting now and average minutes for a branch day. No per-doctor rows in v1. */
final class ClinicDaySummaryQuery implements ConnectorQuery
{
    use ShapesAnswers;

    public function name(): string
    {
        return 'clinic_day_summary';
    }

    public function sources(): array
    {
        return ['clinic.day_summary'];
    }

    public function rules(): array
    {
        return ['date' => ['sometimes', 'date_format:Y-m-d']];
    }

    public function definitions(): string
    {
        return 'Visits registered on the branch-local date (not cancelled): registered, served (called in), waiting now (today only) and the average waiting and consultation minutes. No doctor is named.';
    }

    public function organisationWide(): bool
    {
        return false;
    }

    public function run(ConnectorReads $reads, ?Branch $branch, array $input): array
    {
        $entry = $reads->call('clinic.day_summary', $branch, array_intersect_key($input, ['date' => 1]))['branches'][0] ?? [];
        unset($entry['doctors']);

        return $entry;
    }
}
