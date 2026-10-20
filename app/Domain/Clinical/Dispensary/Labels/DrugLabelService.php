<?php

namespace App\Domain\Clinical\Dispensary\Labels;

use App\Domain\Access\BranchAccessService;
use App\Domain\Access\TransactionalActorAuthority;
use App\Domain\Clinical\Dispensary\Models\DispensaryCase;
use App\Domain\Clinical\Dispensary\Models\DispensaryHandoff;
use App\Domain\Clinical\Dispensary\Models\DispensaryItem;
use App\Domain\Clinical\Dispensary\Services\DispensarySafetyValidator;
use App\Domain\Clinical\Models\ClinicalEncounterAllergyReview;
use App\Domain\Clinical\Models\PatientAllergyProfile;
use App\Domain\Organisation\BusinessDetails\BranchLetterheadAssembler;
use App\Domain\Organisation\BusinessDetails\BusinessDetailAsset;
use App\Domain\Organisation\Models\Branch;
use App\Domain\Patient\Models\Patient;
use App\Domain\Visit\Models\Visit;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

final class DrugLabelService
{
    public const PERMISSION = 'dispensary.labels.print.branch';

    public function __construct(private BranchAccessService $branches, private TransactionalActorAuthority $authority, private BranchLetterheadAssembler $letterheads, private DrugLabelLayout $layout, private DispensarySafetyValidator $safety) {}

    /** @return array<string, mixed> */
    public function preview(User $actor, DispensaryCase $case, ?string $itemPublicId = null): array
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
            $head = $this->letterheads->assemble($branch, function (string $id) use ($actor): string {
                $asset = BusinessDetailAsset::query()->where('organisation_id', $actor->organisation_id)->where('public_id', $id)->whereNull('retired_at')->firstOrFail();
                if (! Storage::disk('local')->exists($asset->path)) {
                    return '';
                }
                $bytes = Storage::disk('local')->get($asset->path);

                return hash_equals($asset->sha256, hash('sha256', $bytes)) ? 'data:'.$asset->mime.';base64,'.base64_encode($bytes) : '';
            });
            $logo = ($head->logo['url'] ?? '') ?: null;
            $details = $head->details;
            $address = implode(', ', array_filter([$details['address_line_1'], $details['address_line_2'], $details['postcode'], $details['city'], $details['state'], $details['country']], fn ($s) => $s !== null && $s !== ''));
            $contact = implode(' / ', array_filter([$details['phone'], $details['email']]));
            $blocked = [];
            $output = [];
            $manifest = [$branch->id, $branch->timezone, $case->lock_version, $patient->lock_version, $handoff->id, $handoff->status, $head->organisationVersion, $head->branchVersion, $head->logo];
            foreach ($items as $item) {
                if ($item->status === 'partial' && ! $item->exceptions()->where('status', 'acknowledged')->where('expected_item_lock_version', $item->lock_version)->where('proposed_quantity_dispensed', $item->quantity_dispensed)->exists()) {
                    $blocked[] = ['name' => $item->medicine_name_snapshot, 'errors' => ['Partial quantity: current doctor acknowledgement required.']];

                    continue;
                }
                $fields = ['clinic' => $details['clinic_name'], 'address' => $address, 'contact' => $contact, 'patient' => $patient->full_name, 'reference' => $patient->patient_number.' / '.$visit->visit_number,
                    'name' => $item->medicine_name_snapshot, 'strength' => $item->strength_snapshot, 'form' => $item->dosage_form_snapshot, 'quantity' => DrugLabelLayout::displayQuantity($item->quantity_dispensed).' '.$item->unit_snapshot,
                    'dosage' => $item->effective('dosage'), 'frequency' => $item->effective('frequency'), 'duration' => $item->effective('duration'), 'route' => $item->effective('route'), 'instruction' => $item->effective('administration_instruction'), 'precaution' => $item->effective('precaution')];
                $at = $completed ? $handoff->completed_at : $item->handled_at;
                $date = $at ? CarbonImmutable::parse($at)->setTimezone($branch->timezone)->format('d/m/Y') : 'Tidak direkod';
                $render = $this->layout->render($fields, $date, $completed, $logo !== null, now()->setTimezone($branch->timezone)->format('d/m/Y H:i').' ('.$branch->timezone.')');
                $manifest[] = [$item->id, $item->lock_version, $fields, $date, $render['errors']];
                if ($render['errors'] !== []) {
                    $blocked[] = ['name' => $item->medicine_name_snapshot, 'errors' => $render['errors']];
                } else {
                    $output[] = ['name' => $item->medicine_name_snapshot, 'strength' => $item->strength_snapshot, 'quantity' => $item->quantity_dispensed, 'unit' => $item->unit_snapshot, 'dosage' => $item->effective('dosage'), 'frequency' => $item->effective('frequency'), 'duration' => $item->effective('duration'), 'instruction' => $item->effective('administration_instruction'), 'lines' => $render['lines'], 'emphasis' => $render['emphasis'], 'previewUrl' => route('dispensary.items.label', [$case, $item->public_id])];
                }
            }

            return ['clinic' => $details['clinic_name'], 'branch' => $branch->name, 'patient' => ['name' => $patient->full_name, 'patientNumber' => $patient->patient_number], 'date' => now()->setTimezone($branch->timezone)->format('d/m/Y H:i').' ('.$branch->timezone.')',
                'items' => $output, 'blocked' => $blocked, 'logo' => $logo, 'pageSize' => DrugLabelLayout::DRUG_LABEL_PAGE_SIZE, 'token' => hash_hmac('sha256', json_encode($manifest, JSON_THROW_ON_ERROR), (string) config('app.key')), 'branchId' => $branch->id, 'excludedCount' => $itemPublicId === null ? $all->count() - $items->count() : 0];
        }, 3);
    }
}
