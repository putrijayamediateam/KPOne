<?php

namespace App\Http\Controllers;

use App\Domain\Patient\Models\Patient;
use App\Domain\Patient\Services\PatientEmergencyContactService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class PatientEmergencyContactController extends Controller
{
    public function show(Request $request, Patient $patient, PatientEmergencyContactService $contacts): JsonResponse
    {
        $this->authorize('view', $patient);
        abort_if($patient->isMergedAway(), 409);

        return response()->json($contacts->contact($request->user(), $patient))->header('Cache-Control', 'no-store, private');
    }

    public function update(Request $request, Patient $patient, PatientEmergencyContactService $contacts): JsonResponse
    {
        return response()->json($contacts->update($request->user(), $patient, $request->all()))->header('Cache-Control', 'no-store, private');
    }
}
