<?php

namespace App\Http\Controllers;

use App\Domain\Clinical\Triage\TriageFormProjection;
use App\Domain\Clinical\Triage\TriageService;
use App\Domain\Visit\Models\Visit;
use App\Http\Requests\RecordTriageRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/** TRI-1b: the Classic triage form. Permission, branch and every rule are checked in the domain; redirects are explicit. */
class TriageController extends Controller
{
    public function show(Request $request, Visit $visit, TriageFormProjection $projection): Response
    {
        return Inertia::render('Triage/Show', $projection->form($request->user(), $visit));
    }

    public function store(RecordTriageRequest $request, Visit $visit, TriageService $triage): RedirectResponse
    {
        try {
            $row = $triage->record($request->user(), $visit, $request->validated());
        } catch (ValidationException $e) {
            throw $e->redirectTo(route('visits.triage.show', $visit));
        }
        Inertia::flash('toast', ['type' => 'success', 'message' => $row->chosen_zone
            ? __('Triage saved · :zone zone', ['zone' => ucfirst($row->chosen_zone)])
            : __('Triage readings saved.')]);

        return to_route('registration.index', ['tab' => 'triage']);
    }
}
