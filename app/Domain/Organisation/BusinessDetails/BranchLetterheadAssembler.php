<?php

namespace App\Domain\Organisation\BusinessDetails;

use App\Domain\Organisation\Models\Branch;

/** Call only after authorising the settings or source-document boundary. */
final class BranchLetterheadAssembler
{
    /** @param callable(string): string $logoUrl */
    public function assemble(Branch $branch, callable $logoUrl): BranchLetterhead
    {
        $organisation = $branch->organisation;
        $org = OrganisationBusinessProfile::query()->where('organisation_id', $organisation->id)->first();
        $details = BranchBusinessProfile::query()->where('organisation_id', $organisation->id)->where('branch_id', $branch->id)->first();
        $logo = $org?->logo_asset_id ? BusinessDetailAsset::query()->whereKey($org->logo_asset_id)->where('organisation_id', $organisation->id)->whereNull('retired_at')->first() : null;

        $footer = BranchDocumentFooter::query()->where('organisation_id', $organisation->id)->where('branch_id', $branch->id)->first();

        return new BranchLetterhead($branch->id, $branch->name,
            ['legal_name' => $org->legal_name ?? $organisation->name, 'registration_type' => $org?->registration_type, 'registration_number' => $org?->registration_number],
            ['clinic_name' => $details->clinic_name ?? $branch->name, 'address_line_1' => $details->address_line_1 ?? '', 'address_line_2' => $details?->address_line_2,
                'postcode' => $details->postcode ?? '', 'city' => $details->city ?? '', 'state' => $details->state ?? '', 'country' => $details->country ?? '', 'phone' => $details?->phone, 'email' => $details?->email],
            $logo ? ['url' => $logoUrl($logo->public_id), 'mime' => $logo->mime, 'width' => $logo->width, 'height' => $logo->height] : null,
            $org->lock_version ?? 0, $details->lock_version ?? 0, $footer?->text, $footer->lock_version ?? 0);
    }
}
