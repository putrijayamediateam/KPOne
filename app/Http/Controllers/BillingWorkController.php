<?php

namespace App\Http\Controllers;

use App\Domain\Access\BillingWorkAccess;
use App\Domain\Access\WorkspaceLandingService;
use App\Domain\Visit\Billing\Services\BillingWorkDirectoryService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class BillingWorkController extends Controller
{
    public function panel(Request $request, BillingWorkAccess $access, WorkspaceLandingService $landing, BillingWorkDirectoryService $directory): Response
    {
        if (! $access->panel($request->user())) {
            abort_unless($landing->canEnterClinic($request->user()), 403);

            return Inertia::render('Clinic/Placeholder', ['module' => 'Panel Claims']);
        }

        return $this->render($request, $directory, true);
    }

    public function finance(Request $request, BillingWorkDirectoryService $directory): Response
    {
        return $this->render($request, $directory, false);
    }

    private function render(Request $request, BillingWorkDirectoryService $directory, bool $panel): Response
    {
        $data = $request->validate(['page' => ['sometimes', 'integer', 'min:1', 'max:10000']]);

        return Inertia::render('FinancialWork/Index', ['work' => $directory->search($request->user(), $panel, (int) ($data['page'] ?? 1))]);
    }
}
