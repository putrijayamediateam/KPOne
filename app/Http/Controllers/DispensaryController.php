<?php

namespace App\Http\Controllers;

use App\Domain\Clinical\Dispensary\Models\DispensaryCase;
use App\Domain\Clinical\Dispensary\Models\DispensaryItem;
use App\Domain\Clinical\Dispensary\Models\DispensaryItemException;
use App\Domain\Clinical\Dispensary\Services\DispensaryDirectoryService;
use App\Domain\Clinical\Dispensary\Services\DispensaryService;
use App\Http\Requests\AcknowledgeDispensaryPartialRequest;
use App\Http\Requests\DispensaryCaseActionRequest;
use App\Http\Requests\UpdateDispensaryItemRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DispensaryController extends Controller
{
    public function show(Request $request, DispensaryCase $dispensaryCase, DispensaryDirectoryService $directory): Response
    {
        return Inertia::render('Dispensary/Show', ['dispensary' => $directory->detail($request->user(), $dispensaryCase)]);
    }

    public function start(DispensaryCaseActionRequest $request, DispensaryCase $dispensaryCase, DispensaryService $service): RedirectResponse
    {
        $service->start($request->user(), $dispensaryCase, $request->validated());
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Dispensing started.']);

        return to_route('dispensary.show', $dispensaryCase);
    }

    public function updateItem(UpdateDispensaryItemRequest $request, DispensaryCase $dispensaryCase, DispensaryItem $item, DispensaryService $service): RedirectResponse
    {
        $service->updateItem($request->user(), $dispensaryCase, $item, $request->validated());
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Fulfilment updated.']);

        return to_route('dispensary.show', $dispensaryCase);
    }

    public function returnToDoctor(DispensaryCaseActionRequest $request, DispensaryCase $dispensaryCase, DispensaryService $service): RedirectResponse
    {
        $service->returnToDoctor($request->user(), $dispensaryCase, $request->validated());
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Case returned to the attending doctor.']);

        return to_route('registration.index');
    }

    public function complete(DispensaryCaseActionRequest $request, DispensaryCase $dispensaryCase, DispensaryService $service): RedirectResponse
    {
        $service->complete($request->user(), $dispensaryCase, $request->validated());
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Dispensary completed.']);

        return to_route('registration.index');
    }

    public function acknowledge(AcknowledgeDispensaryPartialRequest $request, DispensaryItemException $exception, DispensaryService $service): RedirectResponse
    {
        $service->acknowledge($request->user(), $exception, $request->validated());
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Partial fulfilment acknowledged.']);

        return back();
    }
}
