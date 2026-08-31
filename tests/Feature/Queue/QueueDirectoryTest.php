<?php

namespace Tests\Feature\Queue;

use App\Domain\Queue\Models\QueueEntry;
use App\Domain\Queue\Services\QueueDirectoryService;
use App\Domain\Visit\Services\VisitAdministrationService;
use App\Domain\Visit\Services\VisitRegistrationService;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;

class QueueDirectoryTest extends QueueTestCase
{
    public function test_waiting_is_urgent_first_then_fifo_and_projection_is_minimized(): void
    {
        $ca = $this->actor('ca');
        $doctor = $this->doctor();
        $normal = $this->send($ca, $this->consultationVisit($ca, $doctor));
        $urgent = $this->send($ca, $this->consultationVisit($ca, $doctor, ['priority' => 'urgent']));
        $normal->forceFill(['queued_at' => now()->subMinutes(10)])->save();
        $urgent->forceFill(['queued_at' => now()->subMinute()])->save();

        $snapshot = app(QueueDirectoryService::class)->snapshot($ca);

        $this->assertSame($urgent->visit->visit_number, $snapshot['waiting']['data'][0]['visitNumber']);
        $row = $snapshot['waiting']['data'][0];
        $this->assertFalse($row['canCall']);
        foreach (['id', 'patientId', 'phone', 'email', 'address', 'memberReference', 'normalizedValue'] as $field) {
            $this->assertArrayNotHasKey($field, $row);
        }
    }

    public function test_previous_day_waiting_is_visible_as_carry_over_and_today_number_remains_independent(): void
    {
        try {
            Date::setTestNow('2026-08-27 15:59:00 UTC');
            $ca = $this->actor('ca');
            $doctor = $this->doctor();
            $previous = $this->send($ca, $this->consultationVisit($ca, $doctor));

            Date::setTestNow('2026-08-27 16:01:00 UTC');
            $today = $this->send($ca, $this->consultationVisit($ca, $doctor));
            $snapshot = app(QueueDirectoryService::class)->snapshot($ca);

            $this->assertSame($previous->visit->visit_number, $snapshot['carryOver']['data'][0]['visitNumber']);
            $this->assertSame($today->visit->visit_number, $snapshot['waiting']['data'][0]['visitNumber']);
            $this->assertSame('001', $snapshot['carryOver']['data'][0]['queueNumber']);
            $this->assertSame('001', $snapshot['waiting']['data'][0]['queueNumber']);
        } finally {
            Date::setTestNow();
        }
    }

    public function test_doctor_scope_is_forced_to_own_assigned_queue(): void
    {
        $ca = $this->actor('ca');
        $doctor = $this->doctor();
        $otherDoctor = $this->doctor();
        $own = $this->send($ca, $this->consultationVisit($ca, $doctor));
        $this->send($ca, $this->consultationVisit($ca, $otherDoctor));
        $this->selectBranch($doctor);

        $snapshot = app(QueueDirectoryService::class)->snapshot($doctor, ['doctor_id' => $otherDoctor->id]);

        $this->assertSame('own', $snapshot['scope']);
        $this->assertCount(1, $snapshot['waiting']['data']);
        $this->assertSame($own->visit->visit_number, $snapshot['waiting']['data'][0]['visitNumber']);
        $this->assertTrue($snapshot['waiting']['data'][0]['canCall']);
        $this->assertSame([], $snapshot['doctors']);
    }

    public function test_branch_snapshot_excludes_another_branch_and_repositions_priority_server_side(): void
    {
        $director = $this->actor('director');
        $doctor = $this->doctor();
        $firstVisit = $this->consultationVisit($director, $doctor);
        $first = $this->send($director, $firstVisit);
        $secondVisit = $this->consultationVisit($director, $doctor);
        $second = $this->send($director, $secondVisit);
        app(VisitAdministrationService::class)->update($secondVisit, [
            'expected_branch_id' => $this->branch->id,
            'lock_version' => $secondVisit->lock_version,
            'queue_lock_version' => $second->lock_version,
            'visit_type' => 'consultation',
            'assigned_doctor_user_id' => $doctor->id,
            'visit_reason' => 'Synthetic urgent Queue update',
            'priority' => 'urgent',
            'coverage_type' => 'self_pay',
        ], $director);
        $otherBranch = $this->organisation->branches()->whereKeyNot($this->branch->id)->firstOrFail();
        $otherDoctor = $this->doctor($otherBranch);
        $this->selectBranch($director, $otherBranch);
        $otherVisit = app(VisitRegistrationService::class)->register(
            $director,
            $this->visitAttributes($this->patient($director), [
                'expected_branch_id' => $otherBranch->id,
                'visit_type' => 'consultation',
                'assigned_doctor_user_id' => $otherDoctor->id,
                'visit_reason' => 'Synthetic isolated branch Queue',
            ]),
        );
        $this->send($director, $otherVisit);
        $this->selectBranch($director, $this->branch);

        $snapshot = app(QueueDirectoryService::class)->snapshot($director);
        $numbers = collect($snapshot['waiting']['data'])->pluck('visitNumber');

        $this->assertSame($secondVisit->visit_number, $snapshot['waiting']['data'][0]['visitNumber']);
        $this->assertSame($firstVisit->visit_number, $snapshot['waiting']['data'][1]['visitNumber']);
        $this->assertContains($first->visit->visit_number, $numbers);
        $this->assertNotContains($otherVisit->visit_number, $numbers);
    }

    public function test_ineligible_doctor_remains_visible_as_an_operational_exception(): void
    {
        $ca = $this->actor('ca_supervisor');
        $doctor = $this->doctor();
        $entry = $this->send($ca, $this->consultationVisit($ca, $doctor));
        $doctor->forceFill(['is_active' => false])->save();

        $snapshot = app(QueueDirectoryService::class)->snapshot($ca);
        $row = collect($snapshot['waiting']['data'])->firstWhere('visitNumber', $entry->visit->visit_number);

        $this->assertNotNull($row);
        $this->assertFalse($row['doctorEligible']);
        $this->assertFalse($row['canCall']);
    }

    public function test_waiting_duration_is_computed_from_queued_timestamp(): void
    {
        $ca = $this->actor('ca');
        $entry = $this->send($ca, $this->consultationVisit($ca, $this->doctor()));
        $entry->forceFill(['queued_at' => now()->subMinutes(65)])->save();

        $snapshot = app(QueueDirectoryService::class)->snapshot($ca);
        $row = collect($snapshot['waiting']['data'])->firstWhere('visitNumber', $entry->visit->visit_number);

        $this->assertSame(65, $row['waitingMinutes']);
    }

    public function test_carry_over_is_bounded_and_independently_paginated(): void
    {
        try {
            Date::setTestNow('2026-08-27 15:00:00 UTC');
            $ca = $this->actor('ca');
            $doctor = $this->doctor();
            foreach (range(1, 26) as $unused) {
                $this->send($ca, $this->consultationVisit($ca, $doctor));
            }

            Date::setTestNow('2026-08-27 16:01:00 UTC');
            $first = app(QueueDirectoryService::class)->snapshot($ca);
            $second = app(QueueDirectoryService::class)->snapshot($ca, ['carry_page' => 2]);

            $this->assertSame(26, $first['carryOver']['total']);
            $this->assertCount(25, $first['carryOver']['data']);
            $this->assertSame(2, $first['carryOver']['lastPage']);
            $this->assertCount(1, $second['carryOver']['data']);
        } finally {
            Date::setTestNow();
        }
    }

    public function test_post_search_is_server_side_and_like_metacharacters_are_literal(): void
    {
        $ca = $this->actor('ca');
        $doctor = $this->doctor();
        $otherDoctor = $this->doctor();
        $normalVisit = $this->consultationVisit($ca, $doctor);
        $urgentVisit = $this->consultationVisit($ca, $doctor, ['priority' => 'urgent']);
        $otherDoctorVisit = $this->consultationVisit($ca, $otherDoctor, ['priority' => 'urgent']);
        $this->send($ca, $normalVisit);
        $this->send($ca, $urgentVisit);
        $this->send($ca, $otherDoctorVisit);
        $this->selectBranch($ca);

        $this->postJson('/queue/search', [
            'query' => 'Synthetic Visit Patient',
            'doctor_id' => $doctor->id,
            'priority' => 'urgent',
            'status' => 'waiting',
            'page' => 1,
            'carry_page' => 1,
        ])
            ->assertOk()
            ->assertJsonCount(1, 'waiting.data')
            ->assertJsonPath('waiting.data.0.visitNumber', $urgentVisit->visit_number);

        $this->postJson('/queue/search', ['query' => '%%%'])
            ->assertOk()
            ->assertJsonCount(0, 'waiting.data');
        $this->get('/queue/search?query=Patient')->assertMethodNotAllowed();
    }

    public function test_polling_requires_queue_authority_and_has_private_headers(): void
    {
        $ca = $this->actor('ca');
        $this->selectBranch($ca);
        $response = $this->postJson('/queue/search')->assertOk();
        $cacheControl = (string) $response->headers->get('Cache-Control');
        $this->assertStringContainsString('private', $cacheControl);
        $this->assertStringContainsString('no-store', $cacheControl);

        $technical = $this->actor('technical_admin');
        $this->selectBranch($technical);
        $this->postJson('/queue/search')->assertForbidden();
    }

    public function test_repeated_queue_page_and_polling_snapshots_are_read_only(): void
    {
        try {
            Date::setTestNow('2026-08-31 01:00:00 UTC');
            $ca = $this->actor('ca');
            $visit = $this->consultationVisit($ca, $this->doctor());
            $entry = $this->send($ca, $visit);
            $queueVersion = $entry->lock_version;
            $queueUpdatedAt = $entry->updated_at;
            $visitVersion = $visit->lock_version;

            Date::setTestNow('2026-08-31 01:10:00 UTC');
            $this->get(route('queue.index'))->assertOk();
            foreach (range(1, 3) as $unused) {
                $this->postJson(route('queue.search'))->assertOk();
            }

            $this->assertSame($queueVersion, $entry->refresh()->lock_version);
            $this->assertTrue($entry->updated_at->equalTo($queueUpdatedAt));
            $this->assertSame($visitVersion, $visit->refresh()->lock_version);
            $this->assertSame(QueueEntry::STATUS_WAITING, $entry->status);
        } finally {
            Date::setTestNow();
        }
    }

    public function test_snapshot_does_not_repeat_branch_authority_query_for_every_row(): void
    {
        $ca = $this->actor('ca_supervisor');
        $doctor = $this->doctor();
        foreach (range(1, 3) as $unused) {
            $this->send($ca, $this->consultationVisit($ca, $doctor));
        }

        DB::enableQueryLog();
        DB::flushQueryLog();
        app(QueueDirectoryService::class)->snapshot($ca);

        $assignmentQueries = collect(DB::getQueryLog())
            ->filter(fn (array $query): bool => str_contains(strtolower($query['query']), 'staff_branch_assignments'));

        $this->assertLessThanOrEqual(2, $assignmentQueries->count());
    }
}
