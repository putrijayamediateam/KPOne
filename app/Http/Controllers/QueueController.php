<?php

namespace App\Http\Controllers;

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
use Inertia\Inertia;
use Inertia\Response;

class QueueController extends Controller
{
    public function index(Request $request, QueueDirectoryService $directory): Response
    {
        $this->authorize('viewAny', QueueEntry::class);

        return Inertia::render('Queue/Index', [
            'snapshot' => $directory->snapshot($request->user()),
        ]);
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
