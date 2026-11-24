<?php

namespace App\Domain\Agents\Chat;

use RuntimeException;

/** A chatbox request that was refused. The message is plain text for the person; the code decides the screen state. */
final class ChatRefused extends RuntimeException
{
    public function __construct(public readonly string $reason, string $message, public readonly int $status = 422)
    {
        parent::__construct($message);
    }
}
