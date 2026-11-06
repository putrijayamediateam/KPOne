<?php

namespace App\Domain\Organisation\BusinessDetails;

use App\Domain\Access\BranchAccessService;
use App\Domain\Organisation\Models\Branch;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Storage;

/**
 * CAT-4: read-only document readiness per active branch.
 *
 * It repeats, without writing anything, the checks the document code already makes at issue time:
 * - Referral and attendance letters (DocumentLetterheadCapture) need the branch clinic name, a complete address and a
 *   phone or email, and refuse a configured logo that is retired, missing on disk or changed.
 * - The Medical Certificate (MedicalCertificateSnapshot) checks only the logo file: missing on disk or changed blocks
 *   it; a retired logo is dropped and the MC issues without one.
 * - Invoices and receipts print without a footer (D02), so a missing footer is a warning, not a block.
 */
final class DocumentReadinessService
{
    private const ADDRESS_FIELDS = ['address_line_1' => 'address line 1', 'postcode' => 'postcode', 'city' => 'city', 'state' => 'state', 'country' => 'country'];

    public function __construct(private readonly BranchAccessService $branches) {}

    /**
     * @return array<int, array{
     *     branchId: int, name: string, isActive: bool, status: string,
     *     missing: array<int, string>, blockedLetters: array<int, string>, logoState: string, detailsComplete: bool, hasFooter: bool
     * }>
     */
    public function branches(User $actor): array
    {
        if (! $actor->can(BusinessDetailsAccess::VIEW)) {
            throw new AuthorizationException;
        }
        $organisationId = (int) $actor->organisation_id;
        $activeId = $this->branches->activeBranch($actor)?->id;

        $branches = Branch::query()->where('organisation_id', $organisationId)->where('is_active', true)->orderBy('name')->get(['id', 'name']);
        $profiles = BranchBusinessProfile::query()->where('organisation_id', $organisationId)->whereIn('branch_id', $branches->pluck('id'))->get()->keyBy('branch_id');
        $footers = BranchDocumentFooter::query()->where('organisation_id', $organisationId)->whereIn('branch_id', $branches->pluck('id'))->get()->keyBy('branch_id');
        $logoState = $this->logoState($organisationId);

        $rows = $branches->map(function (Branch $branch) use ($profiles, $footers, $logoState, $activeId): array {
            $profile = $profiles->get($branch->id);
            $blank = fn (string $field): bool => trim((string) $profile?->getAttribute($field)) === '';

            $missing = [];
            if ($blank('clinic_name')) {
                $missing[] = 'clinic name';
            }
            foreach (self::ADDRESS_FIELDS as $field => $label) {
                if ($blank($field)) {
                    $missing[] = $label;
                }
            }
            if ($blank('phone') && $blank('email')) {
                $missing[] = 'phone or email';
            }
            $detailsComplete = $missing === [];
            if ($logoState === 'retired') {
                $missing[] = 'logo (configured but retired)';
            } elseif ($logoState === 'unavailable') {
                $missing[] = 'logo (configured but unavailable)';
            }
            $hasFooter = trim((string) $footers->get($branch->id)?->text) !== '';
            // The footer blocks no letter, so it is reported on its own ("Needs footer"), never in the blocking list.

            $blockedLetters = match (true) {
                $logoState === 'unavailable' => ['MC', 'referral', 'attendance'],
                ! $detailsComplete || $logoState === 'retired' => ['referral', 'attendance'],
                default => [],
            };

            return [
                'branchId' => (int) $branch->id,
                'name' => (string) $branch->name,
                'isActive' => $activeId !== null && (int) $branch->id === (int) $activeId,
                'status' => $blockedLetters !== [] ? 'letters_blocked' : ($hasFooter ? 'ready' : 'needs_footer'),
                'missing' => $missing,
                'blockedLetters' => $blockedLetters,
                'logoState' => $logoState,
                'detailsComplete' => $detailsComplete,
                'hasFooter' => $hasFooter,
            ];
        });

        // The active branch first, then by name.
        return $rows->sortBy(fn (array $row): string => ($row['isActive'] ? '0' : '1').mb_strtolower($row['name']))->values()->all();
    }

    /**
     * 'none' (no logo configured, fine), 'ok', 'retired' (configured but retired or gone: letters refuse it, the MC
     * drops it) or 'unavailable' (file missing on disk or changed: every letter refuses it).
     */
    private function logoState(int $organisationId): string
    {
        $assetId = OrganisationBusinessProfile::query()->where('organisation_id', $organisationId)->value('logo_asset_id');
        if ($assetId === null) {
            return 'none';
        }
        $asset = BusinessDetailAsset::query()->where('organisation_id', $organisationId)->whereKey($assetId)->whereNull('retired_at')->first();
        $disk = Storage::disk('local');
        if ($asset === null) {
            return 'retired';
        }
        if (! $disk->exists($asset->path) || $disk->size($asset->path) !== (int) $asset->bytes) {
            return 'unavailable';
        }

        return hash_equals((string) $asset->sha256, hash('sha256', (string) $disk->get($asset->path))) ? 'ok' : 'unavailable';
    }
}
