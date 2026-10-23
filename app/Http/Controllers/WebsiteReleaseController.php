<?php

namespace App\Http\Controllers;

use App\Domain\Organisation\Website\WebsiteReleaseService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

final class WebsiteReleaseController extends Controller
{
    public function index(Request $request, WebsiteReleaseService $service, ?int $release = null): Response
    {
        return Inertia::render('Website/Releases', ['releases' => $service->page($request->user(), $release)])->with('privatePreview', true);
    }

    public function prepare(Request $request, WebsiteReleaseService $service): RedirectResponse
    {
        try {
            $id = $service->mutate($request->user(), 'prepare', null, $request->all());
        } catch (ValidationException $e) {
            return redirect()->route('website.releases.index')->withErrors($e->errors());
        }

        return redirect()->route('website.releases.show', ['release' => $id]);
    }

    public function decide(Request $request, WebsiteReleaseService $service, int $release, string $operation): RedirectResponse
    {
        try {
            $service->mutate($request->user(), $operation, $release, $request->all());
        } catch (ValidationException $e) {
            return redirect()->route('website.releases.show', ['release' => $release])->withErrors($e->errors());
        }

        return redirect()->route('website.releases.show', ['release' => $release]);
    }

    public function media(Request $request, WebsiteReleaseService $service, int $release, string $filename): HttpResponse
    {
        $image = $service->media($request->user(), $release, $filename);

        return response($image['content'], 200, ['Content-Type' => $image['mime'], 'X-Content-Type-Options' => 'nosniff', 'Content-Security-Policy' => "default-src 'none'", 'X-Robots-Tag' => 'noindex, nofollow']);
    }
}
