<?php

namespace App\Http\Controllers;

use App\Domain\Access\BillingWorkAccess;
use App\Domain\Visit\Billing\Services\BillingWorkDirectoryService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class BillingWorkController extends Controller
{
    public function panel(Request $request, BillingWorkAccess $access, BillingWorkDirectoryService $directory): Response
    {
        abort_unless($access->panel($request->user()), 403);

        return $this->render($request, $directory, true);
    }

    public function finance(Request $request, BillingWorkAccess $access, BillingWorkDirectoryService $directory): Response
    {
        abort_unless($access->finance($request->user()), 403);

        return $this->render($request, $directory, false);
    }

    private function render(Request $request, BillingWorkDirectoryService $directory, bool $panel): Response
    {
        $data = $request->validate(['page' => ['sometimes', 'integer', 'min:1', 'max:10000']]);

        return Inertia::render('FinancialWork/Index', ['work' => $directory->search($request->user(), $panel, (int) ($data['page'] ?? 1))]);
    }
}
