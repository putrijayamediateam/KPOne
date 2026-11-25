<?php

namespace App\Domain\DepartmentSpace;

use App\Models\User;

/**
 * HR-22d: where a roster station's card on the home leads. The server decides: a link is returned only when the
 * person already holds the permission of the page behind it, so no one is shown a link they cannot open.
 * A station this map does not know gets no link, and the card shows text only.
 */
final class StationLink
{
    /** station key => [permission, route name] */
    private const MAP = [
        'registration' => ['visits.view.branch', 'registration.index'],
        'ma' => ['visits.view.branch', 'registration.index'],
        'dispensary' => ['visits.view.branch', 'registration.index'],
        'treatment' => ['queue.view.branch', 'queue.index'],
    ];

    public static function for(User $actor, string $station): ?string
    {
        $entry = self::MAP[$station] ?? null;
        if ($entry === null || ! $actor->can($entry[0])) {
            return null;
        }

        return route($entry[1], absolute: false);
    }
}
