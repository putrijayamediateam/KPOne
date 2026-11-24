<?php

namespace App\Domain\Agents\Ask;

use RuntimeException;

/** A refused ask or answer: a short code for the audit row, a plain message for the person or for Claude, and an HTTP status. */
final class AskRefused extends RuntimeException
{
    public function __construct(public readonly string $reason, string $message, public readonly int $status = 422)
    {
        parent::__construct($message);
    }
}
