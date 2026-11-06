<?php

namespace App\Domain\Agents\Stocktake;

/**
 * The result of one stocktake officer (Rizal) check at one branch: counts only, plus capped worklists
 * of SKU and location identifiers. Feeds Rizal's section of the daily summary (contract section 14).
 */
final readonly class StocktakeCheck
{
    /**
     * @param  list<array<string, mixed>>  $due  stocked pairs with no completed count in the window, oldest first
     * @param  list<array<string, mixed>>  $open  pairs with an open count case
     */
    public function __construct(
        public string $branch,
        public string $date,
        public string $dueBefore,
        public int $countsDue,
        public int $openCases,
        public int $unresolvedVariances,
        public array $due,
        public array $open,
    ) {}

    /** The fixed-template step summary: counts only, never item text. */
    public function summary(): string
    {
        return sprintf('%s: %d %s due, %d open %s, %d unresolved %s.',
            $this->branch,
            $this->countsDue, $this->countsDue === 1 ? 'count' : 'counts',
            $this->openCases, $this->openCases === 1 ? 'case' : 'cases',
            $this->unresolvedVariances, $this->unresolvedVariances === 1 ? 'variance' : 'variances');
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'branch' => $this->branch,
            'date' => $this->date,
            'dueBefore' => $this->dueBefore,
            'countsDue' => $this->countsDue,
            'openCases' => $this->openCases,
            'unresolvedVariances' => $this->unresolvedVariances,
            'due' => $this->due,
            'open' => $this->open,
        ];
    }
}
