<?php

namespace App\Http\Controllers;

use App\Domain\Access\BranchAccessService;
use App\Domain\Organisation\Models\Branch;
use App\Domain\Organisation\Models\PublicCheckInLink;
use App\Domain\Organisation\Services\PublicCheckInLinkService;
use App\Domain\Organisation\Services\PublicCheckInQrCodeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class PublicCheckInLinkController extends Controller
{
    public function index(
        Request $request,
        BranchAccessService $branchAccess,
        PublicCheckInLinkService $service,
        PublicCheckInQrCodeService $qr,
    ): Response {
        $organisationId = $request->user()->organisation_id;
        $canManageOrganisation = $request->user()->can('public_checkin_links.manage.organisation');
        $branches = $canManageOrganisation
            ? Branch::query()->where('organisation_id', $organisationId)->where('is_active', true)->orderBy('name')->get()
            : $branchAccess->availableBranches($request->user())
                ->filter(fn (Branch $branch): bool => $branch->is_active
                    && $branchAccess->hasEffectiveAssignment($request->user(), $branch))
                ->values();
        $branchIds = $branches->pluck('id');
        $links = PublicCheckInLink::query()
            ->where('organisation_id', $organisationId)
            ->whereIn('branch_id', $branchIds)
            ->with('branch:id,organisation_id,name,code,is_active')
            ->latest()->get()
            ->map(function (PublicCheckInLink $link) use ($request, $service, $qr): array {
                $rawToken = $service->recover($request->user(), $link);

                return [
                    'publicId' => $link->public_id,
                    'branch' => $link->branch->only(['code', 'name']),
                    'label' => $link->label,
                    'status' => ! $link->is_active ? 'revoked' : ($link->expires_at?->isFuture() ? 'active' : 'expired'),
                    'createdAt' => $link->created_at?->toIso8601String(),
                    'expiresAt' => $link->expires_at?->toIso8601String(),
                    'revokedAt' => $link->revoked_at?->toIso8601String(),
                    'activeQr' => $rawToken === null ? null : $this->issuedLink($link, $rawToken, $qr),
                    'requiresRotation' => $link->is_active && $link->expires_at?->isFuture() && $rawToken === null,
                ];
            });

        return Inertia::render('PublicCheckInLinks/Index', [
            'links' => $links,
            'branches' => $branches->map->only(['id', 'name'])->values(),
        ]);
    }

    public function store(
        Request $request,
        PublicCheckInLinkService $service,
        PublicCheckInQrCodeService $qr,
    ): JsonResponse {
        $validated = $request->validate([
            'branch_id' => ['required', 'integer', Rule::exists('branches', 'id')->where('organisation_id', $request->user()->organisation_id)],
            'label' => ['required', 'string', 'max:120'],
        ]);
        $branch = Branch::query()->where('organisation_id', $request->user()->organisation_id)
            ->whereKey($validated['branch_id'])->firstOrFail();
        $issued = $service->issue($request->user(), $branch, $validated['label']);

        return response()->json(['issuedLink' => $this->issuedLink($issued['link'], $issued['rawToken'], $qr)], 201);
    }

    public function rotate(
        Request $request,
        string $publicCheckInLink,
        PublicCheckInLinkService $service,
        PublicCheckInQrCodeService $qr,
    ): JsonResponse {
        $link = $this->scopedLink($request, $publicCheckInLink);
        $issued = $service->rotate($request->user(), $link);

        return response()->json(['issuedLink' => $this->issuedLink($issued['link'], $issued['rawToken'], $qr)]);
    }

    public function revoke(Request $request, string $publicCheckInLink, PublicCheckInLinkService $service): JsonResponse
    {
        $service->revoke($request->user(), $this->scopedLink($request, $publicCheckInLink));

        return response()->json(status: 204);
    }

    private function scopedLink(Request $request, string $publicId): PublicCheckInLink
    {
        return PublicCheckInLink::query()->where('organisation_id', $request->user()->organisation_id)
            ->where('public_id', $publicId)->with('branch')->firstOrFail();
    }

    /** @return array{publicId: string, url: string, qrDataUri: string, expiresAt: string|null} */
    private function issuedLink(
        PublicCheckInLink $link,
        #[\SensitiveParameter] string $rawToken,
        PublicCheckInQrCodeService $qr,
    ): array {
        // URL fragments are handled only by the browser and are never part of an HTTP request target.
        $url = route('public-checkin.show').'#'.$rawToken;

        return [
            'publicId' => $link->public_id,
            'url' => $url,
            'qrDataUri' => 'data:image/svg+xml;base64,'.base64_encode($qr->svg($url)),
            'expiresAt' => $link->expires_at?->toIso8601String(),
        ];
    }
}
