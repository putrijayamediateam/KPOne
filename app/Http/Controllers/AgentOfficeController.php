<?php

namespace App\Http\Controllers;

use App\Domain\Agents\Services\AgentOfficeDetail;
use App\Domain\Agents\Services\AgentOfficeFeed;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** AG-3: the 2.5D agent office page and the read-only details behind its click panel. */
class AgentOfficeController extends Controller
{
    public function index(Request $request, AgentOfficeFeed $feed): Response
    {
        return Inertia::render('Agents/Office', [
            'feed' => $feed->build($request->user()->organisation_id),
            'canOpenInbox' => $request->user()->can('ai_proposals.view.branch'),
        ]);
    }

    public function agent(Request $request, string $code, AgentOfficeDetail $detail): JsonResponse
    {
        $agent = $detail->build($request->user(), $code);
        abort_if($agent === null, 404);

        return response()->json($agent);
    }
}
