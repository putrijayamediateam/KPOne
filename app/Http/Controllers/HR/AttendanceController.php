<?php

namespace App\Http\Controllers\HR;

use App\Domain\DepartmentSpace\MeSpace;
use App\Domain\HR\Attendance\Models\ClockSession;
use App\Domain\HR\Attendance\Photos\ClockPhotoFiles;
use App\Domain\HR\Attendance\Photos\ClockPhotoService;
use App\Domain\HR\Attendance\Services\AttendancePageService;
use App\Domain\HR\Attendance\Services\AttendanceSettingsService;
use App\Domain\HR\Attendance\Services\ClockResult;
use App\Domain\HR\Attendance\Services\ClockService;
use App\Domain\HR\Attendance\Services\CorrectionService;
use App\Domain\HR\Attendance\Services\LocationCheckService;
use App\Domain\HR\Attendance\Services\OvertimeService;
use App\Domain\HR\Attendance\Services\OvertimeSettingsService;
use App\Domain\HR\Credentials\Services\CredentialsPageService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
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

    /**
     * DS-12 and DS-13: the same state My attendance shows, for the top-bar shortcut and the Me home ring. Read only:
     * HR-PHOTO's camera dialog makes the press. The extra facts (clock-in time, shift, rostered minutes) come from the
     * same rows and the same Late rule as Review.
     */
    public function clockState(Request $request, MeSpace $me): JsonResponse
    {
        $my = $me->attendanceMine($request->user()) ?? [];

        return response()->json($me->ring($request->user(), $my) + [
            'label' => $my['clockedInLabel'] ?? $my['doneLabel'] ?? null,
            // HR-PHOTO: every press takes a live photo through the camera dialog, after the one-time notice.
            'photoRequired' => true,
            'photoNotice' => ['version' => ClockPhotoService::NOTICE_VERSION, 'seen' => app(ClockPhotoService::class)->noticeSeen($request->user())],
        ]);
    }

    public function clockIn(Request $request, ClockService $clock, ClockResult $result): RedirectResponse|JsonResponse
    {
        return $this->press($request, 'in', fn () => $clock->clockIn($request->user(), $this->pressInput($request)), $result);
    }

    public function clockOut(Request $request, ClockService $clock, ClockResult $result): RedirectResponse|JsonResponse
    {
        return $this->press($request, 'out', fn () => $clock->clockOut($request->user(), $this->pressInput($request)), $result);
    }

    /**
     * HR-PHOTO: the camera dialog asks for JSON and gets the confirmation (time and chip) or the 422 errors; the
     * Attendance page's own form keeps its redirect and toast.
     *
     * @param  callable(): ClockSession  $action
     */
    private function press(Request $request, string $kind, callable $action, ClockResult $result): RedirectResponse|JsonResponse
    {
        $session = null;
        $response = $this->run($request, 'my', null, function () use ($action, &$session): void {
            $session = $action();
        });
        /** @var ClockSession $session */
        $confirmation = $result->for($session, $kind);
        if ($request->wantsJson()) {
            return response()->json($confirmation, 200, ['Cache-Control' => 'no-store, private']);
        }
        Inertia::flash('toast', ['type' => 'success', 'message' => $confirmation['title'].' at '.$confirmation['time'].'.']);

        return $response;
    }

    /** HR-PHOTO: the person has read the current "About clock-in photos" notice. */
    public function photoNotice(Request $request, ClockPhotoService $photos): JsonResponse
    {
        $photos->markNoticeSeen($request->user(), $request->input('version'));

        return response()->json(['version' => ClockPhotoService::NOTICE_VERSION, 'seen' => true], 200, ['Cache-Control' => 'no-store, private']);
    }

    /** HR-PHOTO: when the camera opens, a token good for one press of that kind for five minutes. */
    public function capture(Request $request, ClockPhotoService $photos): JsonResponse
    {
        return response()->json($photos->issueToken($request->user(), (string) $request->input('kind')), 200, ['Cache-Control' => 'no-store, private']);
    }

    /** HR-PHOTO: your own photo, or (for a reviewer) someone else's, recorded before any byte is sent. */
    public function photo(Request $request, ClockPhotoService $photos, string $photo, string $size): \Illuminate\Http\Response
    {
        // The size is checked here, not in the route, so the cross-site refusal answers first for any address.
        abort_unless(in_array($size, ['full', 'thumb'], true), 404);
        $opened = $photos->open($request->user(), $photo, $size);

        return response($opened['content'], 200, [
            'Content-Type' => 'image/jpeg',
            'Content-Disposition' => 'inline; filename="clock-photo.jpg"',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "sandbox; default-src 'none'",
            'Cache-Control' => 'no-store, private',
        ]);
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

        // HR-PHOTO: the photo is checked and re-encoded when the press reaches it (after the branch and session checks);
        // the token proves the camera was just opened.
        return ['idempotency_key' => $request->input('idempotency_key'), 'location_consent' => $request->boolean('location_consent'),
            'photo_prepared' => fn (): array => ClockPhotoFiles::prepare($request->file('photo')), 'capture_token' => $request->input('capture_token'),
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
