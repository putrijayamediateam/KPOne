<?php

namespace App\Domain\Clinical\Documents;

use App\Domain\Organisation\BusinessDetails\BranchBusinessProfile;
use App\Domain\Organisation\BusinessDetails\BranchLetterheadAssembler;
use App\Domain\Organisation\BusinessDetails\BusinessDetailAsset;
use App\Domain\Organisation\BusinessDetails\OrganisationBusinessProfile;
use App\Domain\Organisation\Models\Branch;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/** Source authority must be locked by the caller. Never grants settings access. */
final class DocumentLetterheadCapture
{
    /** @return array<string, mixed> */
    public function capture(Branch $branch): array
    {
        $profile = BranchBusinessProfile::query()->where('organisation_id', $branch->organisation_id)->where('branch_id', $branch->id)->lockForUpdate()->first();
        if (! $profile || trim((string) $profile->clinic_name) === '') {
            throw ValidationException::withMessages(['letterhead' => 'Configure this branch clinic name before issue.']);
        }
        $head = app(BranchLetterheadAssembler::class)->assemble($branch, function (string $id) use ($branch): string {
            $asset = BusinessDetailAsset::query()->where('organisation_id', $branch->organisation_id)->where('public_id', $id)->whereNull('retired_at')->lockForUpdate()->firstOrFail();
            $disk = Storage::disk('local');
            if (! $disk->exists($asset->path)) {
                throw ValidationException::withMessages(['letterhead' => 'Configured clinic logo is unavailable. Restore it before issue.']);
            }
            $bytes = $disk->get($asset->path);
            if (! hash_equals($asset->sha256, hash('sha256', $bytes))) {
                throw ValidationException::withMessages(['letterhead' => 'Configured clinic logo failed integrity verification.']);
            }

            return 'data:'.$asset->mime.';base64,'.base64_encode($bytes);
        });
        // A configured retired/missing asset must not silently become "no logo".
        $configured = OrganisationBusinessProfile::query()->where('organisation_id', $branch->organisation_id)->value('logo_asset_id');
        if ($configured && $head->logo === null) {
            throw ValidationException::withMessages(['letterhead' => 'Configured clinic logo is unavailable.']);
        }
        foreach (['clinic_name', 'address_line_1', 'postcode', 'city', 'state', 'country'] as $field) {
            if (trim((string) $head->details[$field]) === '') {
                throw ValidationException::withMessages(['letterhead' => 'Complete clinic name, address and contact in Business details before issuing.']);
            }
        }
        if (trim((string) $head->details['phone']) === '' && trim((string) $head->details['email']) === '') {
            throw ValidationException::withMessages(['letterhead' => 'A clinic contact phone or email is required.']);
        }

        return ['details' => $head->details, 'logo' => $head->logo, 'organisationVersion' => $head->organisationVersion, 'branchVersion' => $head->branchVersion];
    }
}
