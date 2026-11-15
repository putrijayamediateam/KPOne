<?php

namespace App\Http\Controllers;

use App\Domain\Organisation\Website\WebsiteDraftService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

final class WebsiteDraftController extends Controller
{
    public function profiles(Request $request, WebsiteDraftService $drafts): Response
    {
        $page = $request->validate(['page' => ['sometimes', 'integer', 'min:1']]);

        return Inertia::render('Website/Profiles', ['profile' => $drafts->profile($request->user(), (int) ($page['page'] ?? 1))]);
    }

    public function save(Request $request, WebsiteDraftService $drafts): RedirectResponse
    {
        $drafts->save($request->user(), $request->all());

        return redirect()->route('website.profiles');
    }

    public function media(Request $request, WebsiteDraftService $drafts): Response
    {
        $page = $request->validate(['page' => ['sometimes', 'integer', 'min:1']]);

        return Inertia::render('Website/Media', ['library' => $drafts->media($request->user(), (int) ($page['page'] ?? 1))]);
    }

    public function upload(Request $request, WebsiteDraftService $drafts): RedirectResponse
    {
        $request->validate(['image' => ['required', 'file']]);
        $drafts->upload($request->user(), $request->except('image'), $request->file('image'));

        return redirect()->route('website.media');
    }

    public function retire(Request $request, string $asset, WebsiteDraftService $drafts): RedirectResponse
    {
        $drafts->retire($request->user(), $asset, $request->all());

        return redirect()->route('website.media');
    }

    public function image(Request $request, string $asset, WebsiteDraftService $drafts): HttpResponse
    {
        $image = $drafts->image($request->user(), $asset);

        return response($image['content'], 200, ['Content-Type' => $image['mime'], 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff', 'Content-Security-Policy' => "default-src 'none'; sandbox", 'Content-Disposition' => 'inline']);
    }
}
