<?php

namespace App\Http\Controllers;

use App\Domain\Visit\Models\Visit;
use App\Domain\Visit\Services\VisitAdministrationService;
use App\Domain\Visit\Services\VisitDirectoryService;
use App\Http\Requests\CancelVisitRequest;
use App\Http\Requests\UpdateVisitRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class VisitController extends Controller
{
    public function show(Request $request, Visit $visit, VisitDirectoryService $directory): Response
    {
        return Inertia::render('Registration/Show', [
            'visit' => $directory->detail($request->user(), $visit),
        ]);
    }

    public function edit(Request $request, Visit $visit, VisitDirectoryService $directory): Response
    {
        return Inertia::render('Registration/Edit', [
            'visit' => $directory->detail($request->user(), $visit),
            'options' => $directory->editOptions($request->user(), $visit),
        ]);
    }

    public function update(UpdateVisitRequest $request, Visit $visit, VisitAdministrationService $administration): RedirectResponse
    {
        $administration->update($visit, $request->validated(), $request->user());
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Visit updated.')]);

        return to_route('visits.show', $visit);
    }

    public function cancel(CancelVisitRequest $request, Visit $visit, VisitAdministrationService $administration): RedirectResponse
    {
        $administration->cancel($visit, $request->validated(), $request->user());
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Visit cancelled.')]);

        return to_route('visits.show', $visit);
    }
}
