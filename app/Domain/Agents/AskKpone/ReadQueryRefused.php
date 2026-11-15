<?php

namespace App\Domain\Agents\AskKpone;

use RuntimeException;

/** A query `kpone:read` will not run. The reason code is audited; the query text never is. */
final class ReadQueryRefused extends RuntimeException
{
    public function __construct(public readonly string $reasonCode, string $message)
    {
        parent::__construct($message);
    }
}
