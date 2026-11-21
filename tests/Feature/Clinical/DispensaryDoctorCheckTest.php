<?php

namespace Tests\Feature\Clinical;

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Clinical\Dispensary\Models\DispensaryCase;
use App\Domain\Clinical\Dispensary\Models\DispensaryDoctorCheck;
use App\Domain\Clinical\Dispensary\Models\DispensaryHandoff;
use App\Domain\Clinical\Dispensary\Models\DispensaryItem;
use App\Domain\Clinical\Dispensary\Services\DispensaryService;
use App\Domain\Clinical\Dispensary\Services\DispensarySettingsService;
use App\Domain\Clinical\Dispensary\Services\DoctorDispensaryAttentionService;
use App\Domain\Organisation\Inventory\Models\InventoryBatch;
use App\Domain\Organisation\Inventory\Models\InventoryStockBalance;
use App\Domain\Organisation\Models\Branch;
use App\Domain\Organisation\Models\BranchDispensarySetting;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use LogicException;
use Tests\Support\DoctorCheckFixtures;

/**
 * DSP-23 (owner decision 14 of 20-21 Nov 2026; answers 10 = A, 11 = A, 12 = A, 13 = B, P3): the doctor checks
 * prepared medicine before payment, behind a per-branch switch that defaults to off.
 */
class DispensaryDoctorCheckTest extends ClinicalTestCase
{
    use DoctorCheckFixtures;

    /** @param array<string, mixed> $f @return array<string, mixed> */
    private function base(array $f, array $extra = []): array
    {
        return ['expected_branch_id' => $f['visit']->branch_id, 'case_lock_version' => $f['case']->refresh()->lock_version, 'retry_key' => (string) Str::uuid(), ...$extra];
    }

    /** @param array<string, mixed> $f */
    private function sendCase(array $f): DispensaryCase
    {
        $this->selectBranch($f['ca'], $f['visit']->branch);

        return app(DispensaryService::class)->sendForDoctorCheck($f['ca'], $f['case']->refresh(), $this->base($f));
    }

    /** @param array<string, mixed> $f */
    private function verify(array $f, ?User $by = null, array $extra = []): DispensaryCase
    {
        $by ??= $f['doctor'];
        $this->selectBranch($by, $f['visit']->branch);

        return app(DispensaryService::class)->verifyDoctorCheck($by, $f['case']->refresh(), $this->base($f, $extra));
    }

    private function dispenseMoves(): int
    {
        return DB::table('stock_movements')->where('movement_type', 'dispense')->count();
    }

    public function test_with_the_switch_off_complete_works_exactly_as_before(): void
    {
        $f = $this->prepared();

        app(DispensaryService::class)->complete($f['ca'], $f['case'], ['expected_branch_id' => $f['visit']->branch_id, 'case_lock_version' => $f['case']->lock_version]);

        $case = $f['case']->refresh();
        $this->assertSame(DispensaryCase::STATUS_COMPLETED, $case->status);
        $this->assertNull($case->doctor_check_required);
        $this->assertSame(1, $this->dispenseMoves());
        $this->assertDatabaseCount('dispensary_doctor_checks', 0);
        $this->assertSame(0, BranchDispensarySetting::query()->where('doctor_check_required', true)->count());
    }

    public function test_with_the_switch_on_the_ca_sends_and_the_doctor_verifies_then_stock_moves(): void
    {
        $f = $this->prepared();
        $this->switchOn($f['visit']->branch);
        $balance = InventoryStockBalance::query()->where('inventory_location_id', $f['location']->id)->sole()->quantity;

        try {
            app(DispensaryService::class)->complete($f['ca'], $f['case'], ['expected_branch_id' => $f['visit']->branch_id, 'case_lock_version' => $f['case']->lock_version]);
            $this->fail('Complete must be refused once the branch needs the doctor\'s check.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('Reload the page', $exception->errors()['case'][0]);
        }

        $case = $this->sendCase($f);
        $this->assertSame(DispensaryCase::STATUS_AWAITING_DOCTOR_CHECK, $case->status);
        $this->assertTrue($case->doctor_check_required);
        $this->assertSame($f['ca']->id, $case->sent_for_check_by_user_id);
        $this->assertSame(0, $this->dispenseMoves(), 'nothing is dispensed until the doctor verifies');
        $this->assertSame(1, AuditLog::query()->where('event', 'dispensary.sent_for_doctor_check')->count());

        $verified = $this->verify($f);

        $this->assertSame(DispensaryCase::STATUS_COMPLETED, $verified->status);
        $this->assertSame(1, $this->dispenseMoves());
        $this->assertSame(number_format((float) $balance - 2, 3, '.', ''), InventoryStockBalance::query()->where('inventory_location_id', $f['location']->id)->sole()->quantity);
        $handoff = DispensaryHandoff::query()->where('dispensary_case_id', $f['case']->id)->sole();
        $this->assertSame($f['doctor']->id, $handoff->completed_by_user_id);
        $this->assertEquals($case->sent_for_check_at, $handoff->ca_verified_at, 'ca_verified_at holds the time the CA sent the case');
        $check = DispensaryDoctorCheck::query()->sole();
        $this->assertSame([DispensaryDoctorCheck::AS_DOCTOR, DispensaryDoctorCheck::OUTCOME_VERIFIED], [$check->checked_as, $check->outcome]);
        $this->assertSame(['record_version', 'checked_as', 'override_reason'], array_keys(AuditLog::query()->where('event', 'dispensary.doctor_verified')->sole()->metadata));
        $this->assertSame(1, AuditLog::query()->where('event', 'dispensary.completed')->count());
    }

    public function test_while_waiting_the_ca_cannot_edit_complete_or_return_and_the_doctor_cannot_use_return(): void
    {
        $f = $this->prepared();
        $this->switchOn($f['visit']->branch);
        $case = $this->sendCase($f);
        $item = DispensaryItem::query()->where('dispensary_handoff_id', DispensaryHandoff::query()->where('dispensary_case_id', $case->id)->sole()->id)->sole();

        $this->selectBranch($f['ca'], $f['visit']->branch);
        $attempts = [
            fn () => app(DispensaryService::class)->removeItem($f['ca'], $case->refresh(), $item, ['expected_branch_id' => $f['visit']->branch_id, 'case_lock_version' => $case->refresh()->lock_version, 'item_lock_version' => $item->lock_version]),
            fn () => app(DispensaryService::class)->complete($f['ca'], $case->refresh(), ['expected_branch_id' => $f['visit']->branch_id, 'case_lock_version' => $case->refresh()->lock_version]),
            fn () => app(DispensaryService::class)->returnToDoctor($f['ca'], $case->refresh(), ['expected_branch_id' => $f['visit']->branch_id, 'case_lock_version' => $case->refresh()->lock_version]),
            fn () => app(DispensaryService::class)->sendForDoctorCheck($f['ca'], $case->refresh(), $this->base($f)),
        ];
        foreach ($attempts as $index => $attempt) {
            try {
                $attempt();
                $this->fail("Attempt {$index} must be refused while the case waits.");
            } catch (ValidationException|AuthorizationException) {
                $this->assertSame(DispensaryCase::STATUS_AWAITING_DOCTOR_CHECK, $case->refresh()->status);
            }
        }
        $this->assertSame(0, $this->dispenseMoves());
    }

    public function test_send_back_returns_the_case_to_the_ca_with_the_reason_and_the_check_stays_after_the_switch_is_off(): void
    {
        $f = $this->prepared();
        $this->switchOn($f['visit']->branch);
        $this->sendCase($f);
        $this->selectBranch($f['doctor'], $f['visit']->branch);

        try {
            app(DispensaryService::class)->sendBackDoctorCheck($f['doctor'], $f['case']->refresh(), $this->base($f, ['reason' => 'other']));
            $this->fail('"Other" needs a note.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('note', $exception->errors());
        }
        $sentAt = $f['case']->refresh()->sent_for_check_at;
        $sendKey = $f['case']->sent_for_check_retry_key;
        $back = app(DispensaryService::class)->sendBackDoctorCheck($f['doctor'], $f['case']->refresh(), $this->base($f, ['reason' => 'wrong_quantity', 'note' => 'Synthetic note: two tablets, not three']));

        // Hakim: replaying the CA's original Send after the Send back sends nothing again.
        $this->selectBranch($f['ca'], $f['visit']->branch);
        $replayed = app(DispensaryService::class)->sendForDoctorCheck($f['ca'], $f['case']->refresh(), [...$this->base($f), 'retry_key' => $sendKey]);
        $this->assertSame(DispensaryCase::STATUS_DISPENSING, $replayed->status);
        $this->assertEquals($sentAt, $replayed->sent_for_check_at);
        $this->selectBranch($f['doctor'], $f['visit']->branch);

        $this->assertSame(DispensaryCase::STATUS_DISPENSING, $back->status);
        $this->assertSame($f['ca']->id, $back->current_handler_user_id);
        $this->assertSame(0, $this->dispenseMoves());
        $audit = AuditLog::query()->where('event', 'dispensary.doctor_sent_back')->sole();
        $this->assertSame('wrong_quantity', $audit->metadata['reason']);
        $this->assertStringNotContainsString('Synthetic note', json_encode(AuditLog::query()->get()->toArray(), JSON_THROW_ON_ERROR), 'the note never reaches audit');
        $this->assertSame('Synthetic note: two tablets, not three', DispensaryDoctorCheck::query()->sole()->note_encrypted);
        $this->assertNotSame('Synthetic note: two tablets, not three', DB::table('dispensary_doctor_checks')->value('note_encrypted'), 'stored encrypted');

        // The CA's page shows the reason at the top.
        $this->selectBranch($f['ca'], $f['visit']->branch);
        $this->get(route('dispensary.show', $f['case']))->assertInertia(fn (Assert $page) => $page
            ->where('dispensary.doctorCheck.sentBack.reason', 'wrong_quantity')
            ->where('dispensary.doctorCheck.required', true));

        // The database keeps the flag one way too (Hakim).
        try {
            DB::transaction(fn () => DB::table('dispensary_cases')->where('id', $f['case']->id)->update(['doctor_check_required' => false]));
            $this->fail('A case that needed the check must keep needing it in the database.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('keeps needing it', $exception->getMessage());
        }

        // Turning the switch off doesn't release this case: the next submit is still Send to doctor.
        BranchDispensarySetting::query()->where('branch_id', $f['visit']->branch_id)->update(['doctor_check_required' => false]);
        try {
            app(DispensaryService::class)->complete($f['ca'], $f['case']->refresh(), ['expected_branch_id' => $f['visit']->branch_id, 'case_lock_version' => $f['case']->refresh()->lock_version]);
            $this->fail('A case that needed the check keeps needing it.');
        } catch (ValidationException) {
        }
        $this->sendCase($f);
        $this->assertSame(DispensaryCase::STATUS_COMPLETED, $this->verify($f)->status);
        $this->assertSame(1, $this->dispenseMoves());
    }

    public function test_a_page_opened_with_the_check_on_cannot_send_after_it_is_turned_off(): void
    {
        $f = $this->prepared();
        $this->switchOn($f['visit']->branch, false);

        try {
            $this->sendCase($f);
            $this->fail('Send must be refused when the branch no longer needs the check.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('Reload the page', $exception->errors()['case'][0]);
        }
        $this->assertSame(DispensaryCase::STATUS_DISPENSING, $f['case']->refresh()->status);
    }

    public function test_verify_is_refused_for_another_doctor_a_stale_page_and_another_branch(): void
    {
        $f = $this->prepared();
        $this->switchOn($f['visit']->branch);
        $this->sendCase($f);

        $otherDoctor = $this->doctor();
        try {
            $this->verify($f, $otherDoctor);
            $this->fail('Only the attending doctor may verify.');
        } catch (AuthorizationException) {
        }

        $this->selectBranch($f['doctor'], $f['visit']->branch);
        try {
            app(DispensaryService::class)->verifyDoctorCheck($f['doctor'], $f['case']->refresh(), [...$this->base($f), 'case_lock_version' => $f['case']->refresh()->lock_version - 1]);
            $this->fail('A stale page must be refused.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('case_lock_version', $exception->errors());
        }

        try {
            app(DispensaryService::class)->verifyDoctorCheck($f['doctor'], $f['case']->refresh(), [...$this->base($f), 'expected_branch_id' => $f['visit']->branch_id + 999]);
            $this->fail('Another branch must be refused.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('expected_branch_id', $exception->errors());
        }

        $this->assertSame(DispensaryCase::STATUS_AWAITING_DOCTOR_CHECK, $f['case']->refresh()->status);
        $this->assertDatabaseCount('dispensary_doctor_checks', 0);
        $this->assertSame(0, $this->dispenseMoves());
    }

    public function test_verify_is_refused_when_the_batch_can_no_longer_be_dispensed(): void
    {
        $f = $this->prepared();
        $this->switchOn($f['visit']->branch);
        $this->sendCase($f);
        $f['batch']->forceFill(['status' => InventoryBatch::STATUS_QUARANTINED])->save();

        try {
            $this->verify($f);
            $this->fail('An ineligible batch must stop Verify.');
        } catch (ValidationException $exception) {
            $this->assertSame('This batch can no longer be dispensed. Send the case back so the CA can choose another.', $exception->errors()['allocations'][0]);
        }
        $this->assertSame(DispensaryCase::STATUS_AWAITING_DOCTOR_CHECK, $f['case']->refresh()->status);
        $this->assertDatabaseCount('dispensary_doctor_checks', 0);
        $this->assertSame(0, $this->dispenseMoves());
    }

    public function test_verify_is_refused_after_a_new_allergy_is_recorded_while_the_case_waits(): void
    {
        $f = $this->prepared();
        $this->switchOn($f['visit']->branch);
        $this->sendCase($f);
        // A new allergy recorded elsewhere bumps the profile version, so the encounter review is no longer current.
        DB::table('patient_allergy_profiles')->where('patient_id', $f['visit']->patient_id)->increment('lock_version');

        try {
            $this->verify($f);
            $this->fail('A changed allergy profile must stop Verify.');
        } catch (ValidationException) {
        }
        $this->assertSame(DispensaryCase::STATUS_AWAITING_DOCTOR_CHECK, $f['case']->refresh()->status);
        $this->assertSame(0, $this->dispenseMoves());
    }

    public function test_a_replayed_verify_writes_no_second_dispense_and_a_reused_key_with_other_details_is_refused(): void
    {
        $f = $this->prepared();
        $this->switchOn($f['visit']->branch);
        $this->sendCase($f);
        $payload = $this->base($f);
        $this->selectBranch($f['doctor'], $f['visit']->branch);
        app(DispensaryService::class)->verifyDoctorCheck($f['doctor'], $f['case']->refresh(), $payload);

        $again = app(DispensaryService::class)->verifyDoctorCheck($f['doctor'], $f['case']->refresh(), $payload);

        $this->assertSame(DispensaryCase::STATUS_COMPLETED, $again->status);
        $this->assertSame(1, $this->dispenseMoves());
        $this->assertDatabaseCount('dispensary_doctor_checks', 1);
        $this->expectException(ValidationException::class);
        app(DispensaryService::class)->sendBackDoctorCheck($f['doctor'], $f['case']->refresh(), [...$payload, 'reason' => 'wrong_batch']);
    }

    public function test_a_ca_supervisor_may_check_instead_with_a_reason_but_never_a_case_they_prepared(): void
    {
        $f = $this->prepared();
        $this->switchOn($f['visit']->branch);
        $this->sendCase($f);

        try {
            $this->verify($f, $f['supervisor']);
            $this->fail('A supervisor needs a reason.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('override_reason', $exception->errors());
        }
        try {
            $this->verify($f, $f['supervisor'], ['override_reason' => 'other']);
            $this->fail('"Other" needs a note.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('override_note', $exception->errors());
        }

        $done = $this->verify($f, $f['supervisor'], ['override_reason' => 'doctor_off_shift']);

        $this->assertSame(DispensaryCase::STATUS_COMPLETED, $done->status);
        $check = DispensaryDoctorCheck::query()->sole();
        $this->assertSame([DispensaryDoctorCheck::AS_SUPERVISOR, 'doctor_off_shift'], [$check->checked_as, $check->override_reason]);
        $this->assertSame('doctor_off_shift', AuditLog::query()->where('event', 'dispensary.doctor_verified')->sole()->metadata['override_reason']);
    }

    public function test_a_ca_supervisor_who_sent_the_case_cannot_check_it(): void
    {
        $f = $this->prepared();
        $this->switchOn($f['visit']->branch);
        // The supervisor prepares and sends this case themselves.
        $this->selectBranch($f['supervisor'], $f['visit']->branch);
        $case = DispensaryCase::query()->whereKey($f['case']->id)->sole();
        DB::table('dispensary_cases')->where('id', $case->id)->update(['current_handler_user_id' => $f['supervisor']->id]);
        app(DispensaryService::class)->sendForDoctorCheck($f['supervisor'], $case->refresh(), $this->base($f));

        try {
            $this->verify($f, $f['supervisor'], ['override_reason' => 'doctor_emergency']);
            $this->fail('A supervisor may not check a case they prepared.');
        } catch (AuthorizationException) {
        }
        $this->assertSame(DispensaryCase::STATUS_AWAITING_DOCTOR_CHECK, $f['case']->refresh()->status);
    }

    public function test_the_doctor_sees_the_case_on_the_consultation_page_and_can_act_over_http(): void
    {
        $f = $this->prepared();
        $this->switchOn($f['visit']->branch);
        $this->sendCase($f);
        $encounter = $f['visit']->clinicalEncounter()->sole();
        $encounter->setRelation('visit', $f['visit']->refresh()->load('queueEntry'));
        $this->selectBranch($f['doctor'], $f['visit']->branch);

        $check = app(DoctorDispensaryAttentionService::class)->checkForEncounter($f['doctor'], $encounter);
        $this->assertNotNull($check);
        $this->assertSame('2.000', $check['lines'][0]['prepared']['quantity']);
        $this->assertSame($f['batch']->batch_number, $check['lines'][0]['batches'][0]['batchNumber']);
        $this->assertTrue(app(DoctorDispensaryAttentionService::class)->hasPendingForVisit($f['doctor'], $f['visit']));
        $this->assertNull(app(DoctorDispensaryAttentionService::class)->checkForEncounter($this->doctor(), $encounter));

        $this->post(route('dispensary.doctor-check.send-back', $f['case']), $this->base($f))
            ->assertRedirect(route('encounters.show', $f['visit']))->assertSessionHasErrors('reason');
        $this->post(route('dispensary.doctor-check.verify', $f['case']), $this->base($f))
            ->assertRedirect(route('encounters.show', $f['visit']));
        $this->assertSame(DispensaryCase::STATUS_COMPLETED, $f['case']->refresh()->status);
    }

    public function test_the_waiting_case_is_read_only_for_the_ca_but_printable_and_callable(): void
    {
        $f = $this->prepared();
        $this->switchOn($f['visit']->branch);
        $this->sendCase($f);
        $this->selectBranch($f['ca'], $f['visit']->branch);

        $this->get(route('dispensary.show', $f['case']))->assertInertia(fn (Assert $page) => $page
            ->where('dispensary.status', DispensaryCase::STATUS_AWAITING_DOCTOR_CHECK)
            ->where('dispensary.can.update', false)
            ->where('dispensary.can.complete', false)
            ->where('dispensary.can.return', false)
            ->where('dispensary.canPrintLabels', true)
            ->where('dispensary.tvCall.canCall', true)
            ->where('dispensary.doctorCheck.waiting', true)
            ->where('dispensary.doctorCheck.canCheck', false)
            ->where('dispensary.doctorCheck.preparedByYou', true));
        $this->get(route('dispensary.labels', $f['case']))->assertOk();

        // The other CA Supervisor may check instead, and sees the branch list.
        $this->selectBranch($f['supervisor'], $f['visit']->branch);
        $this->get(route('dispensary.show', $f['case']))->assertInertia(fn (Assert $page) => $page->where('dispensary.doctorCheck.canCheck', true));
        $this->get(route('dispensary.doctor-checks'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Dispensary/DoctorChecks')->where('waiting.total', 1)->where('waiting.rows.0.publicId', $f['case']->public_id));
        $this->selectBranch($f['ca'], $f['visit']->branch);
        $this->get(route('dispensary.doctor-checks'))->assertForbidden();
    }

    public function test_the_switch_is_turned_on_by_the_director_or_the_branch_ca_supervisor_with_a_key_and_audit(): void
    {
        $director = $this->actor('director');
        $this->selectBranch($director, $this->branch);
        $key = (string) Str::uuid();
        $payload = ['expected_branch_id' => $this->branch->id, 'doctor_check_required' => true, 'lock_version' => 1, 'retry_key' => $key];

        app(DispensarySettingsService::class)->setDoctorCheck($director, $payload);
        app(DispensarySettingsService::class)->setDoctorCheck($director, $payload);

        $row = BranchDispensarySetting::query()->where('branch_id', $this->branch->id)->sole();
        $this->assertTrue($row->doctor_check_required);
        $this->assertSame(2, $row->lock_version);
        $audit = AuditLog::query()->where('event', 'dispensary.settings_changed')->sole();
        $this->assertSame(['doctor_check_required' => true, 'record_version' => 2], $audit->metadata);

        $supervisor = $this->actor('ca_supervisor');
        $this->selectBranch($supervisor, $this->branch);
        $this->get(route('dispensary-settings.show'))->assertOk()->assertInertia(fn (Assert $page) => $page->component('Dispensary/Settings')->where('settings.doctorCheckRequired', true));
        $this->put(route('dispensary-settings.update'), ['expected_branch_id' => $this->branch->id, 'doctor_check_required' => false, 'lock_version' => 1, 'retry_key' => (string) Str::uuid()])
            ->assertRedirect(route('dispensary-settings.show'))->assertSessionHasErrors('doctor_check_required');
        $this->put(route('dispensary-settings.update'), ['expected_branch_id' => $this->branch->id, 'doctor_check_required' => false, 'lock_version' => 2, 'retry_key' => (string) Str::uuid()])
            ->assertRedirect(route('dispensary-settings.show'));
        $this->assertFalse($row->refresh()->doctor_check_required);

        foreach (['ca', 'resident_doctor', 'finance_officer', 'administrator'] as $role) {
            $other = $this->actor($role);
            $this->selectBranch($other, $this->branch);
            $this->get(route('dispensary-settings.show'))->assertForbidden();
        }
    }

    public function test_check_rows_are_append_only_and_the_database_refuses_a_check_on_a_case_that_is_not_waiting(): void
    {
        $f = $this->prepared();
        $this->switchOn($f['visit']->branch);
        $this->sendCase($f);
        $this->verify($f);
        $check = DispensaryDoctorCheck::query()->sole();

        try {
            $check->forceFill(['reason' => 'wrong_batch'])->save();
            $this->fail('The model must refuse an update.');
        } catch (LogicException) {
        }
        foreach (['UPDATE dispensary_doctor_checks SET checked_at = now()', 'DELETE FROM dispensary_doctor_checks', 'TRUNCATE dispensary_doctor_checks'] as $sql) {
            try {
                DB::transaction(fn () => DB::statement($sql));
                $this->fail("PostgreSQL must refuse: {$sql}");
            } catch (QueryException) {
            }
        }

        try {
            DB::transaction(fn () => DB::table('dispensary_doctor_checks')->insert([
                'public_id' => (string) Str::uuid(), 'organisation_id' => $check->organisation_id, 'branch_id' => $check->branch_id,
                'dispensary_case_id' => $check->dispensary_case_id, 'dispensary_handoff_id' => $check->dispensary_handoff_id,
                'case_lock_version' => $f['case']->refresh()->lock_version, 'checked_by_user_id' => $f['doctor']->id, 'checked_as' => 'doctor',
                'outcome' => 'verified', 'checked_at' => now(), 'retry_key' => (string) Str::uuid(), 'payload_hash' => str_repeat('a', 64),
            ]));
            $this->fail('PostgreSQL must refuse a check on a completed case.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('Invalid dispensary doctor check source', $exception->getMessage());
        }
    }

    public function test_the_database_refuses_a_doctor_check_from_someone_who_is_not_the_attending_clinician(): void
    {
        $f = $this->prepared();
        $this->switchOn($f['visit']->branch);
        $case = $this->sendCase($f);
        $handoff = DispensaryHandoff::query()->where('dispensary_case_id', $case->id)->sole();

        try {
            DB::transaction(fn () => DB::table('dispensary_doctor_checks')->insert([
                'public_id' => (string) Str::uuid(), 'organisation_id' => $case->organisation_id, 'branch_id' => $case->branch_id,
                'dispensary_case_id' => $case->id, 'dispensary_handoff_id' => $handoff->id, 'case_lock_version' => $case->lock_version,
                'checked_by_user_id' => $this->doctor()->id, 'checked_as' => 'doctor', 'outcome' => 'verified',
                'checked_at' => now(), 'retry_key' => (string) Str::uuid(), 'payload_hash' => str_repeat('a', 64),
            ]));
            $this->fail('PostgreSQL must refuse another doctor.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('attending clinician', $exception->getMessage());
        }
        try {
            DB::transaction(fn () => DB::table('dispensary_cases')->where('id', $case->id)->update(['doctor_check_required' => false]));
            $this->fail('A waiting case must keep doctor_check_required.');
        } catch (QueryException) {
        }
    }

    public function test_the_database_allows_the_doctors_check_only_on_consultation_cases(): void
    {
        $definition = (string) DB::selectOne("SELECT pg_get_constraintdef(oid) AS def FROM pg_constraint WHERE conname = 'dispensary_cases_doctor_check_shape'")->def;

        $this->assertStringContainsString("(case_type)::text = 'consultation'", $definition);
        $this->assertStringContainsString('awaiting_doctor_check', (string) DB::selectOne("SELECT pg_get_constraintdef(oid) AS def FROM pg_constraint WHERE conname = 'dispensary_cases_status_check'")->def);
    }

    public function test_the_permission_migration_is_additive_and_grants_exactly_the_approved_roles(): void
    {
        $holders = fn (string $permission): array => DB::table('role_has_permissions')->join('roles', 'roles.id', '=', 'role_has_permissions.role_id')
            ->join('permissions', 'permissions.id', '=', 'role_has_permissions.permission_id')->where('permissions.name', $permission)->orderBy('roles.name')->pluck('roles.name')->all();

        $this->assertSame(['resident_doctor'], $holders('dispensary.doctor_check.own'));
        $this->assertSame(['ca_supervisor'], $holders('dispensary.doctor_check.override.branch'));
        $this->assertSame(['ca_supervisor', 'director'], $holders('dispensary.settings.manage.branch'));

        (require base_path('database/migrations/2026_11_06_200700_grant_dispensary_doctor_check_permissions.php'))->up();
        $this->assertSame(['resident_doctor'], $holders('dispensary.doctor_check.own'));
    }
}
