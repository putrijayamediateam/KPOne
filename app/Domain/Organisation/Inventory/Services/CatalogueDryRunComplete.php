<?php

namespace App\Domain\Organisation\Inventory\Services;

use RuntimeException;

/** @internal IMP-1: thrown at the end of a dry run so the transaction rolls every write back. */
final class CatalogueDryRunComplete extends RuntimeException {}
