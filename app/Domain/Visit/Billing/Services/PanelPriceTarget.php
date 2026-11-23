<?php

namespace App\Domain\Visit\Billing\Services;

use App\Domain\Visit\Billing\Models\PriceBook;

/**
 * Which Panel price a proposal is about: the default Panel price (panelId null) or one Panel's own price. The price
 * book may not exist yet; it is created when the first price for the Panel is approved.
 */
final readonly class PanelPriceTarget
{
    private function __construct(public ?int $panelId, public ?PriceBook $book) {}

    public static function forPanel(?int $panelId): self
    {
        return new self($panelId, null);
    }

    public static function forBook(PriceBook $book): self
    {
        return new self($book->panel_id, $book);
    }

    public function scopeKey(): string
    {
        return $this->book !== null ? $this->book->scope_key : ($this->panelId === null ? 'panel:default' : 'panel:'.$this->panelId);
    }
}
