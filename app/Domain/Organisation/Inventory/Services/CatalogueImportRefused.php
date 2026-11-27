<?php

namespace App\Domain\Organisation\Inventory\Services;

use RuntimeException;

/** IMP-1: the import files were refused; nothing was written. Holds every problem found. */
final class CatalogueImportRefused extends RuntimeException
{
    /** @param list<string> $problems */
    public function __construct(public readonly array $problems)
    {
        parent::__construct('The catalogue import was refused. Nothing was written.');
    }
}
