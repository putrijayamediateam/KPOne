<?php

namespace Tests\Feature\Queue;

use App\Domain\Access\BranchAccessService;
use App\Domain\Clinical\Services\ConsultationHoldService;
use App\Domain\Organisation\Models\Branch;
use App\Domain\Organisation\Models\Organisation;
use App\Domain\Queue\Models\QueueEntry;
use App\Domain\Queue\Services\OtcQueueService;
use App\Domain\Queue\Services\QueueWaitingCountService;
use App\Domain\Visit\Models\Visit;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Clinical\ClinicalTestCase;

class QueueWaitingCountsTest extends ClinicalTestCase
{
    public function test_own_counts_cannot_be_expanded_by_client_filters_and_return_no_patient_rows(): void
    {
        $ca = $this->actor('ca');
        $doctor = $this->doctor();
        $otherDoctor = $this->doctor();
        $this->send($ca, $this->consultationVisit($ca, $doctor));
        $this->send($ca, $this->consultationVisit($ca, $otherDoctor));
        $this->selectBranch($doctor);
        $auditCount = DB::table('audit_logs')->count();
        $queueVersions = QueueEntry::query()->pluck('lock_version', 'id')->all();

        $this->getJson(route('queue.waiting-counts', [
            'expected_branch_id' => $this->branch->id,
            'scope' => 'branch', 'doctor_id' => $otherDoctor->id,
        ]))->assertOk()->assertExactJson(['branchId' => $this->branch->id, 'own' => 1, 'branch' => null])
            ->assertHeader('Cache-Control', 'max-age=0, must-revalidate, no-store, private');
        $this->assertSame($auditCount, DB::table('audit_logs')->count());
        $this->assertSame($queueVersions, QueueEntry::query()->pluck('lock_version', 'id')->all());
    }

    public function test_branch_count_requires_the_existing_branch_permission(): void
    {
        $ca = $this->actor('ca');
        $doctor = $this->doctor();
        $this->send($ca, $this->consultationVisit($ca, $doctor));
        $this->send($ca, $this->consultationVisit($ca, $this->doctor()));
        $otherBranch = $this->organisation->branches()->whereKeyNot($this->branch->id)->firstOrFail();
        $otherVisit = Visit::factory()->create([
            'branch_id' => $otherBranch->id, 'visit_type' => 'consultation', 'visit_reason' => 'Synthetic other branch reason',
            'assigned_doctor_user_id' => $doctor->id,
        ]);
        QueueEntry::factory()->create(['visit_id' => $otherVisit->id]);
        $this->grantPermissions($doctor, 'queue.view.branch');
        $this->selectBranch($doctor);
        $this->getJson(route('queue.waiting-counts', ['expected_branch_id' => $this->branch->id]))
            ->assertOk()->assertExactJson(['branchId' => $this->branch->id, 'own' => 1, 'branch' => 2]);
    }

    public function test_counts_include_carry_over_at_branch_midnight_and_exclude_future_days(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-14 15:59:59', 'UTC'));
        $ca = $this->actor('ca');
        $doctor = $this->doctor();
        $this->send($ca, $this->consultationVisit($ca, $doctor));
        $this->travelTo(CarbonImmutable::parse('2026-10-14 16:00:00', 'UTC'));
        $this->send($ca, $this->consultationVisit($ca, $doctor));
        $future = $this->send($ca, $this->consultationVisit($ca, $doctor));
        DB::table('queue_entries')->where('id', $future->id)->update(['operational_date' => '2026-10-16']);
        $this->selectBranch($doctor);

        $this->getJson(route('queue.waiting-counts', ['expected_branch_id' => $this->branch->id]))
            ->assertOk()->assertExactJson(['branchId' => $this->branch->id, 'own' => 2, 'branch' => null]);
    }

    public function test_counts_exclude_serving_held_removed_cancelled_and_otc(): void
    {
        [$doctor, $ca, $heldVisit, $heldQueue] = $this->servingFixture();
        $encounter = $this->startEncounter($doctor, $heldVisit, $heldQueue);
        app(ConsultationHoldService::class)->hold($doctor, $heldVisit->refresh(), [
            'expected_branch_id' => $this->branch->id,
            'visit_lock_version' => $heldVisit->lock_version,
            'queue_lock_version' => $heldQueue->lock_version,
            'encounter_lock_version' => $encounter->lock_version,
            'idempotency_key' => (string) Str::uuid(),
        ]);
        $this->send($ca, $this->consultationVisit($ca, $doctor));
        $serving = $this->send($ca, $this->consultationVisit($ca, $doctor));
        DB::table('queue_entries')->where('id', $serving->id)->update(['status' => QueueEntry::STATUS_SERVING, 'called_at' => now(), 'called_by_user_id' => $doctor->id]);
        $removed = $this->send($ca, $this->consultationVisit($ca, $doctor));
        DB::table('queue_entries')->where('id', $removed->id)->update(['status' => QueueEntry::STATUS_REMOVED, 'removed_at' => now()]);
        $cancelled = $this->send($ca, $this->consultationVisit($ca, $doctor));
        DB::table('visits')->where('id', $cancelled->visit_id)->update(['status' => Visit::STATUS_CANCELLED, 'cancelled_at' => now(), 'cancelled_by_user_id' => $ca->id, 'cancellation_reason' => 'Synthetic cancellation']);
        $otcVisit = $this->register($ca, $this->patient($ca));
        app(OtcQueueService::class)->enter($ca, $otcVisit, ['expected_branch_id' => $this->branch->id]);
        $this->selectBranch($doctor);

        $this->getJson(route('queue.waiting-counts', ['expected_branch_id' => $this->branch->id]))
            ->assertOk()->assertExactJson(['branchId' => $this->branch->id, 'own' => 1, 'branch' => null]);
    }

    public function test_branch_and_organisation_mismatch_and_lost_access_are_refused(): void
    {
        $doctor = $this->doctor();
        $this->selectBranch($doctor);
        $otherBranch = $this->organisation->branches()->whereKeyNot($this->branch->id)->firstOrFail();
        $this->getJson(route('queue.waiting-counts', ['expected_branch_id' => $otherBranch->id]))
            ->assertUnprocessable()->assertJsonValidationErrors('expected_branch_id');
        session([BranchAccessService::SESSION_KEY => $otherBranch->id]);
        $this->getJson(route('queue.waiting-counts', ['expected_branch_id' => $otherBranch->id]))->assertUnprocessable()->assertJsonValidationErrors('expected_branch_id');
        $otherOrganisation = Organisation::query()->create(['code' => 'SYN-WAITING', 'name' => 'Synthetic waiting organisation']);
        $outsiderBranch = new Branch;
        $outsiderBranch->forceFill(['organisation_id' => $otherOrganisation->id, 'code' => 'SYN-WAITING', 'name' => 'Synthetic waiting branch', 'timezone' => 'Asia/Kuala_Lumpur', 'is_active' => true])->save();
        session([BranchAccessService::SESSION_KEY => $outsiderBranch->id]);
        $this->getJson(route('queue.waiting-counts', ['expected_branch_id' => $outsiderBranch->id]))->assertUnprocessable()->assertJsonValidationErrors('expected_branch_id');
        $this->selectBranch($doctor);
        DB::table('staff_branch_assignments')->update(['valid_until' => now()->subDays(2)->toDateString(), 'valid_from' => now()->subDays(3)->toDateString()]);
        $this->getJson(route('queue.waiting-counts', ['expected_branch_id' => $this->branch->id]))->assertForbidden();
    }

    public function test_permission_matrix_and_required_branch_context(): void
    {
        foreach (['director', 'ca', 'ca_supervisor', 'resident_doctor'] as $role) {
            $this->selectBranch($this->actor($role));
            $this->getJson(route('queue.waiting-counts', ['expected_branch_id' => $this->branch->id]))
                ->assertOk()->assertExactJson(['branchId' => $this->branch->id, 'own' => 0, 'branch' => $role === 'resident_doctor' ? null : 0]);
            $this->getJson(route('queue.waiting-counts'))->assertUnprocessable()->assertJsonValidationErrors('expected_branch_id');
        }
        foreach (['technical_admin', 'administrator', 'finance_officer', 'panel_officer', 'queue_display'] as $role) {
            $this->selectBranch($this->actor($role));
            $this->getJson(route('queue.waiting-counts', ['expected_branch_id' => $this->branch->id]))->assertForbidden();
        }
    }

    public function test_domain_service_rechecks_permission_without_http_middleware(): void
    {
        $actor = $this->actor('technical_admin');
        $this->selectBranch($actor);
        $this->expectException(AuthorizationException::class);
        app(QueueWaitingCountService::class)->counts($actor, $this->branch->id);
    }
}
