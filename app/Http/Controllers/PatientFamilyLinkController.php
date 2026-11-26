<?php

namespace App\Http\Controllers;

use App\Domain\Patient\Models\Patient;
use App\Domain\Patient\Services\PatientFamilyLinkService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class PatientFamilyLinkController extends Controller
{
    public function show(Request $request, Patient $patient, PatientFamilyLinkService $links): JsonResponse
    {
        return response()->json($links->directory($request->user(), $patient))->header('Cache-Control', 'no-store, private');
    }

    public function store(Request $request, Patient $patient, PatientFamilyLinkService $links): JsonResponse
    {
        return response()->json($links->execute($request->user(), $patient, $request->all()))->header('Cache-Control', 'no-store, private');
    }
}
