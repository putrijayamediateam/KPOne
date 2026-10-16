<?php

namespace App\Domain\Organisation\BusinessDetails;

use App\Domain\Access\BranchAccessService;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class BranchLetterheadService
{
    public function __construct(private BusinessDetailsAccess $access, private BranchAccessService $branches) {}

    public function forActiveBranch(User $actor): BranchLetterhead
    {
        $branch = $this->branches->activeBranch($actor);
        abort_unless($branch !== null, 404);

        return DB::transaction(function () use ($actor, $branch): BranchLetterhead {
            [, $organisation, $branch] = $this->access->lock($actor, $branch->id);
            $org = OrganisationBusinessProfile::query()->where('organisation_id', $organisation->id)->first();
            $details = BranchBusinessProfile::query()->where('organisation_id', $organisation->id)->where('branch_id', $branch->id)->first();
            $logo = $org?->logo_asset_id ? BusinessDetailAsset::query()->whereKey($org->logo_asset_id)->where('organisation_id', $organisation->id)->first() : null;

            return new BranchLetterhead($branch->id, $branch->name,
                ['legal_name' => $org->legal_name ?? $organisation->name, 'registration_type' => $org?->registration_type, 'registration_number' => $org?->registration_number],
                ['clinic_name' => $details->clinic_name ?? $branch->name, 'address_line_1' => $details->address_line_1 ?? '', 'address_line_2' => $details?->address_line_2,
                    'postcode' => $details->postcode ?? '', 'city' => $details->city ?? '', 'state' => $details->state ?? '', 'country' => $details->country ?? '', 'phone' => $details?->phone, 'email' => $details?->email],
                $logo ? ['url' => route('business-details.logo', ['branch' => $branch->id, 'asset' => $logo->public_id], absolute: false), 'mime' => $logo->mime, 'width' => $logo->width, 'height' => $logo->height] : null,
                $org->lock_version ?? 0, $details->lock_version ?? 0);
        }, 3);
    }
}
