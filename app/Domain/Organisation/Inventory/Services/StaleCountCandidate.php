<?php

namespace App\Domain\Organisation\Inventory\Services;

use RuntimeException;

/** @internal STK-45: raised inside the report transaction when the newest live count changed; the transaction restarts. */
final class StaleCountCandidate extends RuntimeException {}
