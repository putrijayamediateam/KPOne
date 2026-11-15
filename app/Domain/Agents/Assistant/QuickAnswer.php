<?php

namespace App\Domain\Agents\Assistant;

/**
 * One answer to a Director quick question (AG-5): a table and one sentence built from a fixed template. Never
 * stored (contract section 2): the run and its steps are kept, the figures are recomputed when asked again.
 *
 * Each cell is ['value' => …, 'kind' => text|count|money|minutes|quantity]; money is whole sen.
 */
final readonly class QuickAnswer
{
    /**
     * @param  list<array{key: string, label: string, align: 'left'|'right'}>  $columns
     * @param  list<list<array{value: int|string|null, kind: string}>>  $rows
     * @param  list<array{value: int|string|null, kind: string}>|null  $totals
     * @param  array<string, string|null>  $scope  branch, date, from, to as shown on the page
     */
    public function __construct(
        public string $question,
        public string $label,
        public string $sentence,
        public array $columns,
        public array $rows,
        public int $totalRows,
        public ?array $totals,
        public array $scope,
        public int $runId,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'question' => $this->question,
            'label' => $this->label,
            'sentence' => $this->sentence,
            'columns' => $this->columns,
            'rows' => $this->rows,
            'totalRows' => $this->totalRows,
            'truncated' => $this->totalRows > count($this->rows),
            'totals' => $this->totals,
            'scope' => $this->scope,
            'runId' => $this->runId,
        ];
    }
}
