<?php

namespace App\Http\Controllers;

use App\Domain\Organisation\Website\WebsiteServicesService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class WebsiteServicesController extends Controller
{
    public function index(Request $request, WebsiteServicesService $service): Response
    {
        return Inertia::render('Website/Services', ['services' => $service->page($request->user(), $request->route('contentType'), $request->route('websiteItem'), $request->query())]);
    }

    public function save(Request $request, WebsiteServicesService $service): RedirectResponse
    {
        $id = $service->mutate($request->user(), $request->route('contentType'), $request->route('websiteItem'), 'save', $request->all());

        return redirect()->route($request->route('contentType') === 'service_category' ? 'website.categories.edit' : 'website.services.edit', ['websiteItem' => $id]);
    }

    public function archive(Request $request, WebsiteServicesService $service): RedirectResponse
    {
        $id = $service->mutate($request->user(), $request->route('contentType'), $request->route('websiteItem'), $request->route('archiveAction'), $request->all());

        return redirect()->route($request->route('contentType') === 'service_category' ? 'website.categories.edit' : 'website.services.edit', ['websiteItem' => $id]);
    }
}
