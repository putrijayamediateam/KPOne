<?php

namespace App\Http\Controllers;

use App\Domain\Clinical\Services\ConsultationHoldService;
use App\Domain\Visit\Models\Visit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

class ConsultationHoldController extends Controller
{
    public function hold(Request $request, Visit $visit, ConsultationHoldService $holds): RedirectResponse
    {
        $holds->hold($request->user(), $visit, $request->all());
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Consultation placed On Hold.']);

        return back();
    }

    public function resume(Request $request, Visit $visit, ConsultationHoldService $holds): RedirectResponse
    {
        $holds->resume($request->user(), $visit, $request->all());
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Consultation resumed.']);

        return back();
    }
}
