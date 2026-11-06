<?php

namespace App\Http\Controllers\HR;

use App\Domain\HR\Attendance\Services\AttendancePageService;
use App\Domain\HR\Attendance\Services\AttendanceSettingsService;
use App\Domain\HR\Attendance\Services\ClockService;
use App\Domain\HR\Attendance\Services\CorrectionService;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/** HR-3a attendance. Thin: every rule lives in app/Domain/HR/Attendance. */
class AttendanceController extends Controller
{
    public function index(Request $request, AttendancePageService $pages): Response
    {
        return Inertia::render('HR/Attendance', ['attendance' => $pages->page($request->user(), $request->only(['tab', 'branch', 'date']))]);
    }

    public function clockIn(Request $request, ClockService $clock): RedirectResponse
    {
        $label = null;
        $response = $this->run($request, 'my', null, function () use ($request, $clock, &$label): void {
            $session = $clock->clockIn($request->user(), $request->only(['idempotency_key']));
            $label = 'Clocked in at '.$session->clock_in_at->setTimezone($session->timezone)->format('g:i A').'.';
        });
        Inertia::flash('toast', ['type' => 'success', 'message' => $label]);

        return $response;
    }

    public function clockOut(Request $request, ClockService $clock): RedirectResponse
    {
        $label = null;
        $response = $this->run($request, 'my', null, function () use ($request, $clock, &$label): void {
            $session = $clock->clockOut($request->user(), $request->only(['idempotency_key']));
            $label = 'Clocked out at '.$session->clock_out_at?->setTimezone($session->timezone)->format('g:i A').'.';
        });
        Inertia::flash('toast', ['type' => 'success', 'message' => $label]);

        return $response;
    }

    public function propose(Request $request, CorrectionService $corrections): RedirectResponse
    {
        $tab = $request->input('tab') === 'review' ? 'review' : 'my';

        // From Review a correction is always for someone named; it never falls back to the reviewer's own times (Suresh).
        return $this->run($request, $tab, 'Correction sent for approval.', fn () => $corrections->propose($request->user(),
            [...$request->only(['user_id', 'kind', 'session_id', 'branch_id', 'in_time', 'out_time', 'reason_code', 'note', 'idempotency_key']), 'on_behalf' => $tab === 'review']));
    }

    public function decide(Request $request, CorrectionService $corrections, string $correction, string $decision): RedirectResponse
    {
        return $this->run($request, 'review', $decision === 'approve' ? 'Correction approved.' : 'Correction rejected.',
            fn () => $corrections->decide($request->user(), $correction, $decision, $request->only(['expected_lock_version', 'reason', 'idempotency_key'])));
    }

    public function cancel(Request $request, CorrectionService $corrections, string $correction): RedirectResponse
    {
        $tab = $request->input('tab') === 'review' ? 'review' : 'my';

        return $this->run($request, $tab, 'Correction withdrawn.', fn () => $corrections->cancel($request->user(), $correction, $request->only(['expected_lock_version', 'idempotency_key'])));
    }

    public function saveSettings(Request $request, AttendanceSettingsService $settings): RedirectResponse
    {
        return $this->run($request, 'settings', 'Attendance settings saved.', fn () => $settings->save($request->user(),
            $request->only(['grace_minutes', 'forgotten_window_minutes', 'correction_days', 'effective_from', 'idempotency_key'])));
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

    /** Return to the same tab, branch and day (validated shapes only). */
    private function back(Request $request, string $tab): string
    {
        $query = ['tab' => $tab];
        $branch = filter_var($request->input('review_branch'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($tab === 'review' && $branch !== false) {
            $query['branch'] = $branch;
        }
        $date = $request->input('review_date');
        if ($tab === 'review' && is_string($date) && preg_match('/\A\d{4}-\d{2}-\d{2}\z/', $date) === 1) {
            $query['date'] = $date;
        }

        return route('hr.attendance.index', $query);
    }
}
