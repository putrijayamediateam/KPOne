<?php

namespace App\Http\Controllers\HR;

use App\Domain\HR\Leave\Services\LeaveEntryService;
use App\Domain\HR\Leave\Services\LeavePageService;
use App\Domain\HR\Leave\Services\LeaveRequestService;
use App\Domain\HR\Leave\Services\LeaveSettingsService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/** HR-2a leave. Thin: every rule lives in app/Domain/HR/Leave. */
class LeaveController extends Controller
{
    private const APPLY_FIELDS = ['leave_type_id', 'start_date', 'end_date', 'start_part', 'end_part', 'reason', 'evidence_reference', 'idempotency_key'];

    public function index(Request $request, LeavePageService $pages): Response
    {
        return Inertia::render('HR/Leave', ['leave' => $pages->page($request->user(), $request->only(['tab', 'branch', 'year', 'month']))]);
    }

    public function preview(Request $request, LeaveRequestService $leave): JsonResponse
    {
        try {
            return response()->json($leave->preview($request->user(), $request->only(self::APPLY_FIELDS)));
        } catch (ValidationException $e) {
            return response()->json(['errors' => array_map(fn (array $m): string => $m[0], $e->errors())], 422);
        }
    }

    public function apply(Request $request, LeaveRequestService $leave): RedirectResponse
    {
        return $this->run($request, 'my', 'Leave request sent.', fn () => $leave->apply($request->user(), $request->only(self::APPLY_FIELDS)));
    }

    public function approve(Request $request, LeaveRequestService $leave, string $leaveRequest): RedirectResponse
    {
        return $this->run($request, 'approvals', 'Leave approved.', fn () => $leave->approve($request->user(), $leaveRequest, $request->only(['expected_lock_version', 'evidence_seen', 'idempotency_key'])));
    }

    public function reject(Request $request, LeaveRequestService $leave, string $leaveRequest): RedirectResponse
    {
        return $this->run($request, 'approvals', 'Leave rejected.', fn () => $leave->reject($request->user(), $leaveRequest, $request->only(['expected_lock_version', 'reason', 'idempotency_key'])));
    }

    public function cancel(Request $request, LeaveRequestService $leave, string $leaveRequest): RedirectResponse
    {
        $tab = $request->input('tab') === 'approvals' ? 'approvals' : 'my';

        return $this->run($request, $tab, 'Leave request cancelled.', fn () => $leave->cancel($request->user(), $leaveRequest, $request->only(['expected_lock_version', 'reason', 'idempotency_key'])));
    }

    public function saveType(Request $request, LeaveSettingsService $settings): RedirectResponse
    {
        return $this->run($request, 'settings', 'Leave types saved.', fn () => $settings->saveType($request->user(), $request->only(['leave_type_id', 'expected_lock_version', 'code', 'name',
            'is_paid', 'uses_balance', 'allow_half_days', 'evidence_needed', 'allow_negative', 'carry_max_days', 'carry_expires_on', 'is_active'])));
    }

    public function saveEntitlements(Request $request, LeaveSettingsService $settings): RedirectResponse
    {
        return $this->run($request, 'settings', 'Entitlements saved.', fn () => $settings->saveEntitlements($request->user(), $request->only(['year', 'rows'])));
    }

    public function grant(Request $request, LeaveSettingsService $settings): RedirectResponse
    {
        $count = 0;
        $response = $this->run($request, 'settings', null, function () use ($request, $settings, &$count): void {
            $count = $settings->grant($request->user(), $request->only(['year', 'idempotency_key']));
        });
        Inertia::flash('toast', ['type' => 'success', 'message' => $count === 0 ? 'Entitlement already given. Nothing changed.' : "Entitlement given ({$count} ".($count === 1 ? 'line' : 'lines').').']);

        return $response;
    }

    public function carryForward(Request $request, LeaveSettingsService $settings): RedirectResponse
    {
        return $this->run($request, 'settings', 'Carry-forward done.', fn () => $settings->carryForward($request->user(), $request->only(['year', 'idempotency_key'])));
    }

    public function expire(Request $request, LeaveSettingsService $settings): RedirectResponse
    {
        return $this->run($request, 'settings', 'Carried days expired.', fn () => $settings->expire($request->user(), $request->only(['year', 'idempotency_key'])));
    }

    public function workingDays(Request $request, LeaveSettingsService $settings): RedirectResponse
    {
        return $this->run($request, 'settings', 'Working days saved.', fn () => $settings->saveWorkingDays($request->user(), $request->only(['branch_id', 'weekdays', 'expected_lock_version'])));
    }

    public function addHoliday(Request $request, LeaveSettingsService $settings): RedirectResponse
    {
        return $this->run($request, 'settings', 'Public holiday added.', fn () => $settings->addHoliday($request->user(), $request->only(['branch_id', 'holiday_date', 'name'])));
    }

    public function removeHoliday(Request $request, LeaveSettingsService $settings, string $holiday): RedirectResponse
    {
        return $this->run($request, 'settings', 'Public holiday removed.', fn () => $settings->removeHoliday($request->user(), $holiday));
    }

    public function enter(Request $request, LeaveEntryService $entries, string $kind): RedirectResponse
    {
        $kind = $kind === 'openings' ? 'opening' : 'adjustment';

        return $this->run($request, 'settings', $kind === 'opening' ? 'Opening balance sent for a check.' : 'Adjustment sent for a check.',
            fn () => $entries->enter($request->user(), $kind, $request->only(['user_id', 'leave_type_id', 'year', 'days', 'reason_code', 'note', 'idempotency_key'])));
    }

    public function decide(Request $request, LeaveEntryService $entries, string $kind, string $entry, string $decision): RedirectResponse
    {
        $kind = $kind === 'openings' ? 'opening' : 'adjustment';
        $confirmed = $decision === 'confirm';
        $message = ($kind === 'opening' ? 'Opening balance ' : 'Adjustment ').($confirmed ? 'confirmed.' : 'rejected.');

        return $this->run($request, 'settings', $message, fn () => $entries->decide($request->user(), $kind, $entry, $confirmed ? 'confirmed' : 'rejected', $request->only(['expected_lock_version', 'idempotency_key'])));
    }

    private function run(Request $request, string $tab, ?string $message, callable $action): RedirectResponse
    {
        $back = $this->back($request, $tab);
        try {
            $action();
        } catch (ValidationException $e) {
            throw $e->redirectTo($back);
        }
        if ($message !== null) {
            Inertia::flash('toast', ['type' => 'success', 'message' => $message]);
        }

        return redirect($back);
    }

    /** Return to the same tab, branch and year (validated shapes only). */
    private function back(Request $request, string $tab): string
    {
        $query = ['tab' => $tab];
        $branch = filter_var($request->input('branch_id'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($branch !== false) {
            $query['branch'] = $branch;
        }
        $year = filter_var($request->input('year'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 2000, 'max_range' => 2100]]);
        if ($year !== false) {
            $query['year'] = $year;
        }

        return route('hr.leave.index', $query);
    }
}
