<?php

namespace App\Http\Controllers;

use App\Domain\Organisation\Website\WebsiteLittleShieldService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class WebsiteLittleShieldController extends Controller
{
    public function index(Request $request, WebsiteLittleShieldService $service): Response
    {
        return Inertia::render('Website/LittleShield', ['littleShield' => $service->page($request->user(), $request->query())]);
    }

    public function save(Request $request, WebsiteLittleShieldService $service): RedirectResponse
    {
        $service->save($request->user(), $request->all());

        return redirect()->route('website.little-shield.index');
    }
}
