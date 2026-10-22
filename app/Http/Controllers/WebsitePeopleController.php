<?php

namespace App\Http\Controllers;

use App\Domain\Organisation\Website\WebsitePeopleService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class WebsitePeopleController extends Controller
{
    public function index(Request $request, WebsitePeopleService $service): Response
    {
        return Inertia::render('Website/People', ['people' => $service->page($request->user(), $request->route('contentType'), $request->route('websiteItem'), $request->query())]);
    }

    public function save(Request $request, WebsitePeopleService $service): RedirectResponse
    {
        $id = $service->mutate($request->user(), $request->route('contentType'), $request->route('websiteItem'), 'save', $request->all());

        return redirect()->route($request->route('contentType') === 'doctor_profile' ? 'website.doctors.edit' : 'website.careers.edit', ['websiteItem' => $id]);
    }

    public function archive(Request $request, WebsitePeopleService $service): RedirectResponse
    {
        $service->mutate($request->user(), $request->route('contentType'), $request->route('websiteItem'), $request->route('archiveAction'), $request->all());

        return back();
    }
}
