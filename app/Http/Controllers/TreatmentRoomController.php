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
        return Inertia::render('TreatmentRoom/Index', ['room' => $directory->snapshot($request->user(), $this->pages($request)['page'])]);
    }

    public function show(Request $request, Visit $visit, TreatmentRoomDirectory $directory, TreatmentRoomProjection $projection): Response
    {
        $context = $directory->context($request->user(), $visit, $this->pages($request)['steps_page']);
        $room = $directory->snapshot($request->user(), $this->pages($request)['page']);

        return Inertia::render('TreatmentRoom/Show', [
            'room' => collect($room)->except('rows')->all(),
            'treatment' => $context,
            'records' => $projection->forVisit($request->user(), $visit, true, $this->pages($request)['current_page'], $this->pages($request)['history_page']),
        ]);
    }

    public function records(Request $request, Visit $visit, TreatmentRoomProjection $projection): JsonResponse
    {
        return response()->json(['data' => $projection->forVisit($request->user(), $visit, false, $this->pages($request)['current_page'], $this->pages($request)['history_page']), 'timezone' => $visit->branch->timezone]);
    }

    public function historyRecords(Request $request, Visit $historicalVisit, TreatmentRoomProjection $projection): JsonResponse
    {
        return response()->json(['data' => $projection->forVisit($request->user(), $historicalVisit, true, $this->pages($request)['current_page'], $this->pages($request)['history_page']), 'timezone' => $historicalVisit->branch->timezone]);
    }

    public function start(Request $request, Visit $visit, TreatmentRoomService $service): RedirectResponse
    {
        // Preserve unknown fields for the service's strict rejection; only Laravel's CSRF field is transport metadata.
        $service->start($request->user(), $visit, $this->command($request));

        return to_route('treatment-room.show', ['visit' => $visit, ...array_filter([...$this->pages($request), 'steps_page' => 1], fn (int $page): bool => $page > 1)]);
    }

    public function record(Request $request, Visit $visit, TreatmentRoomService $service): RedirectResponse
    {
        $service->recordOutcome($request->user(), $visit, $this->command($request));

        return to_route('treatment-room.show', ['visit' => $visit, ...array_filter($this->pages($request), fn (int $page): bool => $page > 1)]);
    }

    public function correct(Request $request, Visit $visit, TreatmentRoomService $service): RedirectResponse
    {
        $service->correctOutcome($request->user(), $visit, $this->command($request));

        return to_route('treatment-room.show', ['visit' => $visit, ...array_filter($this->pages($request), fn (int $page): bool => $page > 1)]);
    }

    public function void(Request $request, Visit $visit, TreatmentRoomService $service): RedirectResponse
    {
        $service->voidWrongPatient($request->user(), $visit, $this->command($request));

        return to_route('treatment-room.show', ['visit' => $visit, ...array_filter($this->pages($request), fn (int $page): bool => $page > 1)]);
    }

    /** @return array{page:int,steps_page:int,current_page:int,history_page:int} */
    private function pages(Request $request): array
    {
        $keys = ['page', 'steps_page', 'current_page', 'history_page'];
        $validated = $request->validate(array_fill_keys($keys, ['sometimes', 'integer', 'min:1', 'max:1000000']));

        return array_map(fn ($key): int => (int) ($validated[$key] ?? 1), array_combine($keys, $keys));
    }

    /** Keep unknown command fields for the domain validator; page cursors are transport metadata only.
     * @return array<string,mixed>
     */
    private function command(Request $request): array
    {
        $this->pages($request);

        return $request->except('_token', 'page', 'steps_page', 'current_page', 'history_page');
    }
}
