<?php

namespace App\Domain\Audit;

use App\Models\User;
use Closure;

/**
 * Carries an explicit actor only for the synchronous Spatie mutation being run.
 * Generic access events outside this boundary continue to use normal Auth context.
 */
class AccessChangeActorContext
{
    /** @var list<User> */
    private array $actors = [];

    public function current(): ?User
    {
        $key = array_key_last($this->actors);

        return $key === null ? null : $this->actors[$key];
    }

    public function run(User $actor, Closure $callback): mixed
    {
        $this->actors[] = $actor;

        try {
            return $callback();
        } finally {
            array_pop($this->actors);
        }
    }
}
