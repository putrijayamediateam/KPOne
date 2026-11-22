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
        return 'Amounts are in sen. Counts approved Panel responsibilities on invoices finalised in the period (default: the last 31 days; at most 31 days): claimed or unclaimed, how many unclaimed are overdue by the Panel\'s due rule, and accepted claim amounts not yet paid. Shows the first 25 Panels.';
    }

    public function organisationWide(): bool
    {
        return false;
    }

    public function run(ConnectorReads $reads, ?Branch $branch, array $input): array
    {
        $window = array_intersect_key($input, ['from' => 1, 'to' => 1]);
        if ($window === [] && $branch) {
            $today = now($branch->timezone);
            $window = ['from' => $today->copy()->subDays(self::MAX_DAYS - 1)->toDateString(), 'to' => $today->toDateString()];
        }
        $entry = $reads->call('panel.claims_summary', $branch, $window)['branches'][0] ?? [];
        [$panels, $limits] = $this->cap(array_map(fn (array $panel): array => [...$panel, 'panel' => $this->label($panel['panel'] ?? '')], $entry['panels'] ?? []));
        $keys = ['panel', 'approvedInvoices', 'approvedSen', 'claimedInvoices', 'claimedSen', 'unclaimedInvoices', 'unclaimedSen', 'overdueUnclaimedInvoices', 'overdueUnclaimedSen', 'acceptedSen', 'remittedSen', 'acceptedOutstandingSen', 'acceptedUnpaidClaims'];
        $entry = [
            'branch' => $entry['branch'] ?? null,
            'from' => $entry['from'] ?? null,
            'to' => $entry['to'] ?? null,
            'totals' => $entry['totals'] ?? [],
            'panels' => array_map(fn (array $panel): array => array_intersect_key($panel, array_flip($keys)), $panels),
        ];
        $entry['limits'] = ['panels' => $limits];

        return $entry;
    }
}
