<?php

namespace App\Domain\Approvals;

use Carbon\CarbonImmutable;

/**
 * One waiting item as the inbox shows it: a document number (or a business date), how long it has waited and where to open it.
 * There is deliberately no field for a name, an amount, a reason or free text.
 */
final readonly class ApprovalItem
{
    public function __construct(public string $reference, public CarbonImmutable $since, public string $href) {}

    /** @return array{reference: string, age: string, href: string} */
    public function toArray(CarbonImmutable $now): array
    {
        return ['reference' => $this->reference, 'age' => 'waiting '.AgeWords::days($this->since, $now), 'href' => $this->href];
    }
}
