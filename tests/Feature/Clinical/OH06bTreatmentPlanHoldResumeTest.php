<?php

declare(strict_types=1);

namespace Tests\Feature\Clinical;

use App\Domain\Clinical\Models\MedicineCatalogueItem;
use App\Domain\Clinical\Services\ClinicalEncounterDirectoryService;
use App\Domain\Clinical\Services\ConsultationHoldService;
use App\Domain\Clinical\Services\PatientAllergyService;
use App\Domain\Clinical\Services\TreatmentPlanService;
use App\Domain\Visit\Models\Visit;
use App\Models\User;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * OH-06b: the same investigation that found the clinical note re-sync gap in
 * Clinical/Show.vue (OH-06) also flagged Clinical/Partials/TreatmentPlanPanel.vue
 * as exhibiting the same pattern - its medicines/services draft `form` was
 * seeded once from `props.plan` and only ever re-synced inside its own save's
 * onSuccess, with no watcher reacting to a Hold/Resume on the parent page.
 *
 * These tests prove the treatment plan draft is never lost or overwritten at
 * the persistence/API layer through hold, an intervening second patient's
 * hold, and resume - and that the backend rejects (rather than silently
 * accepts) a save sent with a stale plan lock_version, exactly mirroring
 * OH06ClinicalNoteHoldResumeTest.php for the clinical note.
 */
class OH06bTreatmentPlanHoldResumeTest extends ClinicalTestCase
{
    public function test_treatment_plan_draft_survives_hold_a_second_patients_hold_and_resume(): void
    {
        $doctor = $this->doctor();
        $ca = $this->actor('ca');

        [, , $visit, $queue] = $this->servingFixture($doctor, $ca);
        $encounter = $this->startEncounter($doctor, $visit, $queue);
        $this->reviewNoKnown($doctor, $visit);
        $medicine = $this->medicineCatalogue($doctor);
        $plan = app(TreatmentPlanService::class)->save($doctor, $visit, $this->payload(null, [
            $this->medicinePayload($medicine),
        ]));

        $this->holdEncounter($doctor, $visit, $queue, $encounter);
        $this->assertSame(
            'Synthetic dosage',
            $plan->refresh()->medicineOrders()->sole()->dosage,
            'Holding the consultation must not touch the treatment plan.',
        );

        // The same doctor now serves and holds a second patient, mirroring the UAT repro exactly.
        [, , $visitB, $queueB] = $this->servingFixture($doctor, $ca);
        $encounterB = $this->startEncounter($doctor, $visitB, $queueB);
        $this->holdEncounter($doctor, $visitB, $queueB, $encounterB);

        // Resume A.
        $this->selectBranch($doctor, $visit->branch);
        app(ConsultationHoldService::class)->resume($doctor, $visit->refresh(), [
            'expected_branch_id' => $visit->branch_id,
            'visit_lock_version' => $visit->refresh()->lock_version,
            'queue_lock_version' => $queue->refresh()->lock_version,
            'encounter_lock_version' => $encounter->refresh()->lock_version,
            'idempotency_key' => (string) Str::uuid(),
        ]);

        $medicineOrder = $plan->refresh()->medicineOrders()->sole();
        $this->assertSame(
            'Synthetic dosage',
            $medicineOrder->dosage,
            'Resuming the consultation must not touch the treatment plan.',
        );
        $this->assertSame('active', $medicineOrder->status);

        $projected = app(ClinicalEncounterDirectoryService::class)->detail($doctor, $visit->refresh());
        $this->assertCount(
            1,
            $projected['treatmentPlan']['medicines'],
            'The detail projection served to the frontend must still carry the saved medicine order after resume.',
        );
        $this->assertSame('Synthetic dosage', $projected['treatmentPlan']['medicines'][0]['dosage']);
    }

    /**
     * Unlike the clinical note's lock_version (bumped by hold/resume
     * directly), the treatment plan's own lock_version is untouched by
     * hold/resume - only another save on the plan itself advances it. The
     * frontend fix's drift guard exists for the case this simulates: a
     * second, still-mounted browser tab on the same visit that already
     * holds a newer plan.lockVersion (from its own save, or - as the fix
     * ensures - re-synced after a hold/resume visit surfaces one) must never
     * have its own stale save silently accepted.
     */
    public function test_treatment_plan_save_with_a_stale_lock_version_is_rejected_and_never_overwrites_the_current_plan(): void
    {
        $doctor = $this->doctor();
        $ca = $this->actor('ca');

        [, , $visit, $queue] = $this->servingFixture($doctor, $ca);
        $this->startEncounter($doctor, $visit, $queue);
        $this->reviewNoKnown($doctor, $visit);
        $medicine = $this->medicineCatalogue($doctor);
        $plan = app(TreatmentPlanService::class)->save($doctor, $visit, $this->payload(null, [
            $this->medicinePayload($medicine),
        ]));
        $staleLockVersion = $plan->refresh()->lock_version;
        $order = $plan->medicineOrders()->sole();

        // A concurrent save (a second tab, or a second edit from this same
        // tab after a reload) advances the plan's own lock_version.
        $plan = app(TreatmentPlanService::class)->save($doctor, $visit->refresh(), $this->payload(
            $staleLockVersion,
            [[...$this->medicinePayload($medicine, $order->public_id), 'dosage' => 'Concurrently updated dosage']],
        ));
        $this->assertNotSame($staleLockVersion, $plan->lock_version);

        try {
            app(TreatmentPlanService::class)->save($doctor, $visit->refresh(), $this->payload(
                $staleLockVersion,
                [$this->medicinePayload($medicine, $order->public_id)],
            ));
            $this->fail('A treatment plan save using a stale lock_version unexpectedly succeeded.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('lock_version', $exception->errors());
        }

        $this->assertSame(
            'Concurrently updated dosage',
            $plan->refresh()->medicineOrders()->sole()->dosage,
            'A rejected stale save must never overwrite the current treatment plan.',
        );
    }

    private function reviewNoKnown(User $doctor, Visit $visit): void
    {
        $service = app(PatientAllergyService::class);
        $profile = $service->declareNoKnown($doctor, $visit, [
            'expected_branch_id' => $visit->branch_id,
            'profile_lock_version' => null,
        ]);
        $service->review($doctor, $visit, [
            'expected_branch_id' => $visit->branch_id,
            'profile_lock_version' => $profile->lock_version,
        ]);
    }

    private function medicineCatalogue(User $doctor): MedicineCatalogueItem
    {
        $item = new MedicineCatalogueItem;
        $item->forceFill([
            'public_id' => (string) Str::uuid(), 'organisation_id' => $doctor->organisation_id,
            'code' => 'SYN-MED-'.Str::upper(Str::random(8)), 'display_name' => 'Synthetic medicine', 'strength_text' => 'Synthetic strength',
            'dosage_form' => 'Synthetic form', 'order_unit' => 'unit', 'authorisation_class' => MedicineCatalogueItem::AUTHORISATION_DOCTOR_REQUIRED,
            'is_active' => true, 'created_by_user_id' => $doctor->id, 'updated_by_user_id' => $doctor->id,
        ])->save();

        return $item;
    }

    /**
     * @param  list<array<string, mixed>>  $medicines
     * @param  list<array<string, mixed>>  $services
     * @return array<string, mixed>
     */
    private function payload(?int $version, array $medicines = [], array $services = []): array
    {
        return ['expected_branch_id' => $this->branch->id, 'lock_version' => $version, 'medicines' => $medicines, 'services' => $services];
    }

    /** @return array<string, mixed> */
    private function medicinePayload(MedicineCatalogueItem $item, ?string $publicId = null): array
    {
        return ['public_id' => $publicId, 'catalogue_public_id' => $publicId ? null : $item->public_id, 'quantity_ordered' => 1, 'dosage' => 'Synthetic dosage', 'frequency' => 'Synthetic frequency', 'duration' => null, 'route' => null, 'administration_instruction' => null, 'indication' => null, 'precaution' => null];
    }
}
