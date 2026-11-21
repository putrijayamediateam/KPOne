<?php

namespace App\Domain\Agents\Connector\Queries;

use App\Domain\Agents\Connector\ConnectorQuery;
use App\Domain\Agents\Connector\ConnectorReads;
use App\Domain\Organisation\Models\Branch;

/** `panel_claims_outstanding`: approved Panel invoices claimed, unclaimed, overdue and accepted but unpaid, per Panel. */
final class PanelClaimsOutstandingQuery implements ConnectorQuery
{
    use ShapesAnswers;

    public function name(): string
    {
        return 'panel_claims_outstanding';
    }

    public function sources(): array
    {
        return ['panel.claims_summary'];
    }

    public function rules(): array
    {
        return $this->rangeRules();
    }

    public function definitions(): string
    {
        return 'Amounts are in sen. Counts approved Panel responsibilities on invoices finalised in the period (all dates when none is given): claimed or unclaimed, how many unclaimed are overdue by the Panel\'s due rule, and accepted claim amounts not yet paid. Shows the first 25 Panels.';
    }

    public function organisationWide(): bool
    {
        return false;
    }

    public function run(ConnectorReads $reads, ?Branch $branch, array $input): array
    {
        $this->assertRange($input);
        $entry = $reads->call('panel.claims_summary', $branch, array_intersect_key($input, ['from' => 1, 'to' => 1]))['branches'][0] ?? [];
        [$panels, $limits] = $this->cap(array_map(fn (array $panel): array => [...$panel, 'panel' => $this->label($panel['panel'] ?? '')], $entry['panels'] ?? []));
        $entry['panels'] = $panels;
        $entry['limits'] = ['panels' => $limits];

        return $entry;
    }
}
