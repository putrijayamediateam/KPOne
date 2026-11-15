<?php

namespace Tests\Feature\Queue;

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Queue\Models\QueueEntry;
use App\Domain\Visit\Services\VisitReasonService;
use App\Domain\Visit\Services\VisitRegistrationService;
use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Support\Facades\Date;
use Illuminate\Validation\ValidationException;

class QueueEntryCreationTest extends QueueTestCase
{
    public function test_consultation_enters_waiting_once_and_retry_returns_same_entry(): void
    {
        $ca = $this->actor('ca');
        $visit = $this->consultationVisit($ca, $this->doctor());

        $first = $this->send($ca, $visit);
        $second = $this->send($ca, $visit);

        $this->assertTrue($first->is($second));
        $this->assertSame(QueueEntry::STATUS_WAITING, $first->status);
        $this->assertSame(1, $first->queue_number);
        $this->assertDatabaseCount('queue_entries', 1);
        $this->assertDatabaseCount('queue_number_counters', 1);
        $this->assertSame(1, AuditLog::query()
            ->where('organisation_id', $this->organisation->id)
            ->where('event', 'queue.entered')
            ->count());
    }

    public function test_otc_cannot_enter_consultation_queue(): void
    {
        $ca = $this->actor('ca');
        $otc = $this->register($ca, $this->patient($ca));

        $this->expectException(ValidationException::class);
        $this->send($ca, $otc);
    }

    public function test_removed_queue_entry_cannot_be_requeued(): void
    {
        $ca = $this->actor('ca');
        $visit = $this->consultationVisit($ca, $this->doctor());
        $entry = $this->send($ca, $visit);
        $entry->forceFill(['status' => QueueEntry::STATUS_REMOVED, 'removed_at' => now()])->save();

        $this->expectException(ValidationException::class);
        $this->send($ca, $visit);
    }

    public function test_queue_number_resets_by_branch_local_day_and_is_independent_per_branch(): void
    {
        try {
            Date::setTestNow('2026-08-27 15:59:00 UTC');
            $ca = $this->actor('director');
            $first = $this->send($ca, $this->consultationVisit($ca, $this->doctor()));
            $otherBranch = $this->organisation->branches()->where('id', '!=', $this->branch->id)->firstOrFail();
            $otherDoctor = $this->doctor($otherBranch);
            $this->selectBranch($ca, $otherBranch);
            $otherVisit = app(VisitRegistrationService::class)->register($ca, $this->visitAttributes($this->patient($ca), [
                'expected_branch_id' => $otherBranch->id,
                'visit_type' => 'consultation',
                'assigned_doctor_user_id' => $otherDoctor->id,
                'visit_reason_public_ids' => [app(VisitReasonService::class)->create($ca, 'Synthetic other branch Queue')->public_id],
            ]));
            $other = $this->send($ca, $otherVisit);

            Date::setTestNow('2026-08-27 16:01:00 UTC');
            $this->selectBranch($ca, $this->branch);
            $nextDay = $this->send($ca, $this->consultationVisit($ca, $this->doctor()));

            $this->assertSame(1, $first->queue_number);
            $this->assertSame(1, $other->queue_number);
            $this->assertSame(1, $nextDay->queue_number);
            $this->assertNotSame($first->operational_date->toDateString(), $nextDay->operational_date->toDateString());
        } finally {
            Date::setTestNow();
        }
    }

    public function test_queue_entry_rejects_mass_assignment(): void
    {
        $entry = new QueueEntry;

        $this->expectException(MassAssignmentException::class);
        $entry->fill(['status' => QueueEntry::STATUS_SERVING, 'queue_number' => 999]);
    }

    public function test_send_request_rejects_system_owned_queue_fields(): void
    {
        $ca = $this->actor('ca');
        $visit = $this->consultationVisit($ca, $this->doctor());
        $this->selectBranch($ca);

        $this->post("/visits/{$visit->visit_number}/queue", [
            'expected_branch_id' => $this->branch->id,
            'visit_lock_version' => $visit->lock_version,
            'organisation_id' => $this->organisation->id,
            'branch_id' => $this->branch->id,
            'visit_id' => $visit->id,
            'queue_number' => 999,
            'status' => QueueEntry::STATUS_SERVING,
        ])->assertSessionHasErrors([
            'organisation_id', 'branch_id', 'visit_id', 'queue_number', 'status',
        ]);

        $this->assertDatabaseCount('queue_entries', 0);
        $this->assertDatabaseCount('queue_number_counters', 0);
    }
}
