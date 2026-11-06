<?php

namespace App\Http\Controllers\HR;

use App\Domain\HR\Leave\Services\LeaveEntryService;
use App\Domain\HR\Leave\Services\LeaveEvidenceFiles;
use App\Domain\HR\Leave\Services\LeaveEvidenceService;
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
use Ramsey\Uuid\Uuid;

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

    public function apply(Request $request, LeaveRequestService $leave, LeaveEvidenceService $evidence): RedirectResponse
    {
        $back = $this->back($request, 'my');
        // HR-2b: every attached file is checked before the request is created, so a bad file changes nothing.
        $files = array_values(array_filter((array) $request->file('evidence_files', [])));
        try {
            if (count($files) > LeaveEvidenceService::MAX_FILES) {
                throw ValidationException::withMessages(['evidence_files' => 'This request already has 3 files.']);
            }
            $prepared = array_map(fn ($file) => LeaveEvidenceFiles::prepare($file, 'evidence_files'), $files);
            $created = $leave->apply($request->user(), $request->only(self::APPLY_FIELDS));
        } catch (ValidationException $e) {
            throw $e->redirectTo($back);
        }
        try {
            foreach ($prepared as $i => $file) {
                // A key derived from the request's own key keeps a retried submit from attaching a file twice.
                $evidence->add($request->user(), $created->public_id, $file,
                    ['idempotency_key' => Uuid::uuid5(Uuid::NAMESPACE_URL, 'kpone-hr-leave-evidence:'.$request->input('idempotency_key').':'.$i)->toString()], 'evidence_files');
            }
        } catch (ValidationException $e) {
            Inertia::flash('toast', ['type' => 'success', 'message' => 'Leave request sent. Add the file again from My leave.']);
            throw $e->redirectTo($back);
        }
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Leave request sent.']);

        return redirect($back);
    }

    public function addEvidence(Request $request, LeaveEvidenceService $evidence, string $leaveRequest): RedirectResponse
    {
        $tab = $request->input('tab') === 'approvals' ? 'approvals' : 'my';

        return $this->run($request, $tab, 'File added.', fn () => $evidence->add($request->user(), $leaveRequest,
            LeaveEvidenceFiles::prepare($request->file('file')), $request->only(['idempotency_key'])));
    }

    public function removeEvidence(Request $request, LeaveEvidenceService $evidence, string $file): RedirectResponse
    {
        $tab = $request->input('tab') === 'approvals' ? 'approvals' : 'my';

        return $this->run($request, $tab, 'File removed.', fn () => $evidence->remove($request->user(), $file, $request->only(['expected_lock_version', 'reason', 'idempotency_key'])));
    }

    /** Streams one evidence file after recording the open. A PDF is always a download, never shown inside KPOne. */
    public function openEvidence(Request $request, LeaveEvidenceService $evidence, string $file): \Illuminate\Http\Response
    {
        $opened = $evidence->open($request->user(), $file);

        return response($opened['content'], 200, [
            'Content-Type' => $opened['mime'],
            'Content-Disposition' => ($opened['inline'] ? 'inline' : 'attachment').'; filename="'.$opened['filename'].'"',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "sandbox; default-src 'none'",
            'Cache-Control' => 'no-store, private',
        ]);
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
            'is_paid', 'uses_balance', 'allow_half_days', 'evidence_needed', 'allow_negative', 'carry_max_days', 'carry_expires_on', 'is_active', 'accepts_evidence_files', 'evidence_retention_days'])));
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
