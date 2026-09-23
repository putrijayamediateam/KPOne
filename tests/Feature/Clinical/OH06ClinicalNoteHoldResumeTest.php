<?php

declare(strict_types=1);

namespace Tests\Feature\Clinical;

use App\Domain\Clinical\Services\ClinicalEncounterDirectoryService;
use App\Domain\Clinical\Services\ClinicalEncounterService;
use App\Domain\Clinical\Services\CompleteConsultationService;
use App\Domain\Clinical\Services\ConsultationHoldService;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * OH-06 investigation (UAT, preview a29a3ad): a doctor reported the clinical note
 * disappearing after Hold -> (hold another patient) -> Resume, and again after
 * a fresh note followed by Complete Consultation.
 *
 * These tests prove the note is never lost at the persistence/API layer: it
 * survives hold, an intervening second patient's hold, resume, a further edit,
 * and Complete Consultation unchanged. Both tests are expected to PASS -
 * confirming the loss the doctor observed is not a backend/data-loss defect.
 * See tests/Frontend/oh-06-clinical-note-resync.test.mjs for the frontend
 * defect this points to instead (Clinical/Show.vue never re-syncs its local
 * `form` state from fresh Inertia props after a `preserveState: true` visit).
 */
class OH06ClinicalNoteHoldResumeTest extends ClinicalTestCase
{
    public function test_clinical_note_survives_hold_then_a_second_patients_hold_then_resume(): void
    {
        $doctor = $this->doctor();
        $ca = $this->actor('ca');

        [, , $visitA, $queueA] = $this->servingFixture($doctor, $ca);
        $encounterA = $this->startEncounter($doctor, $visitA, $queueA);

        $this->selectBranch($doctor, $visitA->branch);
        app(ClinicalEncounterService::class)->update($doctor, $visitA, $this->aggregate($encounterA, [
            'clinical_note' => 'Note A - written before any hold',
        ]));
        $encounterA->refresh();
        $this->assertSame('Note A - written before any hold', $encounterA->clinical_note);

        // Hold A.
        $this->holdEncounter($doctor, $visitA, $queueA, $encounterA);
        $this->assertSame(
            'Note A - written before any hold',
            $encounterA->refresh()->clinical_note,
            'Holding the consultation must not touch the clinical note.',
        );

        // The same doctor now serves and holds a second patient, B - mirroring the UAT repro exactly.
        [, , $visitB, $queueB] = $this->servingFixture($doctor, $ca);
        $encounterB = $this->startEncounter($doctor, $visitB, $queueB);
        $this->holdEncounter($doctor, $visitB, $queueB, $encounterB);

        // Resume A.
        $this->selectBranch($doctor, $visitA->branch);
        app(ConsultationHoldService::class)->resume($doctor, $visitA->refresh(), [
            'expected_branch_id' => $visitA->branch_id,
            'visit_lock_version' => $visitA->refresh()->lock_version,
            'queue_lock_version' => $queueA->refresh()->lock_version,
            'encounter_lock_version' => $encounterA->refresh()->lock_version,
            'idempotency_key' => (string) Str::uuid(),
        ]);

        $this->assertSame(
            'Note A - written before any hold',
            $encounterA->refresh()->clinical_note,
            'Resuming the consultation must not touch the clinical note.',
        );

        $projected = app(ClinicalEncounterDirectoryService::class)->detail($doctor, $visitA->refresh());
        $this->assertSame(
            'Note A - written before any hold',
            $projected['encounter']['clinicalNote'],
            'The detail projection served to the frontend must still carry the saved note after resume.',
        );
    }

    public function test_clinical_note_written_after_resume_survives_complete_consultation(): void
    {
        $doctor = $this->doctor();
        $ca = $this->actor('ca');

        [, , $visitA, $queueA] = $this->servingFixture($doctor, $ca);
        $encounterA = $this->startEncounter($doctor, $visitA, $queueA);
        $this->holdEncounter($doctor, $visitA, $queueA, $encounterA);

        $this->selectBranch($doctor, $visitA->branch);
        app(ConsultationHoldService::class)->resume($doctor, $visitA->refresh(), [
            'expected_branch_id' => $visitA->branch_id,
            'visit_lock_version' => $visitA->refresh()->lock_version,
            'queue_lock_version' => $queueA->refresh()->lock_version,
            'encounter_lock_version' => $encounterA->refresh()->lock_version,
            'idempotency_key' => (string) Str::uuid(),
        ]);

        app(ClinicalEncounterService::class)->update($doctor, $visitA->refresh(), $this->aggregate($encounterA->refresh(), [
            'clinical_note' => 'Note A2 - written after resume',
        ]));
        $encounterA->refresh();
        $this->assertSame('Note A2 - written after resume', $encounterA->clinical_note);

        app(CompleteConsultationService::class)->complete($doctor, $visitA->refresh(), [
            'expected_branch_id' => $visitA->branch_id,
            'visit_lock_version' => $visitA->refresh()->lock_version,
            'queue_lock_version' => $queueA->refresh()->lock_version,
            'encounter_lock_version' => $encounterA->refresh()->lock_version,
            'lock_version' => null,
            'service_deliveries' => [],
        ]);

        $this->assertSame(
            'Note A2 - written after resume',
            $encounterA->refresh()->clinical_note,
            'Complete Consultation must not touch the clinical note.',
        );
    }

    /**
     * The frontend fix (Show.vue) never sends a save with a stale
     * lock_version once it detects drift; this test is the backend half of
     * that safety net - proof that even if a stale save were ever sent (a
     * bug in the guard, a replayed request, a second tab), the backend
     * rejects it outright rather than silently overwriting whatever changed
     * elsewhere in the meantime.
     */
    public function test_save_with_a_stale_lock_version_is_rejected_and_never_overwrites_the_current_note(): void
    {
        $doctor = $this->doctor();
        $ca = $this->actor('ca');

        [, , $visit, $queue] = $this->servingFixture($doctor, $ca);
        $encounter = $this->startEncounter($doctor, $visit, $queue);

        $this->selectBranch($doctor, $visit->branch);
        app(ClinicalEncounterService::class)->update($doctor, $visit, $this->aggregate($encounter, [
            'clinical_note' => 'Note before the hold',
        ]));
        $staleLockVersion = $encounter->refresh()->lock_version;

        // Hold then resume bumps the encounter's lock_version twice, exactly
        // as it would while a second, still-mounted browser tab kept using
        // the pre-hold lock_version it last saw.
        $this->holdEncounter($doctor, $visit, $queue, $encounter);
        $this->selectBranch($doctor, $visit->branch);
        app(ConsultationHoldService::class)->resume($doctor, $visit->refresh(), [
            'expected_branch_id' => $visit->branch_id,
            'visit_lock_version' => $visit->refresh()->lock_version,
            'queue_lock_version' => $queue->refresh()->lock_version,
            'encounter_lock_version' => $encounter->refresh()->lock_version,
            'idempotency_key' => (string) Str::uuid(),
        ]);
        $this->assertNotSame($staleLockVersion, $encounter->refresh()->lock_version);

        try {
            app(ClinicalEncounterService::class)->update($doctor, $visit->refresh(), $this->aggregate($encounter, [
                'lock_version' => $staleLockVersion,
                'clinical_note' => 'Stale overwrite attempt - must never persist',
            ]));
            $this->fail('A save using a stale lock_version unexpectedly succeeded.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('lock_version', $exception->errors());
        }

        $this->assertSame(
            'Note before the hold',
            $encounter->refresh()->clinical_note,
            'A rejected stale save must never overwrite the current note.',
        );
    }
}
