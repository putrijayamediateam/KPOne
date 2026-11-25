<?php

namespace App\Http\Controllers\HR;

use App\Domain\Audit\AuditRecorder;
use App\Domain\HR\Availability\Services\LocumAvailabilityService;
use App\Http\Controllers\Controller;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/** HR-22f locum free times, on the Open shifts page's "Free times" tab. Thin: every rule lives in app/Domain/HR/Availability. */
class LocumAvailabilityController extends Controller
{
    public function propose(Request $request, LocumAvailabilityService $availability): RedirectResponse
    {
        return $this->run($request, 'Free time sent. HR chooses whether to use it.', fn () => $availability->propose($request->user(),
            $request->only(['branch_id', 'date', 'start_time', 'end_time', 'note', 'idempotency_key'])));
    }

    public function withdraw(Request $request, LocumAvailabilityService $availability, string $availability_id): RedirectResponse
    {
        return $this->run($request, 'Free time withdrawn.', fn () => $availability->withdraw($request->user(), $availability_id,
            $request->only(['expected_lock_version', 'idempotency_key'])));
    }

    public function use(Request $request, LocumAvailabilityService $availability, string $availability_id): RedirectResponse
    {
        return $this->run($request, 'Added to the draft roster. Approve the week as usual.', fn () => $availability->use($request->user(), $availability_id,
            $request->only(['start_time', 'end_time', 'break_minutes', 'session_type', 'branch_room_id', 'station', 'expected_lock_version', 'idempotency_key'])));
    }

    public function decline(Request $request, LocumAvailabilityService $availability, string $availability_id): RedirectResponse
    {
        return $this->run($request, 'Free time declined.', fn () => $availability->decline($request->user(), $availability_id,
            $request->only(['reason', 'expected_lock_version', 'idempotency_key'])));
    }

    private function run(Request $request, string $message, callable $action): RedirectResponse
    {
        $back = route('hr.open-shifts.index', ['tab' => 'free']);
        try {
            $action();
        } catch (ValidationException $e) {
            throw $e->redirectTo($back);
        } catch (AuthorizationException $e) {
            app(AuditRecorder::class)->record('authorization.denied', $request->user(), ['route' => $request->route()?->getName(), 'method' => $request->method()], $request->user());
            throw ValidationException::withMessages(['availability' => $e->getMessage() !== '' ? $e->getMessage() : 'You may not do this.'])->redirectTo($back);
        }
        Inertia::flash('toast', ['type' => 'success', 'message' => $message]);

        return redirect($back);
    }
}
