<?php

namespace App\Domain\DepartmentSpace;

use App\Domain\Access\BranchAccessService;
use App\Domain\Access\ClinicManagementNavigation;
use App\Domain\MyDay\MyRoutineService;
use App\Models\User;

/**
 * DAY-1a: the "My day" first page. Today (the person's own clock, shift, leave and credentials, read through the same own-HR
 * services as the Me space, under the same `.own` permissions) and Shortcuts (the person's own spaces and menus). It reads
 * nothing about anyone else and shows no patient, amount or reason. The routine (DAY-1b) and Waiting for me (DAY-1c) come later.
 */
final class MyDayPage
{
    public function __construct(
        private BranchAccessService $branches,
        private MeSpace $me,
        private DepartmentSpaceNavigation $spaces,
        private ClinicManagementNavigation $management,
        private MyRoutineService $routine,
    ) {}

    /** @return array<string, mixed> */
    public function for(User $actor): array
    {
        $branch = $this->branches->activeBranch($actor);
        $zone = $branch === null ? 'Asia/Kuala_Lumpur' : $branch->timezone;
        $me = $this->me->home($actor);
        $date = now()->setTimezone($zone)->format('D j M Y');
        $shortcuts = [];
        foreach ($this->spaces->spaces($actor) as $space) {
            if ($space['key'] !== DepartmentSpaceNavigation::ME[0]) {
                $shortcuts[] = ['title' => $space['title'], 'href' => route('spaces.show', ['space' => $space['key']], absolute: false)];
            }
        }
        if ($this->management->hasDestinations($actor)) {
            $shortcuts[] = ['title' => 'Clinic management', 'href' => route('clinic-management.index', absolute: false)];
        }
        if ($actor->can('dashboard.view.own')) {
            $shortcuts[] = ['title' => 'Main menu', 'href' => route('dashboard', absolute: false)];
        }

        return [
            'header' => ['date' => $date, 'branchName' => $branch?->name],
            'today' => [
                'ring' => $me['ring'], 'week' => $me['week'], 'tiles' => $me['tiles'], 'time' => $me['time'],
                'credentials' => $me['credentials'], 'branchName' => $me['branchName'], 'asOf' => $me['asOf'],
            ],
            'routine' => $this->routine->page($actor),
            'shortcuts' => $shortcuts,
        ];
    }
}
