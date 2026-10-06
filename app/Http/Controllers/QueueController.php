<?php

namespace App\Http\Controllers;

use App\Domain\Access\BranchAccessService;
use App\Domain\Queue\Display\DoctorRoomService;
use App\Domain\Queue\Display\RoomCallService;
use App\Domain\Queue\Models\BranchRoom;
use App\Domain\Queue\Models\QueueEntry;
use App\Domain\Queue\Services\QueueDirectoryService;
use App\Domain\Queue\Services\QueueEntryService;
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
    public function index(
        Request $request,
        QueueDirectoryService $directory,
        DoctorRoomService $rooms,
        RoomCallService $calls,
        BranchAccessService $branches,
    ): Response {
        $this->authorize('viewAny', QueueEntry::class);
        $branch = $branches->activeBranch($request->user());
        $canCall = $request->user()->can('queue.call.own') || $request->user()->can('queue.call.branch');

        return Inertia::render('Queue/Index', [
            'snapshot' => $directory->snapshot($request->user()),
            'roomChoice' => $rooms->choice($request->user()),
            'treatmentRooms' => $branch && $canCall ? $calls->rooms($branch, BranchRoom::KIND_TREATMENT) : [],
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
    ): RedirectResponse {
        $queue->enter($request->user(), $visit, $request->validated());
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Patient sent to Waiting.')]);

        return to_route('queue.index');
    }

    public function call(
        CallQueueEntryRequest $request,
        Visit $visit,
        QueueEntryService $queue,
    ): RedirectResponse {
        $queue->call($request->user(), $visit, $request->validated());
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Patient called in.')]);

        return to_route('queue.index');
    }
}
