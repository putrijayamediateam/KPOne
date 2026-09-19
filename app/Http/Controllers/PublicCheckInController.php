<?php

namespace App\Http\Controllers;

use App\Domain\Organisation\Services\PublicCheckInLinkService;
use App\Domain\Patient\Services\PublicPatientIntakeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class PublicCheckInController extends Controller
{
    public function __invoke(
        string $token,
        PublicCheckInLinkService $links,
        PublicPatientIntakeService $intakes,
    ): Response {
        $intakes->ensureEnabled();
        $link = $links->resolve($token);

        return Inertia::render('PublicCheckIn/Show', [
            'clinicName' => 'Klinik Putrijaya',
            'branch' => ['name' => $link->branch->name],
            'privacyNoticeVersion' => config('public-intake.privacy_notice_version'),
            'minorAge' => (int) config('public-intake.minor_age', 18),
        ]);
    }

    public function session(
        string $token,
        PublicCheckInLinkService $links,
        PublicPatientIntakeService $intakes,
    ): JsonResponse {
        return response()->json($intakes->openSession($links->resolve($token)), 201);
    }

    public function submit(
        Request $request,
        string $token,
        PublicCheckInLinkService $links,
        PublicPatientIntakeService $intakes,
    ): JsonResponse {
        $intake = $intakes->submit($links->resolve($token), $request->all());

        return response()->json([
            'state' => $intake->status,
            'statusUrl' => route('public-intake.status', ['receipt' => $request->string('status_receipt')->toString()]),
        ], 201);
    }

    public function status(string $receipt, PublicPatientIntakeService $intakes): Response
    {
        return Inertia::render('PublicCheckIn/Status', [
            'status' => $intakes->status($receipt),
        ]);
    }
}
