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
        $data = $request->validate(['page' => ['sometimes', 'integer', 'min:1', 'max:10000'], 'overdue' => ['sometimes', 'boolean'], 'query' => ['sometimes', 'nullable', 'string', 'max:100'], 'age' => ['sometimes', 'in:all,over60,31to60,1to30,not_overdue']]);

        return Inertia::render('Billing/PayLaterCollections', [
            'collections' => $collections->page($request->user(), (int) ($data['page'] ?? 1), (bool) ($data['overdue'] ?? false), ['query' => (string) ($data['query'] ?? ''), 'age' => (string) ($data['age'] ?? 'all')]),
        ]);
    }
}
