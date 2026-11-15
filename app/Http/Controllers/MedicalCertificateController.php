<?php

namespace App\Http\Controllers;

use App\Domain\Clinical\Documents\MedicalCertificateDirectory;
use App\Domain\Clinical\Documents\MedicalCertificateService;
use App\Domain\Visit\Models\Visit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class MedicalCertificateController extends Controller
{
    public function index(Request $request, Visit $visit, MedicalCertificateDirectory $directory): Response
    {
        $input = $request->validate(['page' => ['sometimes', 'integer', 'min:1', 'max:10000']]);

        return Inertia::render('MedicalCertificates/Index', ['certificates' => $directory->history($request->user(), $visit, (int) ($input['page'] ?? 1))]);
    }

    public function store(Request $request, Visit $visit, MedicalCertificateService $service): RedirectResponse
    {
        $service->act($request->user(), $visit, $request->all());
        Inertia::flash('toast', ['type' => 'success', 'message' => 'MC evidence saved. Wording remains draft; wet signature required.']);

        return to_route('medical-certificates.index', $visit);
    }

    public function show(Request $request, Visit $visit, int $document, MedicalCertificateDirectory $directory): \Illuminate\Http\Response
    {
        $data = $directory->snapshot($request->user(), $visit, $document, true);

        return response()->view('medical-certificates.show', $data)->header('Cache-Control', 'private, no-store, max-age=0')
            ->header('Content-Security-Policy', "default-src 'none'; img-src data:; style-src 'unsafe-inline'; script-src 'none'; base-uri 'none'; form-action 'none'; frame-ancestors 'none'")
            ->header('X-Content-Type-Options', 'nosniff')->header('Referrer-Policy', 'no-referrer');
    }
}
