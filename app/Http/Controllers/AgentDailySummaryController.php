<?php

namespace App\Http\Controllers;

use App\Domain\Agents\DailySummary\DailySummaryPage;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** AG-8: the daily summary page, read from the stored 07:00 and 14:00 sections only. */
class AgentDailySummaryController extends Controller
{
    public function index(Request $request, DailySummaryPage $page): Response
    {
        return Inertia::render('Agents/DailySummary', [
            'summary' => $page->page($request->user(), $request->query('date')),
        ]);
    }
}
