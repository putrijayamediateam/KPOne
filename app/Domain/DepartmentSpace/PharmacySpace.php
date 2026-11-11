<?php

namespace App\Domain\DepartmentSpace;

use App\Domain\Access\BranchAccessService;
use App\Domain\Clinical\Dispensary\Services\DispensaryDirectoryService;
use App\Domain\Clinical\Dispensary\Services\DispensaryReturnService;
use App\Models\User;

/**
 * DS-3: the Pharmacy space home. Every widget calls the page's own service, which keeps its own permission and
 * branch checks; a widget the person cannot see on its page is simply left out (never greyed, never a count).
 */
final class PharmacySpace
{
    public function __construct(private BranchAccessService $branches, private DispensaryDirectoryService $dispensary, private DispensaryReturnService $returns) {}

    /** @return array{branchName: string|null, toDispense: array<string, mixed>|null, returnsToCheck: array<string, mixed>|null, links: list<array{title: string, href: string}>} */
    public function home(User $actor): array
    {
        $branch = $this->branches->activeBranch($actor);
        $toDispense = null;
        $returnsToCheck = null;

        // Same rule as the Dispensary list: CA and CA Supervisor with dispensary.view.branch at the active branch.
        if ($branch !== null && $actor->can('dispensary.view.branch') && $actor->hasAnyRole(['ca', 'ca_supervisor'])) {
            $board = $this->dispensary->board($actor, []);
            $toDispense = [
                'total' => $board['total'],
                'rows' => collect($board['data'])->take(6)->map(fn (array $r): array => [
                    'visitNumber' => $r['visitNumber'],
                    'patientName' => $r['patientName'],
                    'visitType' => $r['visitType'],
                    'status' => $r['dispensaryStatus'],
                    'minutes' => $r['durationMinutes'],
                    'medicineCount' => $r['medicineCount'],
                    'href' => $r['dispensaryUrl'],
                ])->values()->all(),
            ];
        }
        // Same rule as Returns to check (DSP-7b): the v1 checkers only.
        if ($branch !== null && $this->returns->canCheckReturns($actor)) {
            $returnsToCheck = ['total' => $this->returns->waitingList($actor, 1)['total']];
        }

        $links = [];
        if ($toDispense !== null) {
            $links[] = ['title' => 'Open Dispensary list', 'href' => route('registration.index', absolute: false)];
        }
        if ($returnsToCheck !== null) {
            $links[] = ['title' => 'Returns to check', 'href' => route('dispensary.returns-to-check', absolute: false)];
        }

        return ['branchName' => $branch?->name, 'toDispense' => $toDispense, 'returnsToCheck' => $returnsToCheck, 'links' => $links];
    }
}
