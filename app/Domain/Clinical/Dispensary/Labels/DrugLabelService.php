<?php

namespace App\Domain\Clinical\Dispensary\Labels;

use App\Domain\Access\BranchAccessService;
use App\Domain\Access\TransactionalActorAuthority;
use App\Domain\Audit\AuditRecorder;
use App\Domain\Clinical\Dispensary\Models\DispensaryCase;
use App\Domain\Clinical\Dispensary\Models\DispensaryHandoff;
use App\Domain\Clinical\Dispensary\Models\DispensaryItem;
use App\Domain\Clinical\Dispensary\Models\DispensaryItemBatchAllocation;
use App\Domain\Clinical\Dispensary\Models\DispensaryLabelPrint;
use App\Domain\Clinical\Dispensary\Services\DispensarySafetyValidator;
use App\Domain\Clinical\Models\ClinicalEncounterAllergyReview;
use App\Domain\Clinical\Models\MedicineCatalogueItem;
use App\Domain\Clinical\Models\PatientAllergyProfile;
use App\Domain\Organisation\BusinessDetails\BranchLetterheadAssembler;
use App\Domain\Organisation\Inventory\Models\InventoryBatch;
use App\Domain\Organisation\Models\Branch;
use App\Domain\Patient\Models\Patient;
use App\Domain\Visit\Models\Visit;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class DrugLabelService
{
    public const PERMISSION = 'dispensary.labels.print.branch';

    public const PLACEHOLDER_EXPIRY = '9999-12-31';

    public function __construct(private BranchAccessService $branches, private TransactionalActorAuthority $authority, private BranchLetterheadAssembler $letterheads, private DrugLabelLayout $layout, private DispensarySafetyValidator $safety, private AuditRecorder $audit) {}

    /** @return array<string, mixed> */
    public function preview(User $actor, DispensaryCase $case, ?string $itemPublicId = null): array
    {
        return $this->build($actor, $case, $itemPublicId)['labels'];
    }

    /**
     * DSP-2: the same preview plus, separately, why batch and expiry are not printed on some lines.
     * Kept beside the label payload (not inside it) so the label contract and its token are unchanged.
     *
     * DSP-6: also the preview URLs of labels printed before (they print with the REPRINT mark).
     *
     * @return array{labels: array<string, mixed>, batchNotes: list<array{name: string, previewUrl: string, note: string}>, reprints: list<string>}
     */
    public function previewWithBatchNotes(User $actor, DispensaryCase $case, ?string $itemPublicId = null): array
    {
        $built = $this->build($actor, $case, $itemPublicId);

        return ['labels' => $built['labels'], 'batchNotes' => $built['batchNotes'], 'reprints' => $built['reprints']];
    }

    /**
     * DSP-6: the fresh check before the browser print dialog (owner decision: a press of "Print current labels"
     * counts as a print, because KPOne cannot see the printer). Under the same locks as the preview it refuses a
     * stale or blocked label, then appends one numbered print row per label (append-only, no label content) and a
     * structural audit with counts only. Returns the preview URLs of labels in this print that are reprints.
     *
     * @return list<string>
     */
    public function recordPrint(User $actor, DispensaryCase $case, ?string $itemPublicId, string $token, int $branchId): array
    {
        return DB::transaction(function () use ($actor, $case, $itemPublicId, $token, $branchId): array {
            $built = $this->build($actor, $case, $itemPublicId);
            $labels = $built['labels'];
            abort_unless($branchId === $labels['branchId'] && hash_equals($labels['token'], $token) && $labels['blocked'] === [], 409);
            $reprints = [];
            $now = now()->utc();
            foreach ($built['printable'] as [$item, $previewUrl]) {
                // The case row is locked by build(), so print numbers per line cannot race.
                $number = 1 + (int) DispensaryLabelPrint::query()->where('dispensary_item_id', $item->id)->max('print_number');
                (new DispensaryLabelPrint)->forceFill(['public_id' => (string) Str::uuid(), 'organisation_id' => $item->organisation_id, 'branch_id' => $labels['branchId'],
                    'dispensary_case_id' => $case->id, 'dispensary_item_id' => $item->id, 'print_number' => $number, 'printed_by_user_id' => $actor->id, 'printed_at' => $now])->save();
                if ($number > 1) {
                    $reprints[] = $previewUrl;
                }
            }
            $branch = Branch::query()->whereKey($labels['branchId'])->firstOrFail();
            $this->audit->record('dispensary.labels_printed', $case, ['label_count' => count($built['printable']), 'reprint_count' => count($reprints)], $actor, $branch);

            return $reprints;
        }, 3);
    }

    /** @return array{labels: array<string, mixed>, batchNotes: list<array{name: string, previewUrl: string, note: string}>, printable: list<array{0: DispensaryItem, 1: string}>, reprints: list<string>} */
    private function build(User $actor, DispensaryCase $case, ?string $itemPublicId): array
    {
        return DB::transaction(function () use ($actor, $case, $itemPublicId): array {
            $branch = $this->branches->activeBranch($actor);
            abort_unless($branch && $branch->is_active && $case->organisation_id === $actor->organisation_id && $case->branch_id === $branch->id, 404);
            $actor = $this->authority->lock($actor, $branch, self::PERMISSION);
            abort_unless($actor->getAllPermissions()->contains('name', self::PERMISSION) && $actor->getAllPermissions()->contains('name', 'dispensary.view.branch'), 403);
            $branch = Branch::query()->whereKey($branch->id)->where('is_active', true)->lockForUpdate()->firstOrFail();
            $patient = Patient::query()->whereKey($case->visit()->value('patient_id'))->where('organisation_id', $actor->organisation_id)->lockForUpdate()->firstOrFail();
            $visit = Visit::query()->whereKey($case->visit_id)->where('patient_id', $patient->id)->where('branch_id', $branch->id)->lockForUpdate()->firstOrFail();
            abort_if($visit->status === Visit::STATUS_CANCELLED, 404);
            $case = DispensaryCase::query()->whereKey($case->id)->lockForUpdate()->firstOrFail();
            $completed = $case->status === DispensaryCase::STATUS_COMPLETED;
            abort_unless($completed || ($case->status === DispensaryCase::STATUS_DISPENSING && $case->current_handler_user_id === $actor->id), 404);
            $handoff = $case->handoffs()->orderByDesc('attempt_number')->lockForUpdate()->firstOrFail();
            abort_unless($handoff->status === ($completed ? DispensaryHandoff::STATUS_COMPLETED : DispensaryHandoff::STATUS_OPEN), 404);
            $all = $handoff->items()->orderBy('id')->lockForUpdate()->get();
            if (! $completed) {
                if ($case->case_type === DispensaryCase::TYPE_OTC) {
                    abort_unless($handoff->otc_allergy_confirmed_at !== null, 409);
                } else {
                    $profile = PatientAllergyProfile::query()->where('patient_id', $patient->id)->lockForUpdate()->firstOrFail();
                    $encounter = $case->encounter()->lockForUpdate()->firstOrFail();
                    $plan = $case->treatmentPlan()->lockForUpdate()->firstOrFail();
                    $review = ClinicalEncounterAllergyReview::query()->where('clinical_encounter_id', $encounter->id)->lockForUpdate()->first();
                    $this->safety->assertCurrent($encounter, $profile, $review, $plan, $handoff, $all);
                }
            }
            $items = $all->filter(fn (DispensaryItem $item): bool => ($itemPublicId === null || $item->public_id === $itemPublicId) && $item->change_state !== 'removed' && in_array($item->status, ['dispensed', 'partial'], true) && $item->quantity_dispensed !== null && (float) $item->quantity_dispensed > 0);
            abort_if($items->isEmpty(), 404);
            // DSP-10: the label prints no logo, so the logo file is never read here.
            $head = $this->letterheads->assemble($branch, fn (string $id): string => '');
            $details = $head->details;
            $address = implode(', ', array_filter([$details['address_line_1'], $details['address_line_2'], $details['postcode'], $details['city'], $details['state'], $details['country']], fn ($s) => $s !== null && $s !== ''));
            $blocked = [];
            $output = [];
            $batchNotes = [];
            $printable = [];
            $reprints = [];
            $manifest = [$branch->id, $branch->timezone, $case->lock_version, $patient->lock_version, $handoff->id, $handoff->status, $head->organisationVersion, $head->branchVersion];
            foreach ($items as $item) {
                if ($item->status === 'partial' && ! $item->exceptions()->where('status', 'acknowledged')->where('expected_item_lock_version', $item->lock_version)->where('proposed_quantity_dispensed', $item->quantity_dispensed)->exists()) {
                    $blocked[] = ['name' => $item->medicine_name_snapshot, 'errors' => ['Partial quantity: current doctor acknowledgement required.']];

                    continue;
                }
                [$batch, $batchNote] = $this->singleBatch($item);
                // DSP-10 (owner decisions 2026-11-06): the clinic's landscape label. Phone only; the medicine display
                // name with QTY; directions "dosage, frequency, instruction" (route is not printed); the medicine's
                // catalogue indication; EXP only for exactly one batch with a real expiry; no batch number.
                $medicine = MedicineCatalogueItem::query()->whereKey($item->medicine_catalogue_item_id)->where('organisation_id', $item->organisation_id)->first(['id', 'is_controlled', 'default_indication']);
                $fields = ['clinic' => $details['clinic_name'], 'address' => $address, 'phone' => $details['phone'] ?? '', 'patient' => $patient->full_name,
                    'name' => $item->medicine_name_snapshot, 'quantity' => DrugLabelLayout::displayQuantity($item->quantity_dispensed),
                    'dosage' => $item->effective('dosage'), 'frequency' => $item->effective('frequency'), 'instruction' => $item->effective('administration_instruction'),
                    'precaution' => $item->effective('precaution'), 'duration' => $item->effective('duration'), 'indication' => $medicine?->default_indication];
                $expiry = $batch ? CarbonImmutable::parse($batch->expiry_date)->format('d/m/Y') : null;
                $controlled = (bool) $medicine?->is_controlled;
                $at = $completed ? $handoff->completed_at : $item->handled_at;
                $date = $at ? CarbonImmutable::parse($at)->setTimezone($branch->timezone)->format('d/m/Y') : '-';
                $render = $this->layout->render($fields, $date, $expiry, $controlled);
                $manifest[] = [$item->id, $item->lock_version, $fields, $date, $expiry, $controlled, $render['errors']];
                if ($render['errors'] !== []) {
                    $blocked[] = ['name' => $item->medicine_name_snapshot, 'errors' => $render['errors']];
                } else {
                    $previewUrl = route('dispensary.items.label', [$case, $item->public_id]);
                    $printable[] = [$item, $previewUrl];
                    if (DispensaryLabelPrint::query()->where('dispensary_item_id', $item->id)->exists()) {
                        $reprints[] = $previewUrl;
                    }
                    $output[] = ['name' => $item->medicine_name_snapshot, 'strength' => $item->strength_snapshot, 'quantity' => $item->quantity_dispensed, 'unit' => $item->unit_snapshot, 'dosage' => $item->effective('dosage'), 'frequency' => $item->effective('frequency'), 'duration' => $item->effective('duration'), 'instruction' => $item->effective('administration_instruction'), 'lines' => $render['lines'], 'emphasis' => $render['emphasis'], 'ruleAfter' => $render['ruleAfter'], 'controlled' => $render['controlled'], 'previewUrl' => $previewUrl];
                    if ($batchNote !== null) {
                        $batchNotes[] = ['name' => $item->medicine_name_snapshot, 'previewUrl' => $previewUrl, 'note' => $batchNote];
                    }
                }
            }

            return ['batchNotes' => $batchNotes, 'printable' => $printable, 'reprints' => $reprints, 'labels' => ['clinic' => $details['clinic_name'], 'branch' => $branch->name, 'patient' => ['name' => $patient->full_name, 'patientNumber' => $patient->patient_number], 'date' => now()->setTimezone($branch->timezone)->format('d/m/Y H:i').' ('.$branch->timezone.')',
                'items' => $output, 'blocked' => $blocked, 'pageSize' => DrugLabelLayout::DRUG_LABEL_PAGE_SIZE, 'token' => hash_hmac('sha256', json_encode($manifest, JSON_THROW_ON_ERROR), (string) config('app.key')), 'branchId' => $branch->id, 'excludedCount' => $itemPublicId === null ? $all->count() - $items->count() : 0]];
        }, 3);
    }

    /**
     * DSP-2, DSP-10: the batch whose expiry is printed, only when every allocation of this line is the same one batch.
     * Otherwise the label prints "EXP: -" and the preview says why. Read only; stock is unchanged.
     *
     * @return array{0: ?InventoryBatch, 1: ?string}
     */
    private function singleBatch(DispensaryItem $item): array
    {
        // Plain read: the case row lock already serialises every allocation writer.
        $batchIds = DispensaryItemBatchAllocation::query()->where('dispensary_item_id', $item->id)->orderBy('id')->pluck('inventory_batch_id')->unique()->values();
        if ($batchIds->count() !== 1) {
            return [null, $batchIds->isEmpty()
                ? 'Expiry printed as "EXP: -": no batch is allocated to this line.'
                : 'Expiry printed as "EXP: -": more than one batch is allocated to this line.'];
        }

        $batch = InventoryBatch::query()->whereKey($batchIds->first())->where('organisation_id', $item->organisation_id)->firstOrFail();
        // Before STK-3 a batch could carry the 9999-12-31 placeholder instead of a real expiry; never print it as a date.
        if (CarbonImmutable::parse($batch->expiry_date)->format('Y-m-d') === self::PLACEHOLDER_EXPIRY) {
            return [null, 'Expiry printed as "EXP: -": this batch has no real expiry date recorded.'];
        }

        return [$batch, null];
    }
}
