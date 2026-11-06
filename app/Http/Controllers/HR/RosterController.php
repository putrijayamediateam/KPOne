<?php

namespace App\Http\Controllers\HR;

use App\Domain\HR\Roster\Services\MinimumStaffingService;
use App\Domain\HR\Roster\Services\RosterPageService;
use App\Domain\HR\Roster\Services\RosterService;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/** HR-1 roster planner. Thin: every rule lives in app/Domain/HR/Roster. */
class RosterController extends Controller
{
    private const SHIFT_FIELDS = ['expected_version_lock', 'staff_user_id', 'date', 'start_time', 'end_time', 'break_minutes', 'session_type', 'branch_room_id', 'idempotency_key'];

    public function index(Request $request, RosterPageService $pages): Response
    {
        return Inertia::render('HR/Roster', ['roster' => $pages->page($request->user(), $request->only(['tab', 'branch', 'week', 'view']))]);
    }

    public function start(Request $request, RosterService $roster): RedirectResponse
    {
        $back = $this->back($request, 'planner');
        try {
            $result = $roster->startDraft($request->user(), $request->only(['branch_id', 'week', 'mode', 'expected_week_lock_version', 'idempotency_key']));
        } catch (ValidationException $e) {
            throw $e->redirectTo($back);
        }
        if ($result['skipped'] !== []) {
            Inertia::flash('rosterSkipped', $result['skipped']);
        }
        $mode = $request->input('mode');
        Inertia::flash('toast', ['type' => 'success', 'message' => $mode === 'copy' ? 'Draft started from the previous week.' : 'Draft started.']);

        return redirect($back);
    }

    public function addShift(Request $request, RosterService $roster, string $version): RedirectResponse
    {
        return $this->run($request, 'Shift added.', fn () => $roster->addShift($request->user(), $version, $request->only(self::SHIFT_FIELDS)));
    }

    public function updateShift(Request $request, RosterService $roster, string $version, string $shift): RedirectResponse
    {
        return $this->run($request, 'Shift updated.', fn () => $roster->updateShift($request->user(), $version, $shift, $request->only(self::SHIFT_FIELDS)));
    }

    public function removeShift(Request $request, RosterService $roster, string $version, string $shift): RedirectResponse
    {
        return $this->run($request, 'Shift removed.', fn () => $roster->removeShift($request->user(), $version, $shift, $request->only(['expected_version_lock'])));
    }

    public function submit(Request $request, RosterService $roster, string $version): RedirectResponse
    {
        return $this->run($request, 'Week sent for approval.', fn () => $roster->submit($request->user(), $version, $request->only(['expected_version_lock', 'idempotency_key'])));
    }

    public function approve(Request $request, RosterService $roster, string $version): RedirectResponse
    {
        return $this->run($request, 'Week approved.', fn () => $roster->approve($request->user(), $version, $request->only(['expected_version_lock', 'idempotency_key'])));
    }

    public function returnToDraft(Request $request, RosterService $roster, string $version): RedirectResponse
    {
        return $this->run($request, 'Week returned to draft.', fn () => $roster->returnToDraft($request->user(), $version, $request->only(['expected_version_lock', 'reason', 'idempotency_key'])));
    }

    public function discard(Request $request, RosterService $roster, string $version): RedirectResponse
    {
        return $this->run($request, 'Draft discarded.', fn () => $roster->discard($request->user(), $version, $request->only(['expected_version_lock', 'idempotency_key'])));
    }

    public function minimums(Request $request, MinimumStaffingService $service): RedirectResponse
    {
        $back = $this->back($request, 'minimums');
        try {
            $service->save($request->user(), $request->only(['branch_id', 'minimums', 'expected_lock_version']));
        } catch (ValidationException $e) {
            throw $e->redirectTo($back);
        }
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Minimum staffing saved.']);

        return redirect($back);
    }

    private function run(Request $request, string $message, callable $action): RedirectResponse
    {
        $back = $this->back($request, 'planner');
        try {
            $action();
        } catch (ValidationException $e) {
            throw $e->redirectTo($back);
        }
        Inertia::flash('toast', ['type' => 'success', 'message' => $message]);

        return redirect($back);
    }

    /** Return to the same tab, branch and week (validated shapes only). */
    private function back(Request $request, string $tab): string
    {
        $query = ['tab' => $tab];
        $branch = filter_var($request->input('branch_id'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($branch !== false) {
            $query['branch'] = $branch;
        }
        $week = $request->input('week');
        if (is_string($week) && preg_match('/\A\d{4}-\d{2}-\d{2}\z/', $week) === 1) {
            $query['week'] = $week;
        }

        return route('hr.roster.index', $query);
    }
}
