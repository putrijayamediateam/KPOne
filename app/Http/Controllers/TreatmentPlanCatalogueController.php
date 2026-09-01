<?php

namespace App\Http\Controllers;

use App\Domain\Clinical\Services\TreatmentPlanDirectoryService;
use App\Domain\Visit\Models\Visit;
use App\Http\Requests\SearchTreatmentCatalogueRequest;
use Illuminate\Http\JsonResponse;

class TreatmentPlanCatalogueController extends Controller
{
    public function medicines(SearchTreatmentCatalogueRequest $request, Visit $visit, TreatmentPlanDirectoryService $directory): JsonResponse
    {
        return response()->json(['data' => $directory->searchMedicines($request->user(), $visit, $request->validated())]);
    }

    public function services(SearchTreatmentCatalogueRequest $request, Visit $visit, TreatmentPlanDirectoryService $directory): JsonResponse
    {
        return response()->json(['data' => $directory->searchServices($request->user(), $visit, $request->validated())]);
    }
}
