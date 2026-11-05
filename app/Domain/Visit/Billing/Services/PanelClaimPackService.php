<?php

namespace App\Domain\Visit\Billing\Services;

use App\Domain\Access\BranchAccessService;
use App\Domain\Access\TransactionalActorAuthority;
use App\Domain\Audit\AuditRecorder;
use App\Domain\Organisation\BusinessDetails\BranchLetterheadAssembler;
use App\Domain\Visit\Billing\Models\PanelClaim;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * PNL-8 (owner decisions 2026-11-05): a printable claim pack for one claim or up to 50 claims of one Panel at the
 * active branch, from the saved claim evidence only. It carries the branch letterhead, the patient's IC or passport
 * number for holders of the identity permission, and the names of attached photos (never the photos). No clinical
 * content. Each pack writes one audit record holding the claim ids only.
 */
final class PanelClaimPackService
{
    public const PERMISSION = 'panel.claims.manage.branch';

    public const IDENTITY_PERMISSION = 'panel.claims.patient_identity.view.branch';

    public const MAX_CLAIMS = 50;

    public function __construct(
        private BranchAccessService $branches,
        private TransactionalActorAuthority $authority,
        private AuditRecorder $audit,
        private BranchLetterheadAssembler $letterheads,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function pack(User $actor, array $input): array
    {
        // Lower-case first, so the same claim given twice in different case is refused as a repeat.
        if (isset($input['claims']) && is_array($input['claims'])) {
            $input['claims'] = array_map(static fn (mixed $id): mixed => is_string($id) ? strtolower($id) : $id, $input['claims']);
        }
        $data = Validator::make($input, [
            'claims' => ['required', 'array', 'min:1', 'max:'.self::MAX_CLAIMS],
            'claims.*' => ['required', 'uuid', 'distinct'],
        ], [
            'claims.required' => 'Choose at least one claim.',
            'claims.min' => 'Choose at least one claim.',
            'claims.max' => 'Choose at most '.self::MAX_CLAIMS.' claims.',
            'claims.array' => 'Choose at least one claim.',
            'claims.*.uuid' => 'A chosen claim could not be found. Refresh the list and try again.',
            'claims.*.distinct' => 'A claim was chosen twice. Refresh the list and try again.',
        ])->validate();
        $publicIds = array_values(array_map(static fn (mixed $id): string => strtolower((string) $id), $data['claims']));

        $branch = $this->branches->activeBranch($actor);
        abort_unless($branch !== null && $branch->organisation_id === $actor->organisation_id, 404);

        return DB::transaction(function () use ($actor, $branch, $publicIds): array {
            $actor = $this->authority->lock($actor, $branch, self::PERMISSION);
            $claims = PanelClaim::query()->where('organisation_id', $actor->organisation_id)->where('branch_id', $branch->id)
                ->whereIn('public_id', $publicIds)->with(['lines', 'documents'])->orderBy('id')->get();
            abort_unless($claims->count() === count($publicIds), 404);
            if ($claims->pluck('panel_id')->unique()->count() > 1) {
                throw ValidationException::withMessages(['claims' => 'Choose claims of one Panel.']);
            }

            $canSeeIdentity = $actor->can(self::IDENTITY_PERMISSION);
            $identifiers = $canSeeIdentity
                ? DB::table('patient_identifiers')->where('organisation_id', $actor->organisation_id)
                    ->whereIn('patient_id', $claims->pluck('patient_id')->unique()->all())->whereNull('retired_at')
                    ->orderByDesc('id')->get(['patient_id', 'identifier_type', 'normalized_value'])->unique('patient_id')->keyBy('patient_id')
                : collect();
            $submitted = DB::table('panel_claim_events')->where('organisation_id', $actor->organisation_id)->where('branch_id', $branch->id)
                ->whereIn('panel_claim_id', $claims->pluck('id')->all())->where('event_type', 'submitted')
                ->orderBy('occurred_at')->orderBy('id')->get(['panel_claim_id', 'occurred_at'])->unique('panel_claim_id')->keyBy('panel_claim_id');

            $rows = $claims->map(function (PanelClaim $claim) use ($branch, $identifiers, $submitted, $canSeeIdentity): array {
                $subtotal = (int) $claim->lines->sum('line_total_sen');
                $identifier = $identifiers->get($claim->patient_id);
                $submittedAt = $submitted->get($claim->id)?->occurred_at;

                return [
                    'publicId' => $claim->public_id,
                    'claimNo' => (string) $claim->id,
                    'status' => PanelClaimDirectoryService::statusLabel($claim),
                    'patientName' => (string) $claim->patient_name_snapshot,
                    'identifierType' => $identifier === null ? null : strtoupper((string) $identifier->identifier_type),
                    'identifier' => $identifier === null ? null : (string) $identifier->normalized_value,
                    'identityHidden' => ! $canSeeIdentity,
                    'memberReference' => $claim->member_reference,
                    'visitNumber' => (string) $claim->visit_number,
                    'visitDate' => $claim->visit_date->format('j M Y'),
                    'invoiceNumber' => (string) $claim->invoice_number,
                    'submittedOn' => $submittedAt === null ? null : CarbonImmutable::parse((string) $submittedAt)->setTimezone($branch->timezone)->format('j M Y'),
                    'remarks' => $claim->remarks,
                    'lines' => $claim->lines->sortBy('line_number')->values()->map(static fn ($line): array => [
                        'number' => (int) $line->line_number,
                        'name' => (string) $line->display_name,
                        'code' => $line->code_snapshot,
                        'quantity' => (string) $line->quantity,
                        'unitPriceSen' => (int) $line->unit_price_sen,
                        'totalSen' => (int) $line->line_total_sen,
                    ])->all(),
                    'invoiceSubtotalSen' => $subtotal,
                    'invoiceAdjustmentSen' => (int) $claim->invoice_total_sen - $subtotal,
                    'invoiceTotalSen' => (int) $claim->invoice_total_sen,
                    'claimOnlyAdjustmentSen' => max(0, (int) $claim->claim_amount_sen - (int) $claim->invoice_total_sen),
                    'claimAmountSen' => (int) $claim->claim_amount_sen,
                    'photos' => $claim->documents->sortBy('id')->values()->map(static fn ($document): string => (string) $document->original_name)->all(),
                ];
            })->values()->all();

            $first = $claims->first();
            $this->audit->record('panel_claim.pack_printed', $first, [
                'claim_count' => $claims->count(),
                'claim_ids' => $claims->pluck('id')->map(static fn ($id): int => (int) $id)->all(),
                'panel_id' => (int) $first->panel_id,
                'identity_shown' => $canSeeIdentity,
            ], $actor, $branch);

            $letterhead = $this->letterheads->assemble($branch, static fn (string $asset): string => route('panel-claims.letterhead-logo', ['asset' => $asset], absolute: false));

            return [
                'letterhead' => $letterhead->toArray(),
                'pack' => [
                    'panelName' => (string) $first->panel_name_snapshot,
                    'branchName' => (string) $branch->name,
                    'printedAt' => now()->setTimezone($branch->timezone)->format('j M Y, g:i A'),
                    'claimCount' => $claims->count(),
                    'totalClaimSen' => (int) $claims->sum('claim_amount_sen'),
                    'claims' => $rows,
                ],
            ];
        }, 3);
    }

    /**
     * The logo of this branch's letterhead, for a holder of the claims permission at the active branch.
     *
     * @return array{path: string, mime: string}
     */
    public function logo(User $actor, string $asset): array
    {
        $branch = $this->branches->activeBranch($actor);
        abort_unless($branch !== null && $branch->organisation_id === $actor->organisation_id, 404);

        return DB::transaction(function () use ($actor, $branch, $asset): array {
            $this->authority->lock($actor, $branch, self::PERMISSION);
            $letterhead = $this->letterheads->assemble($branch, static fn (string $id): string => $id);
            abort_unless($letterhead->logo !== null && $letterhead->logo['url'] === strtolower($asset), 404);
            $image = DB::table('business_detail_assets')->where('organisation_id', $actor->organisation_id)
                ->where('public_id', strtolower($asset))->whereNull('retired_at')->first(['path', 'mime']);
            abort_unless($image !== null, 404);

            return ['path' => (string) $image->path, 'mime' => (string) $image->mime];
        }, 3);
    }
}
