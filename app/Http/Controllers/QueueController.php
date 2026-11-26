<?php

namespace App\Http\Controllers;

use App\Domain\Access\BranchAccessService;
use App\Domain\Clinical\TreatmentRoom\TreatmentRoomPermissions;
use App\Domain\Queue\Display\DoctorRoomService;
use App\Domain\Queue\Display\RoomCallService;
use App\Domain\Queue\Models\BranchRoom;
use App\Domain\Queue\Models\QueueCall;
use App\Domain\Queue\Models\QueueEntry;
use App\Domain\Queue\QueueNumberFormat;
use App\Domain\Queue\Services\CallNextService;
use App\Domain\Queue\Services\OtcQueueService;
use App\Domain\Queue\Services\QueueDirectoryService;
use App\Domain\Queue\Services\QueueEntryService;
use App\Domain\Queue\Services\QueueRowDetailsService;
use App\Domain\Queue\Services\QueueWaitingCountService;
use App\Domain\Queue\Services\RedZoneAlertService;
use App\Domain\Visit\Billing\Services\SalesTargetService;
use App\Domain\Visit\Models\Visit;
use App\Http\Requests\CallQueueEntryRequest;
use App\Http\Requests\SearchQueueEntriesRequest;
use App\Http\Requests\SendToWaitingRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class QueueController extends Controller
{
    public function waitingCounts(Request $request, QueueWaitingCountService $counts): JsonResponse
    {
        $validated = $request->validate(['expected_branch_id' => ['required', 'integer', 'min:1'], 'include_readiness' => ['sometimes', 'boolean'], 'scope' => ['sometimes', 'in:own,branch']]);

        return response()->json($counts->counts($request->user(), (int) $validated['expected_branch_id'], $request->boolean('include_readiness'), $validated['scope'] ?? 'own'));
    }

    public function index(
        Request $request,
        QueueDirectoryService $directory,
        DoctorRoomService $rooms,
        RoomCallService $calls,
        BranchAccessService $branches,
        SalesTargetService $salesTargets,
    ): Response {
        $this->authorize('viewAny', QueueEntry::class);
        $branch = $branches->activeBranch($request->user());
        $canCall = $request->user()->can('queue.call.own') || $request->user()->can('queue.call.branch')
            || $request->user()->can(TreatmentRoomPermissions::TREATMENT_CALL_BRANCH);

        return Inertia::render('Queue/Index', [
            'snapshot' => $directory->snapshot($request->user()),
            'roomChoice' => $rooms->choice($request->user()),
            'treatmentRooms' => $branch && $canCall ? $calls->rooms($branch, BranchRoom::KIND_TREATMENT) : [],
            // BIL-30 (owner P7, answer 2): only the signed-in doctor's own target; null when none is set or allowed.
            'mySalesTarget' => $salesTargets->own($request->user()),
        ]);
    }

    public function recall(Request $request, Visit $visit, QueueEntryService $queue): RedirectResponse
    {
        // Return to the page the doctor pressed it on: the queue board or the consultation.
        $target = $request->input('from') === 'consultation'
            ? route('encounters.show', $visit->visit_number)
            : route('queue.index');

        try {
            $queue->recall($request->user(), $visit, Validator::make($request->all(), [
                'expected_branch_id' => ['required', 'integer'],
                'queue_lock_version' => ['required', 'integer', 'min:1'],
            ])->validate());
        } catch (ValidationException $exception) {
            throw $exception->redirectTo($target);
        }
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Patient called again on the TV.')]);

        return redirect($target);
    }

    public function room(Request $request, DoctorRoomService $rooms): RedirectResponse
    {
        try {
            $validated = Validator::make($request->all(), [
                'branch_room_id' => ['present', 'nullable', 'integer'],
                'expected_branch_id' => ['required', 'integer'],
            ])->validate();
            $room = $rooms->select(
                $request->user(),
                $validated['branch_room_id'] === null ? null : (int) $validated['branch_room_id'],
                (int) $validated['expected_branch_id'],
            );
        } catch (ValidationException $exception) {
            throw $exception->redirectTo(route('queue.index'));
        }
        Inertia::flash('toast', ['type' => 'success', 'message' => $room
            ? __('Your room today: :room.', ['room' => $room->name])
            : __('Room cleared for today.')]);

        return to_route('queue.index');
    }

    public function search(SearchQueueEntriesRequest $request, QueueDirectoryService $directory): JsonResponse
    {
        return response()->json($directory->poll($request->user(), $request->validated()));
    }

    public function store(
        SendToWaitingRequest $request,
        Visit $visit,
        QueueEntryService $queue,
        OtcQueueService $otc,
    ): RedirectResponse {
        if ($visit->visit_type === 'otc') {
            // DS-02a: an OTC patient waits in the OTC list (B series), not the consultation queue.
            try {
                $otc->enter($request->user(), $visit, $request->validated());
            } catch (ValidationException $exception) {
                throw $exception->redirectTo(route('registration.index'));
            }
            Inertia::flash('toast', ['type' => 'success', 'message' => __('OTC patient sent to Waiting.')]);

            return to_route('registration.index');
        }
        $target = $request->validated('from') === 'registration' ? route('registration.index') : route('queue.index');
        try {
            $queue->enter($request->user(), $visit, $request->validated());
        } catch (ValidationException $exception) {
            throw $exception->redirectTo($target);
        }
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Patient sent to Waiting.')]);

        return redirect($target);
    }

    public function call(
        CallQueueEntryRequest $request,
        Visit $visit,
        QueueEntryService $queue,
    ): RedirectResponse {
        // A refused Call In (for example, the doctor has not chosen a room) must come back to the page it was
        // pressed on, not to whatever full page the session last recorded.
        $target = $request->validated('from') === 'registration'
            ? route('registration.index')
            : route('queue.index');

        try {
            $queue->call($request->user(), $visit, $request->validated());
        } catch (ValidationException $exception) {
            throw $exception->redirectTo($target);
        }
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Patient called in.')]);

        return to_route('queue.index');
    }

    /**
     * Q-16c: the details panel of a board row. Read only; opened and audited by QueueRowDetailsService, never inside a transaction.
     * HEAD records nothing: Laravel would route it here, so it is refused.
     */
    public function details(Request $request, Visit $visit, QueueRowDetailsService $details): JsonResponse
    {
        abort_if($request->isMethod('HEAD'), 405);

        return response()->json(['details' => $details->open($request->user(), $visit)]);
    }

    /** Q-16b: the red-zone alerts for resident doctors and triage recorders. Read only, no patient data. */
    public function redZoneAlerts(Request $request, RedZoneAlertService $alerts): JsonResponse
    {
        $validated = $request->validate(['expected_branch_id' => ['required', 'integer', 'min:1']]);

        return response()->json(['alerts' => $alerts->alerts($request->user(), (int) $validated['expected_branch_id'])]);
    }

    /** Q-16b: "I'll take it". Assigns the red-zone patient to the taking doctor; never calls the patient. */
    public function takeRedZone(Request $request, Visit $visit, RedZoneAlertService $alerts): RedirectResponse
    {
        $validated = $request->validate(['expected_branch_id' => ['required', 'integer', 'min:1'], 'from' => ['sometimes', 'string', 'max:20'], 'from_visit' => ['sometimes', 'string', 'max:40']]);
        try {
            $alerts->take($request->user(), $visit, (int) $validated['expected_branch_id']);
        } catch (ValidationException $exception) {
            // A doctor who took it from an open consultation stays on that consultation (never lose unsaved work).
            throw $exception->redirectTo($this->callNextRefusalTarget($request));
        }
        Inertia::flash('toast', ['type' => 'success', 'message' => __('You took this patient. Call them in when ready.')]);

        return redirect()->to($this->callNextRefusalTarget($request));
    }

    /** Q-4b: the doctor's own Call next block, for the held consultation page. Read only. */
    public function callNextBlock(Request $request, CallNextService $callNext): JsonResponse
    {
        $validated = $request->validate(['expected_branch_id' => ['required', 'integer', 'min:1']]);

        return response()->json(['callNext' => $callNext->ownBlock($request->user(), (int) $validated['expected_branch_id'])]);
    }

    /** Q-4: call the confirmed patient if they are still the doctor's next eligible patient. */
    public function callNext(Request $request, CallNextService $callNext): RedirectResponse
    {
        try {
            $entry = $callNext->call($request->user(), $request->all());
        } catch (ValidationException $exception) {
            // Q-4b: a refusal from a held consultation returns to it, so the dialog can show the latest patient.
            throw $exception->redirectTo($this->callNextRefusalTarget($request));
        }
        $room = QueueCall::query()->where('queue_entry_id', $entry->id)->latest('id')->value('room_name');
        Inertia::flash('toast', ['type' => 'success', 'message' => $room
            ? __('Called :number to :room.', ['number' => QueueNumberFormat::format($entry->queue_number), 'room' => $room])
            : __('Called :number to the counter.', ['number' => QueueNumberFormat::format($entry->queue_number)])]);

        return to_route('queue.index');
    }

    /** Q-4b: only the actor's own consultation may be the return page; anything else goes to the queue board. */
    private function callNextRefusalTarget(Request $request): string
    {
        $from = $request->input('from');
        $fromVisit = $request->input('from_visit');
        if ($from !== 'consultation' || ! is_string($fromVisit) || $fromVisit === '' || strlen($fromVisit) > 40) {
            return route('queue.index');
        }
        $actor = $request->user();
        $visitNumber = Visit::query()
            ->where('organisation_id', $actor->organisation_id)
            ->where('assigned_doctor_user_id', $actor->id)
            ->where('visit_number', $fromVisit)
            ->value('visit_number');

        return is_string($visitNumber) ? route('encounters.show', $visitNumber) : route('queue.index');
    }
}
