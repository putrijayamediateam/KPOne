<?php

namespace App\Domain\DepartmentSpace;

use App\Models\User;
use Illuminate\Http\Request;

/**
 * Which look the person is in (Version 2 or Classic). The preference is read once per request and shared, so nothing
 * that needs it (the shared page props, the page swap) ever asks twice. The rule is the same as the `data-ui`
 * attribute: the saved switch, or any page inside the spaces shell. The waiting-room TV account is never V2.
 */
final class UiVersionResolver
{
    /** @var array<int, array{offered: bool, enabled: bool, lockVersion: int}> */
    private array $states = [];

    public function __construct(private SpacePreferenceService $preferences) {}

    /** @return array{offered: bool, enabled: bool, lockVersion: int} */
    public function state(User $user): array
    {
        return $this->states[$user->id] ??= $this->preferences->state($user);
    }

    public function isV2(?User $user, ?Request $request = null): bool
    {
        if ($user === null) {
            return false;
        }

        $state = $this->state($user);

        if (! $state['offered']) {
            return false;
        }

        $request ??= request();

        return $state['enabled'] || $request->is('spaces', 'spaces/*');
    }
}
