<?php

namespace App\Domain\Organisation\Inventory\Services;

/** STK-52: the current checked pack conversion of one SKU, read only. Store {@see $id} on a line to keep the version. */
final readonly class CheckedPackConversion
{
    public function __construct(
        public int $id,
        public string $publicId,
        public int $skuId,
        public int $version,
        public string $packUnit,
        public int $basePerPack,
        public string $baseUnit,
    ) {}
}
