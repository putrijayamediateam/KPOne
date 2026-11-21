<?php

namespace App\Domain\DepartmentSpace;

use App\Models\User;
use Illuminate\Http\Request;

/**
 * Which look the person is in and which Inertia page name to render for it. Classic only (CO-1, owner decision
 * 19 Nov 2026): the answer is always Classic. The class stays, with the same methods, until the V2 removal slices
 * (CO-10) delete it together with the V2 pages; nothing reads the saved "Try the new layout" switch any more.
 */
final class UiVersionResolver
{
    /**
     * The switch is offered to nobody and on for nobody. The saved rows in `user_space_preferences` are kept (the owner
     * decides later whether to drop them) but never read.
     *
     * @return array{offered: bool, enabled: bool, lockVersion: int}
     */
    public function state(User $user): array
    {
        return ['offered' => false, 'enabled' => false, 'lockVersion' => 0];
    }

    /**
     * The "New look (preview)" pilot. Classic only (CO-1, owner decision 19 Nov 2026): Version 2 is retired, so the pilot
     * is off for everyone. The saved choice stays in `user_space_preferences` (not deleted, owner decision) but is not read.
     */
    public function newLook(?User $user, ?Request $request = null): bool
    {
        return false;
    }

    /**
     * Classic only (CO-1, owner decision 19 Nov 2026): every person always sees Classic, whatever their saved switch
     * says and on every page, the department spaces included. The V2 files stay until their removal slices.
     */
    public function isV2(?User $user, ?Request $request = null): bool
    {
        return false;
    }

    /**
     * The Inertia page to render: always the Classic page (CO-1). Before Classic only, a page listed in
     * `config/v2-pages.php` became its `V2` twin for a person in Version 2, and the public QR pages followed one global
     * switch; neither swap happens any more, so no staff member or patient can reach a V2 page.
     */
    public function pageFor(string $component, ?User $user = null, ?Request $request = null): string
    {
        return $component;
    }
}
