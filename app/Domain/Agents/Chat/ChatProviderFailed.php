<?php

namespace App\Domain\Agents\Chat;

use RuntimeException;

/** The provider could not answer (no key, timeout, error status, unreadable body). Never carries the key or the prompt. */
final class ChatProviderFailed extends RuntimeException {}
