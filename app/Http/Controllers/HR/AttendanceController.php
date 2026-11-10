<?php

namespace App\Http\Controllers\HR;

use App\Domain\HR\Attendance\Services\AttendancePageService;
use App\Domain\HR\Attendance\Services\AttendanceSettingsService;
use App\Domain\HR\Attendance\Services\ClockService;
use App\Domain\HR\Attendance\Services\CorrectionService;
use App\Domain\HR\Attendance\Services\LocationCheckService;
use App\Domain\HR\Attendance\Services\OvertimeService;
use App\Domain\HR\Attendance\Services\OvertimeSettingsService;
use App\Domain\HR\Credentials\Services\CredentialsPageService;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/** HR-3a attendance and HR-3b overtime. Thin: every rule lives in app/Domain/HR/Attendance. */
class AttendanceController extends Controller
{
    public function index(Request $request, AttendancePageService $pages): Response
    {
        return Inertia::render('HR/Attendance', ['attendance' => $pages->page($request->user(), $request->only(['tab', 'branch', 'date', 'month', 'period'])),
            // HR-5a: your own expiring or expired credentials, in words; inside KPOne only.
            'credentialReminders' => app(CredentialsPageService::class)->reminders($request->user())]);
    }

    public function clockIn(Request $request, ClockService $clock): RedirectResponse
    {
        $label = null;
        $response = $this->run($request, 'my', null, function () use ($request, $clock, &$label): void {
            $session = $clock->clockIn($request->user(), $this->pressInput($request));
            $label = 'Clocked in at '.$session->clock_in_at->setTimezone($session->timezone)->format('g:i A').'.';
        });
        Inertia::flash('toast', ['type' => 'success', 'message' => $label]);

        return $response;
    }

    public function clockOut(Request $request, ClockService $clock): RedirectResponse
    {
        $label = null;
        $response = $this->run($request, 'my', null, function () use ($request, $clock, &$label): void {
            $session = $clock->clockOut($request->user(), $this->pressInput($request));
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

    /**
     * HR-16a: the press with its optional location reading. The position is passed straight to the domain and never
     * flashed (`clock_position` is in dontFlash), logged or stored.
     *
     * @return array<string, mixed>
     */
    private function pressInput(Request $request): array
    {
        $position = $request->input('clock_position');

        return ['idempotency_key' => $request->input('idempotency_key'), 'location_consent' => $request->boolean('location_consent'),
            'location_unavailable' => $request->input('location_unavailable'),
            'clock_position' => is_array($position) ? array_intersect_key($position, array_flip(['latitude', 'longitude', 'accuracy'])) : null];
    }

    public function saveLocationCheck(Request $request, LocationCheckService $location): RedirectResponse
    {
        $on = $request->boolean('enabled');

        return $this->run($request, 'settings', $on ? 'Location check saved.' : 'Location check turned off.', fn () => $location->save($request->user(),
            $request->only(['branch_id', 'enabled', 'centre_lat', 'centre_lng', 'radius_metres', 'consent_en', 'consent_bm', 'idempotency_key'])));
    }

    public function saveSettings(Request $request, AttendanceSettingsService $settings): RedirectResponse
    {
        return $this->run($request, 'settings', 'Attendance settings saved.', fn () => $settings->save($request->user(),
            $request->only(['grace_minutes', 'forgotten_window_minutes', 'correction_days', 'effective_from', 'idempotency_key'])));
    }

    public function askOvertime(Request $request, OvertimeService $overtime): RedirectResponse
    {
        $tab = $request->input('tab') === 'review' ? 'review' : 'my';

        // From Review overtime is always asked for someone named, never the reviewer's own (as corrections).
        return $this->run($request, $tab, 'Overtime request sent.', fn () => $overtime->ask($request->user(),
            [...$request->only(['user_id', 'session_id', 'minutes', 'reason_code', 'note', 'idempotency_key']), 'on_behalf' => $tab === 'review']));
    }

    public function decideOvertime(Request $request, OvertimeService $overtime, string $overtimeRequest, string $decision): RedirectResponse
    {
        return $this->run($request, 'overtime', $decision === 'approve' ? 'Overtime approved.' : 'Overtime rejected.',
            fn () => $overtime->decide($request->user(), $overtimeRequest, $decision, $request->only(['expected_lock_version', 'minutes', 'reason', 'idempotency_key'])));
    }

    public function withdrawOvertime(Request $request, OvertimeService $overtime, string $overtimeRequest): RedirectResponse
    {
        $tab = $request->input('tab') === 'overtime' ? 'overtime' : 'my';

        return $this->run($request, $tab, 'Overtime request withdrawn.', fn () => $overtime->withdraw($request->user(), $overtimeRequest, $request->only(['expected_lock_version', 'idempotency_key'])));
    }

    public function reverseOvertime(Request $request, OvertimeService $overtime, string $overtimeRequest): RedirectResponse
    {
        return $this->run($request, 'overtime', 'Overtime reversed.', fn () => $overtime->reverse($request->user(), $overtimeRequest,
            $request->only(['expected_lock_version', 'reason', 'idempotency_key'])));
    }

    public function saveOvertimeSettings(Request $request, OvertimeSettingsService $settings): RedirectResponse
    {
        return $this->run($request, 'settings', 'Overtime settings saved.', fn () => $settings->save($request->user(),
            [...$request->only(['normal_multiplier', 'rest_day_multiplier', 'public_holiday_multiplier', 'nearing_hours', 'limit_hours', 'idempotency_key']),
                'roles' => $request->input('roles', []), 'effective_from' => $request->input('overtime_effective_from')]));
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

        $month = $request->input('overtime_month');
        if ($tab === 'overtime' && is_string($month) && preg_match('/\A\d{4}-\d{2}\z/', $month) === 1) {
            $query['month'] = $month;
        }

        return route('hr.attendance.index', $query);
    }
}
