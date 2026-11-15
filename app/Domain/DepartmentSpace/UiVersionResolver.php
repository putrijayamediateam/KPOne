<?php

namespace App\Domain\DepartmentSpace;

use App\Models\User;
use Illuminate\Http\Request;

/**
 * Which look the person is in (Version 2 or Classic) and which Inertia page name to render for it. The preference is
 * read once per request and shared, so the page swap and the shared props never ask twice. The rule is the same as the
 * `data-ui` attribute: the saved switch, or any page inside the spaces shell. The waiting-room TV account is never V2.
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

    /** `Registration/Index` becomes `Registration/V2/Index` when the person is in V2 and the page is registered. */
    public function pageFor(string $component, ?User $user = null, ?Request $request = null): string
    {
        if (in_array($component, config('v2-pages.public_pages', []), true)) {
            return $this->publicPageFor($component);
        }

        $registered = config('v2-pages.pages', []);

        if (! in_array($component, $registered, true)) {
            return $component;
        }

        if (! $this->isV2($user ?? auth()->user(), $request)) {
            return $component;
        }

        $at = strrpos($component, '/');

        return $at === false
            ? 'V2/'.$component
            : substr($component, 0, $at).'/V2'.substr($component, $at);
    }

    /**
     * A public (patient-facing) page: Classic unless the one public switch is on, and then only when the V2 file exists.
     * The person's own switch is never read here, so a signed-in person and a patient get the same page, and while the
     * switch is off nobody gets a V2 public page.
     */
    private function publicPageFor(string $component): string
    {
        if (config('v2-pages.public', false) !== true) {
            return $component;
        }

        $at = strrpos($component, '/');
        $v2 = $at === false ? 'V2/'.$component : substr($component, 0, $at).'/V2'.substr($component, $at);

        return is_file(resource_path('js/pages/'.$v2.'.vue')) ? $v2 : $component;
    }
}
