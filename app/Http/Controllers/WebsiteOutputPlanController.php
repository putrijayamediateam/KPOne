<?php

namespace App\Http\Controllers;

use App\Domain\Organisation\Website\WebsiteOutputPlanService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class WebsiteOutputPlanController extends Controller
{
    public function index(Request $request, WebsiteOutputPlanService $service): Response
    {
        $release = $request->route('release');
        $plan = $request->route('plan');

        return Inertia::render('Website/OutputPlans', ['outputPlans' => $service->page($request->user(), $release !== null ? (int) $release : null, $plan !== null ? (int) $plan : null)])->with('privatePreview', true);
    }

    public function prepare(Request $request, WebsiteOutputPlanService $service, int $release): RedirectResponse
    {
        $id = $service->mutate($request->user(), 'prepare', null, [...$request->all(), 'release_id' => $release]);

        return redirect()->route('website.output-plans.show', ['plan' => $id]);
    }

    public function decide(Request $request, WebsiteOutputPlanService $service, int $plan, string $operation): RedirectResponse
    {
        $service->mutate($request->user(), $operation, $plan, $request->all());

        return redirect()->route('website.output-plans.show', ['plan' => $plan]);
    }
}
