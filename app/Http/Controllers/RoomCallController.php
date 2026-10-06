<?php

namespace App\Http\Controllers;

use App\Domain\Clinical\Dispensary\Models\DispensaryCase;
use App\Domain\Queue\Display\RoomCallService;
use App\Domain\Visit\Models\Visit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class RoomCallController extends Controller
{
    public function dispensary(Request $request, DispensaryCase $dispensaryCase, RoomCallService $calls): RedirectResponse
    {
        $target = route('dispensary.show', $dispensaryCase);

        try {
            $validated = Validator::make($request->all(), [
                'expected_branch_id' => ['required', 'integer'],
                'branch_room_id' => ['nullable', 'integer'],
            ])->validate();
            $calls->callToDispensary(
                $request->user(),
                $dispensaryCase,
                isset($validated['branch_room_id']) ? (int) $validated['branch_room_id'] : null,
                $validated,
            );
        } catch (ValidationException $exception) {
            throw $exception->redirectTo($target);
        }
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Patient called to the dispensary on the TV.')]);

        return redirect($target);
    }

    public function treatment(Request $request, Visit $visit, RoomCallService $calls): RedirectResponse
    {
        // Return to the page it was pressed on: the queue board or the consultation.
        $target = $request->input('from') === 'consultation'
            ? route('encounters.show', $visit->visit_number)
            : route('queue.index');

        try {
            $validated = Validator::make($request->all(), [
                'expected_branch_id' => ['required', 'integer'],
                'queue_lock_version' => ['required', 'integer', 'min:1'],
                'branch_room_id' => ['required', 'integer'],
            ])->validate();
            $calls->callToTreatment($request->user(), $visit, (int) $validated['branch_room_id'], $validated);
        } catch (ValidationException $exception) {
            throw $exception->redirectTo($target);
        }
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Patient called to the treatment room on the TV.')]);

        return redirect($target);
    }
}
