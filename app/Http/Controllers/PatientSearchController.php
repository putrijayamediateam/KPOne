<?php

namespace App\Http\Controllers;

use App\Domain\Patient\Services\PatientDirectoryService;
use App\Http\Requests\CheckPatientDuplicatesRequest;
use App\Http\Requests\SearchPatientsRequest;
use Illuminate\Http\JsonResponse;

class PatientSearchController extends Controller
{
    public function search(SearchPatientsRequest $request, PatientDirectoryService $directory): JsonResponse
    {
        return response()->json($directory->search($request->user(), $request->validated()));
    }

    public function duplicateCheck(CheckPatientDuplicatesRequest $request, PatientDirectoryService $directory): JsonResponse
    {
        return response()->json(['candidates' => $directory->duplicateCandidates($request->user(), $request->validated())]);
    }
}
