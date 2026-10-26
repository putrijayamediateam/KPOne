<?php

namespace Tests\Feature\Clinical;

use App\Domain\Clinical\Services\ConsultationHoldService;
use App\Domain\Clinical\Services\ConsultationLocks;
use App\Domain\Organisation\Models\Branch;
use App\Domain\Queue\Services\QueueEntryService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;
use Tests\Support\StaffBranchAssignmentBootstrapper;

class PilotConsultationTest extends ClinicalTestCase
{
    public function test_custom_role_with_permission_can_hold_and_resume_its_own_consultation(): void
    {
        [$doctor, , $visit, $queue] = $this->servingFixture();
        $encounter = $this->startEncounter($doctor, $visit, $queue);
        $role = Role::findOrCreate('synthetic_hold_doctor', 'web');
        $role->givePermissionTo('consultations.hold.own');
        $doctor->syncRoles([$role]);
        $input = fn () => ['expected_branch_id' => $visit->branch_id, 'visit_lock_version' => $visit->refresh()->lock_version, 'queue_lock_version' => $queue->refresh()->lock_version, 'encounter_lock_version' => $encounter->refresh()->lock_version, 'idempotency_key' => (string) Str::uuid()];
        $hold = app(ConsultationHoldService::class)->hold($doctor, $visit, $input());
        $this->assertNull($hold->resumed_at);
        $resumed = app(ConsultationHoldService::class)->resume($doctor, $visit, $input());
        $this->assertNotNull($resumed->resumed_at);
    }

    public function test_resident_doctor_without_hold_permission_is_denied_using_fresh_authority(): void
    {
        [$doctor, , $visit, $queue] = $this->servingFixture();
        $encounter = $this->startEncounter($doctor, $visit, $queue);
        $doctor->roles()->firstOrFail()->revokePermissionTo('consultations.hold.own');
        $this->expectException(AuthorizationException::class);
        app(ConsultationHoldService::class)->hold($doctor, $visit, ['expected_branch_id' => $visit->branch_id, 'visit_lock_version' => $visit->lock_version, 'queue_lock_version' => $queue->lock_version, 'encounter_lock_version' => $encounter->lock_version, 'idempotency_key' => (string) Str::uuid()]);
    }

    public function test_held_consultation_allows_cross_branch_call_and_three_hold_limit_is_unchanged(): void
    {
        [$doctor, , $first, $queue] = $this->servingFixture();
        $encounter = $this->startEncounter($doctor, $first, $queue);
        $this->holdEncounter($doctor, $first, $queue, $encounter);
        $other = Branch::where('organisation_id', $this->organisation->id)->whereKeyNot($first->branch_id)->orderBy('id')->firstOrFail();
        StaffBranchAssignmentBootstrapper::create($doctor->staffProfile, $other);
        $this->app->forgetScopedInstances();
        $this->branch = $other;
        $ca = $this->actor('ca', $other);
        for ($index = 0; $index < 3; $index++) {
            $this->selectBranch($ca, $other);
            [$doctor, , $visit, $queue] = $this->servingFixture($doctor, $ca);
            $this->assertSame('serving', $queue->status);
            $this->assertSame($other->id, $visit->branch_id);
            $encounter = $this->startEncounter($doctor, $visit, $queue);
            if ($index < 2) {
                $this->holdEncounter($doctor, $visit, $queue, $encounter);
            } else {
                try {
                    $this->holdEncounter($doctor, $visit, $queue, $encounter);
                    $this->fail('A fourth hold across branches was allowed.');
                } catch (ValidationException $exception) {
                    $this->assertStringContainsString('3 pesakit On Hold', $exception->errors()['hold'][0]);
                }
            }
        }
        $this->assertDatabaseCount('consultation_holds', 3);
    }

    public function test_cross_branch_busy_is_boolean_and_ca_refusal_contains_no_other_patient_data(): void
    {
        [$doctor, , $first, $firstQueue] = $this->servingFixture();
        $encounter = $this->startEncounter($doctor, $first, $firstQueue);
        $other = Branch::where('organisation_id', $this->organisation->id)->where('id', '!=', $this->branch->id)->orderBy('id')->firstOrFail();
        StaffBranchAssignmentBootstrapper::create($doctor->staffProfile, $other);
        $this->branch = $other;
        $ca = $this->actor('ca', $other);
        $this->grantPermissions($ca, 'queue.call.branch');
        $next = $this->consultationVisit($ca, $doctor);
        $nextQueue = $this->send($ca, $next);
        $this->assertTrue(ConsultationLocks::doctorBusy($doctor));
        $input = ['expected_branch_id' => $other->id, 'visit_lock_version' => $next->lock_version, 'queue_lock_version' => $nextQueue->lock_version];
        try {
            app(QueueEntryService::class)->call($ca, $next, $input);
            $this->fail('Cross-branch double consultation was allowed.');
        } catch (ValidationException $error) {
            $message = implode(' ', $error->errors()['queue']);
            $this->assertSame('Doktor sedang menjalankan konsultasi lain. Tahan atau selesaikan konsultasi tersebut dahulu.', $message);
            foreach ([$first->patient->full_name, $first->patient->patient_number, $first->visit_number] as $private) {
                $this->assertStringNotContainsString($private, $message);
            }
        }
        $this->selectBranch($doctor, $first->branch);
        app(ConsultationHoldService::class)->hold($doctor, $first, ['expected_branch_id' => $first->branch_id, 'visit_lock_version' => $first->refresh()->lock_version, 'queue_lock_version' => $firstQueue->refresh()->lock_version, 'encounter_lock_version' => $encounter->refresh()->lock_version, 'idempotency_key' => (string) Str::uuid()]);
        $this->assertFalse(ConsultationLocks::doctorBusy($doctor));
        $this->selectBranch($ca, $other);
        $called = app(QueueEntryService::class)->call($ca, $next, $input);
        $this->assertSame('serving', $called->status);
        $this->assertTrue(ConsultationLocks::doctorBusy($doctor));
        $this->selectBranch($doctor, $first->branch);
        try {
            app(ConsultationHoldService::class)->resume($doctor, $first, ['expected_branch_id' => $first->branch_id, 'visit_lock_version' => $first->refresh()->lock_version, 'queue_lock_version' => $firstQueue->refresh()->lock_version, 'encounter_lock_version' => $encounter->refresh()->lock_version, 'idempotency_key' => (string) Str::uuid()]);
            $this->fail('Cross-branch busy doctor resumed another consultation.');
        } catch (ValidationException $error) {
            $this->assertArrayHasKey('hold', $error->errors());
        }
    }
}
