<?php

namespace App\Http\Controllers;

use App\Domain\Organisation\Website\WebsiteEditorialService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class WebsiteEditorialController extends Controller
{
    public function index(Request $request, WebsiteEditorialService $service): Response
    {
        return Inertia::render('Website/Editorial', ['editorial' => $service->page($request->user(), $request->route('contentType'), $request->route('websiteItem'), $request->query())]);
    }

    public function save(Request $request, WebsiteEditorialService $service): RedirectResponse
    {
        $id = $service->mutate($request->user(), $request->route('contentType'), $request->route('websiteItem'), 'save', $request->all());

        return redirect()->route('website.'.['promotion' => 'promotions', 'activity' => 'activities', 'faq' => 'faqs'][$request->route('contentType')].'.edit', ['websiteItem' => $id]);
    }

    public function archive(Request $request, WebsiteEditorialService $service): RedirectResponse
    {
        $id = $service->mutate($request->user(), $request->route('contentType'), $request->route('websiteItem'), $request->route('archiveAction'), $request->all());

        return redirect()->route('website.'.['promotion' => 'promotions', 'activity' => 'activities', 'faq' => 'faqs'][$request->route('contentType')].'.edit', ['websiteItem' => $id]);
    }
}
