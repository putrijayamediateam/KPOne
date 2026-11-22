<?php

namespace App\Domain\Agents\Connector\Queries;

use App\Domain\Agents\Connector\ConnectorQuery;
use App\Domain\Agents\Connector\ConnectorReads;
use App\Domain\Organisation\Models\Branch;

/** `panel_claims_made`: Panel claims created in KPOne in a period, by status and Panel. */
final class PanelClaimsMadeQuery implements ConnectorQuery
{
    use ShapesAnswers;

    public function name(): string
    {
        return 'panel_claims_made';
    }

    public function sources(): array
    {
        return ['connector.panel_claims_made'];
    }

    public function rules(): array
    {
        return $this->rangeRules();
    }

    public function definitions(): string
    {
        return 'Original Panel claims created in KPOne on the branch-local dates (default today): how many, the amount claimed in sen, and per status and Panel (first 25 Panels). A supplementary claim is not counted again.';
    }

    public function organisationWide(): bool
    {
        return false;
    }

    public function run(ConnectorReads $reads, ?Branch $branch, array $input): array
    {
        $entry = $reads->call('connector.panel_claims_made', $branch, array_intersect_key($input, ['from' => 1, 'to' => 1]));
        [$panels, $limits] = $this->cap(array_map(fn (array $panel): array => [...$panel, 'panel' => $this->label($panel['panel'] ?? '')], $entry['panels'] ?? []));
        $entry['panels'] = $panels;
        $entry['limits'] = ['panels' => $limits];

        return $entry;
    }
}
