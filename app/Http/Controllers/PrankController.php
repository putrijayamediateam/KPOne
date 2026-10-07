<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * PRANK: presentation joke only. Never merge this branch.
 * Reads and writes no data. The case id is only used to build the "Back to real dispensary" link;
 * the real Dispensary page enforces its own permission and branch scope.
 */
class PrankController extends Controller
{
    public function pharmamax(Request $request, string $caseId): Response
    {
        return $this->page('Prank/PharmaMax', $caseId);
    }

    public function oops(Request $request, string $caseId): Response
    {
        return $this->page('Prank/DelightfulError', $caseId);
    }

    public function reveal(Request $request, string $caseId): Response
    {
        return $this->page('Prank/Reveal', $caseId);
    }

    private function page(string $component, string $caseId): Response
    {
        abort_unless(config('prank.enabled'), 404);

        return Inertia::render($component, ['caseId' => $caseId]);
    }
}
