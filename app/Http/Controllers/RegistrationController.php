<?php

namespace App\Http\Controllers;

use App\Domain\Visit\Models\Visit;
use App\Domain\Visit\Services\VisitDirectoryService;
use App\Domain\Visit\Services\VisitRegistrationService;
use App\Http\Requests\SearchVisitsRequest;
use App\Http\Requests\StoreVisitRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class RegistrationController extends Controller
{
    public function index(Request $request, VisitDirectoryService $directory): Response
    {
        $this->authorize('viewAny', Visit::class);

        return Inertia::render('Registration/Index', [
            'visits' => $directory->search($request->user(), []),
            'options' => $directory->consoleOptions($request->user()),
            'canCreate' => $request->user()->can('create', Visit::class),
        ]);
    }

    public function search(SearchVisitsRequest $request, VisitDirectoryService $directory): JsonResponse
    {
        return response()->json($directory->search($request->user(), $request->validated()));
    }

    public function create(Request $request, VisitDirectoryService $directory): Response
    {
        $this->authorize('create', Visit::class);

        return Inertia::render('Registration/Create', [
            'options' => $directory->formOptions($request->user()),
            'recentPatients' => $directory->recentPatients($request->user()),
        ]);
    }

    public function store(StoreVisitRequest $request, VisitRegistrationService $registration): RedirectResponse
    {
        $visit = $registration->register($request->user(), $request->validated());
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Visit registered.')]);

        return to_route('visits.show', $visit);
    }
}
