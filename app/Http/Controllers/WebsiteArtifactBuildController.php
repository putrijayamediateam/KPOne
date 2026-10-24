<?php

namespace App\Http\Controllers;

use App\Domain\Organisation\Website\WebsiteArtifactBuildService;
use App\Domain\Organisation\Website\WebsiteArtifactFailure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class WebsiteArtifactBuildController extends Controller
{
    public function store(Request $request, WebsiteArtifactBuildService $service, int $release): RedirectResponse
    {
        try {
            $service->build($request->user(), $release, $request->all());
        } catch (WebsiteArtifactFailure $failure) {
            return redirect()->route('website.releases.show', ['release' => $release])->withErrors(['build' => 'Build refused: '.$failure->failureCode]);
        }

        return redirect()->route('website.releases.show', ['release' => $release]);
    }
}
