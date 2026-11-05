<?php

namespace Tests\Feature\Queue;

use App\Domain\Clinical\Dispensary\Models\DispensaryCase;
use App\Domain\Clinical\Dispensary\Services\DispensaryDirectoryService;
use App\Domain\Clinical\Dispensary\Services\OtcDispensaryService;
use App\Domain\Clinical\Services\CompleteConsultationService;
use App\Domain\Organisation\Models\Branch;
use App\Domain\Organisation\Models\Organisation;
use App\Domain\Queue\Services\QueueDirectoryService;
use App\Domain\Visit\Services\VisitDirectoryService;
use App\Models\User;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Clinical\ClinicalTestCase;

class TabCountsTest extends ClinicalTestCase
{
    public function test_registration_counts_match_every_tab_with_applied_filters_and_dispensary_exception(): void
    {
        $ca = $this->actor('ca');
        $doctor = $this->doctor();
        $waiting = $this->consultationVisit($ca, $doctor, ['priority' => 'urgent']);
        $this->send($ca, $waiting);
        $serving = $this->send($ca, $this->consultationVisit($ca, $doctor));
        $serving->forceFill(['status' => 'serving', 'called_at' => now(), 'called_by_user_id' => $doctor->id])->save();
        $completed = $this->consultationVisit($ca, $doctor);
        $completed->forceFill(['status' => 'completed', 'completed_at' => now(), 'completed_by_user_id' => $ca->id, 'completion_evidence' => ['synthetic' => true]])->save();
        $cancelled = $this->consultationVisit($ca, $doctor);
        $cancelled->forceFill(['status' => 'cancelled', 'cancelled_at' => now(), 'cancelled_by_user_id' => $ca->id, 'cancellation_reason' => 'Synthetic cancellation'])->save();

        // Use the real checkout service to establish Awaiting Billing membership.
        [$billingDoctor, , $billingVisit, $billingQueue] = $this->servingFixture($this->doctor(), $ca);
        $encounter = $this->startEncounter($billingDoctor, $billingVisit, $billingQueue);
        app(CompleteConsultationService::class)->complete($billingDoctor, $billingVisit, [
            'expected_branch_id' => $billingVisit->branch_id, 'visit_lock_version' => $billingVisit->lock_version,
            'queue_lock_version' => $billingQueue->lock_version, 'encounter_lock_version' => $encounter->lock_version,
            'lock_version' => null, 'service_deliveries' => [],
        ]);
        $otc = $this->register($ca, $this->patient($ca));
        $case = app(OtcDispensaryService::class)->open($ca, $otc, ['expected_branch_id' => $this->branch->id]);
        $otc->forceFill(['registered_at' => now()->subDays(2)])->save();
        $case->forceFill(['received_at' => now()->subDays(2)])->save();
        $this->selectBranch($ca);
        $directory = app(VisitDirectoryService::class);
        // The board filters by the branch-local date, so the filter must use it too (not the UTC date).
        $today = now()->setTimezone($this->branch->timezone)->toDateString();
        $criteria = ['date_from' => $today, 'date_to' => $today];
        $initial = $directory->search($ca, $criteria);
        foreach (['all', 'waiting', 'serving', 'dispensary', 'billing', 'completed', 'cancelled'] as $tab) {
            $this->assertGreaterThan(0, $initial['tabCounts'][$tab], $tab);
        }
        $filterSets = [[], ['priority' => 'urgent'], ['visit_type' => 'consultation'], ['coverage_type' => 'self_pay'], ['doctor_id' => $doctor->id], ['patient_query' => $waiting->patient->patient_number], ['patient_query' => 'Synthetic Visit Patient'], ['status' => 'registered']];
        foreach ($filterSets as $filters) {
            $counts = $directory->search($ca, [...$criteria, ...$filters])['tabCounts'];
            foreach (array_keys($counts) as $tab) {
                $result = $directory->search($ca, [...$criteria, ...$filters, 'board_status' => $tab]);
                $this->assertSame($counts[$tab], $result['total'], json_encode([$filters, $tab]));
                $this->assertSame($counts, $result['tabCounts']);
            }
        }
        // The authorised exception preserves the old list, including old normal OTC cases.
        $filters = [...$criteria, 'priority' => 'urgent', 'visit_type' => 'consultation', 'coverage_type' => 'panel'];
        $counts = $directory->search($ca, $filters)['tabCounts'];
        $this->assertSame(0, $counts['all']);
        $this->assertSame(1, $counts['dispensary']);
        $this->assertSame(1, app(DispensaryDirectoryService::class)->board($ca, $filters)['total']);
        $case->forceFill(['status' => DispensaryCase::STATUS_RETURNED])->save();
        $this->assertSame(0, $directory->search($ca, $filters)['tabCounts']['dispensary']);
    }

    public function test_registration_counts_are_additive_integers_and_omit_unauthorised_counts(): void
    {
        $ca = $this->actor('ca');
        $this->send($ca, $this->consultationVisit($ca, $this->doctor()));
        $this->selectBranch($ca);
        $response = $this->postJson(route('registration.search'))->assertOk();
        $this->assertSame(['data', 'tabCounts', 'total', 'currentPage', 'lastPage'], array_keys($response->json()));
        $this->assertSame(['all' => 1, 'waiting' => 1, 'serving' => 0, 'completed' => 0, 'cancelled' => 0, 'billing' => 0, 'dispensary' => 0], $response->json('tabCounts'));
        foreach ($response->json('tabCounts') as $count) {
            $this->assertIsInt($count);
        }
        $ca->revokePermissionTo('billing.view.branch');
        // Revoke the role grant too: the test must exercise effective authority.
        $ca->roles->each(fn ($role) => $role->revokePermissionTo('billing.view.branch'));
        $ca->unsetRelation('roles')->unsetRelation('permissions');
        $this->postJson(route('registration.search'))->assertOk()->assertJsonMissingPath('tabCounts.billing');
        $this->postJson(route('registration.search'), ['board_status' => 'billing'])->assertNotFound();
        $this->grantPermissions($ca, 'visits.view.branch');
        $ca->removeRole('ca');
        $this->postJson(route('registration.search'))->assertOk()->assertJsonMissingPath('tabCounts.dispensary');
    }

    public function test_counts_change_with_status_and_are_isolated_to_branch_and_organisation(): void
    {
        $ca = $this->actor('ca');
        $doctor = $this->doctor();
        $queue = $this->send($ca, $this->consultationVisit($ca, $doctor));
        $foreignBranch = $this->organisation->branches()->whereKeyNot($this->branch->id)->orderBy('id')->firstOrFail();
        $foreignVisit = $queue->visit->replicate();
        $foreignVisit->forceFill(['branch_id' => $foreignBranch->id, 'visit_number' => 'TAB-FOREIGN', 'idempotency_key' => (string) Str::uuid()])->save();
        $foreignQueue = $queue->replicate();
        $foreignQueue->forceFill(['visit_id' => $foreignVisit->id, 'branch_id' => $foreignBranch->id])->save();
        $foreignOrganisation = new Organisation;
        $foreignOrganisation->forceFill(['code' => 'TAB_FOREIGN', 'name' => 'Synthetic foreign organisation'])->save();
        $tenantBranch = new Branch;
        $tenantBranch->forceFill(['organisation_id' => $foreignOrganisation->id, 'code' => 'SYN', 'name' => 'Synthetic foreign branch', 'timezone' => 'Asia/Kuala_Lumpur'])->save();
        $tenantActor = User::factory()->create(['organisation_id' => $foreignOrganisation->id, 'name' => 'Synthetic foreign doctor', 'email' => 'foreign@tab-counts.test']);
        $foreignPatient = $queue->visit->patient->replicate();
        $foreignPatient->forceFill(['organisation_id' => $foreignOrganisation->id, 'patient_number' => 'KP-99999998', 'full_name' => 'Synthetic foreign patient', 'search_name' => 'synthetic foreign patient'])->save();
        $tenantVisit = $queue->visit->replicate();
        $tenantVisit->forceFill(['organisation_id' => $foreignOrganisation->id, 'branch_id' => $tenantBranch->id, 'patient_id' => $foreignPatient->id, 'assigned_doctor_user_id' => $tenantActor->id, 'registered_by_user_id' => $tenantActor->id, 'updated_by_user_id' => $tenantActor->id, 'visit_number' => 'TAB-TENANT', 'idempotency_key' => (string) Str::uuid()])->save();
        $tenantQueue = $queue->replicate();
        $tenantQueue->forceFill(['organisation_id' => $foreignOrganisation->id, 'branch_id' => $tenantBranch->id, 'visit_id' => $tenantVisit->id, 'queued_by_user_id' => $tenantActor->id, 'updated_by_user_id' => $tenantActor->id])->save();
        $this->selectBranch($ca);
        $directory = app(VisitDirectoryService::class);
        $this->assertSame(1, $directory->search($ca, [])['tabCounts']['waiting']);
        $this->assertSame(1, app(QueueDirectoryService::class)->poll($ca)['tabCounts']['waiting']);
        $queue->forceFill(['status' => 'serving', 'called_at' => now(), 'called_by_user_id' => $doctor->id])->save();
        $this->assertSame(0, $directory->search($ca, [])['tabCounts']['waiting']);
        $this->assertSame(1, $directory->search($ca, [])['tabCounts']['serving']);
        $this->assertSame(['all' => 1, 'waiting' => 0, 'serving' => 1, 'removed' => 0], app(QueueDirectoryService::class)->poll($ca)['tabCounts']);
    }

    public function test_queue_counts_cover_carry_over_and_capped_sections_under_every_tab_and_own_scope(): void
    {
        Date::setTestNow('2026-11-04 02:00:00 UTC');
        try {
            $ca = $this->actor('ca');
            $doctor = $this->doctor();
            $other = $this->doctor();
            $queue = $this->send($ca, $this->consultationVisit($ca, $doctor));
            $queue->forceFill(['operational_date' => '2026-11-03'])->save();
            $this->send($ca, $this->consultationVisit($ca, $doctor));
            $this->send($ca, $this->consultationVisit($ca, $other));
            foreach (['serving', 'removed'] as $status) {
                $template = $this->send($ca, $this->consultationVisit($ca, $doctor));
                $template->forceFill(['status' => $status, 'called_at' => now(), 'called_by_user_id' => $doctor->id, ...($status === 'removed' ? ['removed_at' => now(), 'removal_reason' => 'consultation_completed'] : [])])->save();
                for ($index = 0; $index < 26; $index++) {
                    $visit = $template->visit->replicate();
                    $visit->forceFill(['visit_number' => 'TAB-'.Str::random(12), 'idempotency_key' => (string) Str::uuid()])->save();
                    $copy = $template->replicate();
                    $copy->forceFill(['visit_id' => $visit->id, 'queue_number' => 1000 + $index + ($status === 'removed' ? 100 : 0)])->save();
                }
            }
            foreach (['', 'waiting', 'serving', 'removed'] as $status) {
                $snapshot = app(QueueDirectoryService::class)->poll($ca, ['status' => $status]);
                $this->assertSame(['all' => 30, 'waiting' => 3, 'serving' => 27, 'removed' => 27], $snapshot['tabCounts']);
                if ($status === 'waiting') {
                    $this->assertSame(3, $snapshot['waiting']['total'] + $snapshot['carryOver']['total']);
                } elseif ($status !== '') {
                    $this->assertCount(25, $snapshot[$status]);
                }
            }
            $directory = app(QueueDirectoryService::class);
            $this->assertSame(2, $directory->poll($ca, ['doctor_id' => $doctor->id])['tabCounts']['waiting']);
            $this->assertSame(0, $directory->poll($ca, ['priority' => 'urgent'])['tabCounts']['all']);
            $this->assertSame(2, $directory->poll($ca, ['query' => 'Synthetic Visit Patient', 'doctor_id' => $doctor->id])['tabCounts']['waiting']);
            $this->selectBranch($doctor);
            $this->assertSame(2, $directory->poll($doctor, ['doctor_id' => $other->id])['tabCounts']['waiting']);
            $this->assertSame($directory->snapshot($doctor)['tabCounts'], $directory->poll($doctor)['tabCounts']);
        } finally {
            Date::setTestNow();
        }
    }

    public function test_four_hundred_list_entries_keep_bounded_query_cost_and_do_not_change_source_rows(): void
    {
        $ca = $this->actor('ca');
        $queue = $this->send($ca, $this->consultationVisit($ca, $this->doctor()));
        $measure = function () use ($ca): array {
            $counts = [];
            foreach (['registration' => fn () => app(VisitDirectoryService::class)->search($ca, []), 'queue' => fn () => app(QueueDirectoryService::class)->snapshot($ca)] as $name => $read) {
                $read(); // Warm request-local authority caches equally at both fixture sizes.
                DB::enableQueryLog();
                DB::flushQueryLog();
                $result = $read();
                $counts[$name] = count(DB::getQueryLog());
                DB::disableQueryLog();
                $this->assertSame($result['tabCounts']['all'], $result['tabCounts']['waiting']);
            }

            return $counts;
        };
        $small = $measure();
        for ($index = 1; $index < 400; $index++) {
            $visit = $queue->visit->replicate();
            $visit->forceFill(['visit_number' => 'TAB-'.Str::random(12), 'idempotency_key' => (string) Str::uuid()])->save();
            $copy = $queue->replicate();
            $copy->forceFill(['visit_id' => $visit->id, 'queue_number' => $index + 1])->save();
        }
        $before = [];
        foreach (['visits', 'queue_entries', 'patients', 'audit_logs'] as $table) {
            $before[$table] = DB::table($table)->orderBy('id')->get()->toJson();
        }
        $large = $measure();
        foreach ($small as $source => $queries) {
            $this->assertLessThanOrEqual($queries, $large[$source], $source);
        }
        $this->assertLessThanOrEqual(18, $large['registration']);
        $this->assertLessThanOrEqual(16, $large['queue']);
        $this->assertSame(400, app(VisitDirectoryService::class)->search($ca, [])['tabCounts']['all']);
        $this->assertSame(400, app(QueueDirectoryService::class)->poll($ca)['tabCounts']['waiting']);
        foreach ($before as $table => $rows) {
            $this->assertSame($rows, DB::table($table)->orderBy('id')->get()->toJson(), $table);
        }
    }
}
