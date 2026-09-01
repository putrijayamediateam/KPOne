<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ClinicPlaceholderController extends Controller
{
    /** @var array<string, string> */
    private const MODULES = [
        'clinic.reviews' => 'Reviews',
        'clinic.panel-claims' => 'Panel Claims',
        'clinic.insight' => 'Insight',
        'clinic.purchase' => 'Purchase',
    ];

    public function __invoke(Request $request): Response
    {
        $title = self::MODULES[(string) $request->route()?->getName()] ?? abort(404);

        return Inertia::render('Clinic/Placeholder', ['module' => $title]);
    }
}
