<?php

namespace App\Http\Controllers;

use App\Domain\Access\BranchAccessService;
use App\Domain\Access\TransactionalActorAuthority;
use App\Domain\Clinical\Documents\FixedMedicalCertificateTemplates;
use App\Domain\Identity\Services\ClinicianCredentialService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

final class ClinicianCredentialController
{
    public function index(Request $request, ClinicianCredentialService $service): Response
    {
        return Inertia::render('ClinicianCredentials/Index', $service->page($request->user(), false, max(1, $request->integer('page', 1))));
    }

    public function own(Request $request, ClinicianCredentialService $service): Response
    {
        return Inertia::render('ClinicianCredentials/Index', $service->page($request->user(), true, 1));
    }

    public function save(Request $request, int $subject, ClinicianCredentialService $service): RedirectResponse
    {
        $service->save($request->user(), $subject, $request->all());

        return to_route('clinician-credentials.index');
    }

    public function templates(Request $request, BranchAccessService $branches, TransactionalActorAuthority $authority, FixedMedicalCertificateTemplates $templates): Response
    {
        $actor = $request->user();
        $branch = $branches->activeBranch($actor);
        abort_unless($branch !== null, 404);
        $data = DB::transaction(function () use ($actor, $branch, $authority, $templates): array {
            $actor = $authority->lock($actor, $branch, 'catalogue.settings.manage.organisation');
            abort_unless($actor->getAllPermissions()->contains('name', 'catalogue.settings.manage.organisation'), 403);

            return $templates->revisions();
        });

        return Inertia::render('MedicalCertificateTemplates/Index', ['revisions' => $data]);
    }
}
