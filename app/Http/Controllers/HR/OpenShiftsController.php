<?php

namespace App\Http\Controllers\HR;

use App\Domain\Audit\AuditRecorder;
use App\Domain\HR\OpenShifts\Services\OpenShiftService;
use App\Domain\HR\OpenShifts\Services\OpenShiftsPageService;
use App\Http\Controllers\Controller;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/** HR-15b open shifts. Thin: every rule lives in app/Domain/HR/OpenShifts. */
class OpenShiftsController extends Controller
{
    public function index(Request $request, OpenShiftsPageService $pages): Response
    {
        return Inertia::render('HR/OpenShifts', ['openShifts' => $pages->page($request->user(), $request->only(['tab']))]);
    }

    public function store(Request $request, OpenShiftService $shifts): RedirectResponse
    {
        return $this->run($request, 'open', 'Open shift posted.', fn () => $shifts->post($request->user(),
            $request->only(['branch_id', 'date', 'start_time', 'end_time', 'break_minutes', 'session_type', 'role_needed', 'idempotency_key'])));
    }

    public function withdraw(Request $request, OpenShiftService $shifts, string $shift): RedirectResponse
    {
        return $this->run($request, 'open', 'Open shift withdrawn.', fn () => $shifts->withdraw($request->user(), $shift,
            $request->only(['expected_lock_version', 'idempotency_key'])));
    }

    public function ask(Request $request, OpenShiftService $shifts, string $shift): RedirectResponse
    {
        return $this->run($request, 'mine', 'Asked. HR chooses who takes the shift.', fn () => $shifts->ask($request->user(), $shift,
            $request->only(['idempotency_key'])));
    }

    public function withdrawAsk(Request $request, OpenShiftService $shifts, string $ask): RedirectResponse
    {
        return $this->run($request, 'mine', 'Ask withdrawn.', fn () => $shifts->withdrawAsk($request->user(), $ask,
            $request->only(['expected_lock_version', 'idempotency_key'])));
    }

    public function confirm(Request $request, OpenShiftService $shifts, string $ask): RedirectResponse
    {
        return $this->run($request, 'open', 'Confirmed. The shift is in the draft roster; approve the week as usual.', fn () => $shifts->confirm($request->user(), $ask,
            $request->only(['expected_lock_version', 'idempotency_key'])));
    }

    public function decline(Request $request, OpenShiftService $shifts, string $ask): RedirectResponse
    {
        return $this->run($request, 'open', 'Ask declined.', fn () => $shifts->decline($request->user(), $ask,
            $request->only(['reason', 'expected_lock_version', 'idempotency_key'])));
    }

    private function run(Request $request, string $tab, string $message, callable $action): RedirectResponse
    {
        $back = route('hr.open-shifts.index', ['tab' => $tab]);
        try {
            $action();
        } catch (ValidationException $e) {
            throw $e->redirectTo($back);
        } catch (AuthorizationException $e) {
            app(AuditRecorder::class)->record('authorization.denied', $request->user(), ['route' => $request->route()?->getName(), 'method' => $request->method()], $request->user());
            throw ValidationException::withMessages(['open_shift' => $e->getMessage() !== '' ? $e->getMessage() : 'You may not do this.'])->redirectTo($back);
        }
        Inertia::flash('toast', ['type' => 'success', 'message' => $message]);

        return redirect($back);
    }
}
