<?php

namespace App\Http\Controllers;

use App\Domain\Clinical\Services\ClinicalEncounterDirectoryService;
use App\Domain\Clinical\Services\ClinicalEncounterService;
use App\Domain\Clinical\Services\EncounterDiagnosisPresetDirectoryService;
use App\Domain\Queue\Display\RoomCallService;
use App\Domain\Queue\Models\BranchRoom;
use App\Domain\Queue\Treatment\TreatmentStepProjection;
use App\Domain\Queue\Treatment\TreatmentStepService;
use App\Domain\Visit\Models\Visit;
use App\Http\Requests\StartClinicalEncounterRequest;
use App\Http\Requests\UpdateClinicalEncounterRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ClinicalEncounterController extends Controller
{
    public function store(
        StartClinicalEncounterRequest $request,
        Visit $visit,
        ClinicalEncounterService $encounters,
    ): RedirectResponse {
        $encounters->start($request->user(), $visit, $request->validated());

        return to_route('encounters.show', $visit);
    }

    public function show(
        Request $request,
        Visit $visit,
        ClinicalEncounterDirectoryService $directory,
        RoomCallService $calls,
        TreatmentStepService $treatment,
        TreatmentStepProjection $treatmentProjection,
    ): Response {
        // detail() authorises the encounter view first; only then may the attending doctor's open acknowledge Q-2.
        $clinical = $directory->detail($request->user(), $visit);
        try {
            $treatment->acknowledgeOnOpen($request->user(), $visit);
        } catch (\Throwable $exception) {
            // The acknowledgement is a convenience; a failure (for example the audit) never blocks the page.
            report($exception);
        }
        $entry = $visit->queueEntry()->first();
        $entry?->setRelation('visit', $visit->refresh());

        return Inertia::render('Clinical/Show', [
            'clinical' => $clinical,
            'treatmentRooms' => $calls->rooms($visit->branch, BranchRoom::KIND_TREATMENT),
            'treatmentProgress' => $entry ? ($treatmentProjection->rows($request->user(), [$entry])[$entry->id] ?? null) : null,
        ]);
    }

    public function history(
        Request $request,
        Visit $historicalVisit,
        ClinicalEncounterDirectoryService $directory,
    ): Response|JsonResponse {
        $historical = $directory->historicalDetail($request->user(), $historicalVisit);

        if ($request->expectsJson()) {
            return response()->json($historical);
        }

        return Inertia::render('Clinical/HistoryShow', ['historical' => $historical]);
    }

    public function diagnosisPresets(Request $request, Visit $visit, EncounterDiagnosisPresetDirectoryService $directory): JsonResponse
    {
        $data = $request->validate(['expected_branch_id' => ['required', 'integer', 'min:1'], 'q' => ['sometimes', 'nullable', 'string', 'max:200']]);

        return response()->json(['data' => $directory->search($request->user(), $visit, (int) $data['expected_branch_id'], trim((string) ($data['q'] ?? '')))]);
    }

    public function update(
        UpdateClinicalEncounterRequest $request,
        Visit $visit,
        ClinicalEncounterService $encounters,
    ): RedirectResponse {
        $encounters->update($request->user(), $visit, $request->validated());
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Clinical record saved.')]);

        return to_route('encounters.show', $visit);
    }
}
