<?php

namespace App\Domain\Agents\Connector;

use RuntimeException;

/** A connector call that was refused. The message is plain text for the person; the code is audited. */
final class ConnectorRefused extends RuntimeException
{
    public function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }
}
