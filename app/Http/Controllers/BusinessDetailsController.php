<?php

namespace App\Http\Controllers;

use App\Domain\Access\BranchAccessService;
use App\Domain\Organisation\BusinessDetails\BranchLetterheadService;
use App\Domain\Organisation\BusinessDetails\BusinessDetailAsset;
use App\Domain\Organisation\BusinessDetails\BusinessDetailsAccess;
use App\Domain\Organisation\BusinessDetails\BusinessDetailsService;
use App\Domain\Organisation\BusinessDetails\OrganisationBusinessProfile;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

final class BusinessDetailsController extends Controller
{
    public function index(Request $request, BranchLetterheadService $letterheads): Response
    {
        return Inertia::render('BusinessDetails/Index', ['letterhead' => $letterheads->forActiveBranch($request->user())->toArray(),
            'canEdit' => $request->user()->can(BusinessDetailsAccess::MANAGE)]);
    }

    public function store(Request $request, BusinessDetailsService $service): RedirectResponse
    {
        try {
            $service->save($request->user(), $request->all(), $request->file('logo'));
        } catch (ValidationException $e) {
            throw $e->redirectTo(route('business-details.index'));
        }

        return to_route('business-details.index')->with('success', 'Business details saved.');
    }

    public function logo(Request $request, int $branch, string $asset, BusinessDetailsAccess $access): BinaryFileResponse
    {
        abort_unless(app(BranchAccessService::class)->activeBranch($request->user())?->id === $branch, 404);
        $image = DB::transaction(function () use ($request, $branch, $asset, $access): BusinessDetailAsset {
            [, $organisation, $active] = $access->lock($request->user(), $branch);
            abort_unless($active->id === $branch, 404);
            $profile = OrganisationBusinessProfile::query()->where('organisation_id', $organisation->id)->firstOrFail();

            return BusinessDetailAsset::query()->where('organisation_id', $organisation->id)->whereKey($profile->logo_asset_id)
                ->where('public_id', $asset)->whereNull('retired_at')->firstOrFail();
        }, 3);
        abort_unless(Storage::disk('local')->exists($image->path), 404);

        return response()->file(Storage::disk('local')->path($image->path), ['Content-Type' => $image->mime,
            'Content-Disposition' => 'inline; filename="clinic-logo.'.($image->mime === 'image/png' ? 'png' : 'jpg').'"',
            'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }
}
