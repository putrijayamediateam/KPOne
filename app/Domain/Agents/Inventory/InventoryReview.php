<?php

namespace App\Domain\Agents\Inventory;

/** What one inventory analyst (Hana) review proposed at one branch: counts per type, and how many were already in today. */
final readonly class InventoryReview
{
    /**
     * @param  array<string, int>  $proposed  new proposals by type
     * @param  array<string, int>  $alreadyProposed  suggestions already made today (same branch, item and date), by type
     */
    public function __construct(
        public string $branch,
        public string $date,
        public array $proposed,
        public array $alreadyProposed,
    ) {}

    public function total(): int
    {
        return array_sum($this->proposed);
    }

    /** The fixed-template step summary: counts only. */
    public function summary(): string
    {
        $count = fn (string $type): int => ($this->proposed[$type] ?? 0) + ($this->alreadyProposed[$type] ?? 0);
        $again = array_sum($this->alreadyProposed);

        return sprintf('%s: %d restock, %d expiry, %d transfer, %d slow-moving suggestions%s.',
            $this->branch,
            $count(InventoryAnalyst::RESTOCK), $count(InventoryAnalyst::EXPIRY), $count(InventoryAnalyst::TRANSFER), $count(InventoryAnalyst::SLOW),
            $again > 0 ? " ({$again} already proposed today)" : '');
    }
}
