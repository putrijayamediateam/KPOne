<?php

namespace App\Http\Controllers;

use App\Domain\Clinical\TreatmentRoom\TreatmentRoomDirectory;
use App\Domain\Clinical\TreatmentRoom\TreatmentRoomProjection;
use App\Domain\Clinical\TreatmentRoom\TreatmentRoomService;
use App\Domain\Visit\Models\Visit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class TreatmentRoomController extends Controller
{
    public function index(Request $request, TreatmentRoomDirectory $directory): Response
    {
        return Inertia::render('TreatmentRoom/Index', ['room' => $directory->snapshot($request->user())]);
    }

    public function show(Request $request, Visit $visit, TreatmentRoomDirectory $directory, TreatmentRoomProjection $projection): Response
    {
        $context = $directory->context($request->user(), $visit);
        $room = $directory->snapshot($request->user());

        return Inertia::render('TreatmentRoom/Show', [
            'room' => collect($room)->except('rows')->all(),
            'treatment' => $context,
            'records' => $projection->forVisit($request->user(), $visit, true),
        ]);
    }

    public function records(Request $request, Visit $visit, TreatmentRoomProjection $projection): JsonResponse
    {
        return response()->json(['data' => $projection->forVisit($request->user(), $visit), 'timezone' => $visit->branch->timezone]);
    }

    public function historyRecords(Request $request, Visit $historicalVisit, TreatmentRoomProjection $projection): JsonResponse
    {
        return response()->json(['data' => $projection->forVisit($request->user(), $historicalVisit, true), 'timezone' => $historicalVisit->branch->timezone]);
    }

    public function start(Request $request, Visit $visit, TreatmentRoomService $service): RedirectResponse
    {
        // Preserve unknown fields for the service's strict rejection; only Laravel's CSRF field is transport metadata.
        $service->start($request->user(), $visit, $request->except('_token'));

        return to_route('treatment-room.show', $visit);
    }

    public function record(Request $request, Visit $visit, TreatmentRoomService $service): RedirectResponse
    {
        $service->recordOutcome($request->user(), $visit, $request->except('_token'));

        return to_route('treatment-room.show', $visit);
    }

    public function correct(Request $request, Visit $visit, TreatmentRoomService $service): RedirectResponse
    {
        $service->correctOutcome($request->user(), $visit, $request->except('_token'));

        return to_route('treatment-room.show', $visit);
    }

    public function void(Request $request, Visit $visit, TreatmentRoomService $service): RedirectResponse
    {
        $service->voidWrongPatient($request->user(), $visit, $request->except('_token'));

        return to_route('treatment-room.show', $visit);
    }
}
