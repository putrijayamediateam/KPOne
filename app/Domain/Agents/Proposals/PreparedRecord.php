<?php

namespace App\Domain\Agents\Proposals;

/** What an approval prepared: the kind of record, its public id and its printed number (ids and codes only). */
final readonly class PreparedRecord
{
    public const TYPES = ['inventory.stock_request', 'inventory.purchase_request'];

    public function __construct(public string $type, public string $publicId, public string $label)
    {
        if (! in_array($type, self::TYPES, true) || $publicId === '' || trim($label) === '') {
            throw new \InvalidArgumentException('A prepared record needs a known type, a public id and a number.');
        }
    }
}
