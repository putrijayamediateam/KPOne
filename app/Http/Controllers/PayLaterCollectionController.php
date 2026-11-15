<?php

namespace App\Http\Controllers;

use App\Domain\Visit\Billing\Services\PayLaterCollectionService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class PayLaterCollectionController extends Controller
{
    public function index(Request $request, PayLaterCollectionService $collections): Response
    {
        $data = $request->validate(['page' => ['sometimes', 'integer', 'min:1', 'max:10000'], 'overdue' => ['sometimes', 'boolean']]);

        return Inertia::render('Billing/PayLaterCollections', [
            'collections' => $collections->page($request->user(), (int) ($data['page'] ?? 1), (bool) ($data['overdue'] ?? false)),
        ]);
    }
}
