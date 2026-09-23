<?php

declare(strict_types=1);

namespace Tests\Feature\Clinical;

use App\Domain\Clinical\Models\ConsultationHold;
use App\Domain\Clinical\Services\ClinicalEncounterService;
use App\Domain\Clinical\Services\ConsultationHoldService;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * OH-06c: the On Hold button in Clinical/Show.vue now guards unsaved
 * clinical work (note/vitals/diagnoses, and the Treatment Plan draft) behind
 * a confirmation dialog with three choices - Save and hold, Hold without
 * saving, Cancel - instead of letting Hold silently strand it (OH-06 fixed
 * the *display* drift; this fixes the *loss* itself).
 *
 * These tests verify the backend halves of that new sequence: the exact
 * order of calls the frontend now makes (update() then hold(), or hold()
 * alone) still behaves correctly at the persistence layer. No backend code
 * changed for OH-06c - this is regression coverage for an assumption the new
 * frontend flow depends on.
 */
class OH06cUnsavedWorkGuardTest extends ClinicalTestCase
{
    public function test_save_and_hold_sequence_persists_the_note_and_ends_up_held(): void
    {
        $doctor = $this->doctor();
        $ca = $this->actor('ca');
        [, , $visit, $queue] = $this->servingFixture($doctor, $ca);
        $encounter = $this->startEncounter($doctor, $visit, $queue);

        $this->selectBranch($doctor, $visit->branch);
        app(ClinicalEncounterService::class)->update($doctor, $visit, $this->aggregate($encounter, [
            'clinical_note' => 'Save and hold - note to persist',
        ]));
        $encounter->refresh();
        $this->assertSame('Save and hold - note to persist', $encounter->clinical_note);

        app(ConsultationHoldService::class)->hold($doctor, $visit->refresh(), [
            'expected_branch_id' => $visit->branch_id,
            'visit_lock_version' => $visit->refresh()->lock_version,
            'queue_lock_version' => $queue->refresh()->lock_version,
            'encounter_lock_version' => $encounter->refresh()->lock_version,
            'idempotency_key' => (string) Str::uuid(),
        ]);

        $this->assertSame(
            'Save and hold - note to persist',
            $encounter->refresh()->clinical_note,
            'The note saved immediately before the hold must survive it.',
        );
        $this->assertTrue(
            ConsultationHold::query()
                ->where('clinical_encounter_id', $encounter->id)
                ->whereNull('resumed_at')
                ->exists(),
            'The consultation must end up held once the save that preceded it succeeded.',
        );
    }

    public function test_a_failed_save_never_holds_and_keeps_the_original_note(): void
    {
        $doctor = $this->doctor();
        $ca = $this->actor('ca');
        [, , $visit, $queue] = $this->servingFixture($doctor, $ca);
        $encounter = $this->startEncounter($doctor, $visit, $queue);

        $this->selectBranch($doctor, $visit->branch);
        app(ClinicalEncounterService::class)->update($doctor, $visit, $this->aggregate($encounter, [
            'clinical_note' => 'Original note before the stale attempt',
        ]));
        $staleLockVersion = $encounter->refresh()->lock_version;

        // A concurrent edit (a second tab, or - as OH-06's own fix produces -
        // a resync after some other change) advances the encounter's
        // lock_version, exactly the condition "Save and hold" must fail
        // safely under.
        app(ClinicalEncounterService::class)->update($doctor, $visit->refresh(), $this->aggregate($encounter, [
            'clinical_note' => 'Concurrently saved note',
        ]));
        $this->assertNotSame($staleLockVersion, $encounter->refresh()->lock_version);

        try {
            app(ClinicalEncounterService::class)->update($doctor, $visit->refresh(), $this->aggregate($encounter, [
                'lock_version' => $staleLockVersion,
                'clinical_note' => 'Save and hold attempt that must be rejected',
            ]));
            $this->fail('A save using a stale lock_version unexpectedly succeeded.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('lock_version', $exception->errors());
        }

        $this->assertSame(
            'Concurrently saved note',
            $encounter->refresh()->clinical_note,
            'A rejected stale save must never overwrite the current note.',
        );
        $this->assertFalse(
            ConsultationHold::query()->where('clinical_encounter_id', $encounter->id)->exists(),
            'The consultation must not be held when the save that was meant to precede it failed.',
        );
    }

    public function test_hold_without_saving_holds_while_leaving_the_database_note_unchanged(): void
    {
        $doctor = $this->doctor();
        $ca = $this->actor('ca');
        [, , $visit, $queue] = $this->servingFixture($doctor, $ca);
        $encounter = $this->startEncounter($doctor, $visit, $queue);

        $this->selectBranch($doctor, $visit->branch);
        app(ClinicalEncounterService::class)->update($doctor, $visit, $this->aggregate($encounter, [
            'clinical_note' => 'Note already persisted before the doctor typed more',
        ]));
        $encounter->refresh();

        // "Hold without saving" never calls update() at all - the doctor's
        // further, never-submitted edits exist only in the browser and are
        // discarded there. The backend simply holds.
        app(ConsultationHoldService::class)->hold($doctor, $visit->refresh(), [
            'expected_branch_id' => $visit->branch_id,
            'visit_lock_version' => $visit->refresh()->lock_version,
            'queue_lock_version' => $queue->refresh()->lock_version,
            'encounter_lock_version' => $encounter->refresh()->lock_version,
            'idempotency_key' => (string) Str::uuid(),
        ]);

        $this->assertSame(
            'Note already persisted before the doctor typed more',
            $encounter->refresh()->clinical_note,
            'Holding must never touch the database note - there is nothing server-side to discard.',
        );
        $this->assertTrue(
            ConsultationHold::query()
                ->where('clinical_encounter_id', $encounter->id)
                ->whereNull('resumed_at')
                ->exists(),
        );
    }
}
