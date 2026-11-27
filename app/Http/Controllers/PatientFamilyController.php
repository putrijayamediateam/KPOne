<?php

namespace App\Http\Controllers;

use App\Domain\Patient\Family\PatientFamilyService;
use App\Domain\Patient\Models\Patient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** FAM-1: read and change a patient's family. Every rule lives in PatientFamilyService. */
final class PatientFamilyController extends Controller
{
    public function show(Request $request, Patient $patient, PatientFamilyService $families): JsonResponse
    {
        return response()->json($families->directory($request->user(), $patient));
    }

    public function store(Request $request, Patient $patient, PatientFamilyService $families): JsonResponse
    {
        return response()->json($families->execute($request->user(), $patient, $request->all()));
    }
}
